"""Verifica que los resultados del ESP32 concuerdan con su traza y el receptor MQTT.

Solo valida consistencia y correlacion de los registros CAPTURADOS: no demuestra
que un ensayo se haya realizado fisicamente ni autentica archivos historicos.
"""
import argparse
import csv
import hashlib
import json
import statistics
from collections import defaultdict
from pathlib import Path


def load_csv(path):
    with path.open(newline="", encoding="utf-8") as stream:
        return list(csv.DictReader(stream))


def sha256(path):
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def verify_folder(directory, expected=30):
    paths = {name: directory / name for name in
             ("device_raw.log", "device_events.csv", "device_results.csv",
              "receiver_raw.jsonl", "receiver_events.csv")}
    missing = [n for n, path in paths.items() if not path.is_file()]
    if missing:
        raise FileNotFoundError("Faltan archivos: " + ", ".join(missing))
    events = load_csv(paths["device_events.csv"])
    results = load_csv(paths["device_results.csv"])
    receiver = load_csv(paths["receiver_events.csv"])
    raw = paths["device_raw.log"].read_text(encoding="utf-8", errors="replace")
    # Comprobar que los CSV se derivan literalmente de las trazas originales.
    raw_lines = [l.split("\t", 1)[-1] for l in raw.splitlines()]
    event_lines = [l for l in raw_lines if l.startswith("C_EVENT,")]
    result_lines = [l for l in raw_lines if l.startswith("C_RESULT,")]
    reconstructed_events = ["C_EVENT," + ",".join(row[k] for k in
                            ("run_id", "cycle", "millis", "event", "seq")) for row in events]
    reconstructed_results = ["C_RESULT," + ",".join(row[k] for k in
                              ("run_id", "cycle", "wifi_ms", "post_wifi_ack_ms", "total_ms", "seq", "status"))
                             for row in results]
    if event_lines != reconstructed_events or result_lines != reconstructed_results:
        raise ValueError("Los CSV del dispositivo no coinciden literalmente con device_raw.log")
    with paths["receiver_raw.jsonl"].open(encoding="utf-8") as f:
        receiver_raw = [json.loads(line) for line in f if line.strip()]
    if len(receiver_raw) != len(receiver):
        raise ValueError("receiver_raw.jsonl y receiver_events.csv tienen distinta cantidad de entradas")
    for original, row in zip(receiver_raw, receiver):
        packet = original.get("telemetry", {})
        if (original.get("timestamp_utc") != row["timestamp_utc"] or
                original.get("ack_publish_rc") != int(row["ack_publish_rc"]) or
                packet.get("run_id") != row["run_id"] or
                str(packet.get("cycle")) != row["cycle"] or
                str(packet.get("seq")) != row["seq"] or
                packet.get("node_id") != row["node_id"]):
            raise ValueError("receiver_events.csv no coincide con receiver_raw.jsonl")
    sessions = [l for l in raw_lines if l.startswith("C_SESSION,")]
    session_runs = {l.split(",")[1] for l in sessions if l.startswith("C_SESSION,") and len(l.split(",")) >= 4}
    if len(sessions) != 1:
        raise ValueError("Se esperaba exactamente una cabecera C_SESSION: evita mezclar ejecuciones")
    summary_lines = [l for l in raw_lines if l.startswith("C_SUMMARY,")]
    summary = summary_lines[-1].split(",") if summary_lines else []

    run_ids = {r["run_id"] for r in results} | {r["run_id"] for r in events if r["cycle"] != "0"}
    if len(run_ids) != 1 or len(session_runs) != 1 or run_ids != session_runs:
        raise ValueError("La captura debe pertenecer a un solo run_id y contener C_SESSION")
    run_id = next(iter(run_ids))
    by_event = defaultdict(list)
    by_recv = defaultdict(list)
    by_result = defaultdict(list)
    for row in events:
        by_event[(row["run_id"], row["cycle"], row["event"])].append(row)
    for row in receiver:
        by_recv[(row["run_id"], row["cycle"], row["seq"])].append(row)
    for row in results:
        by_result[(row["run_id"], row["cycle"])].append(row)

    output = []
    for cycle in range(1, expected + 1):
        key = (run_id, str(cycle))
        rr = by_result[key]
        errors = []
        if len(rr) != 1:
            errors.append(f"C_RESULT cantidad={len(rr)} (esperado 1)")
        result = rr[0] if len(rr) == 1 else None
        point = {}
        for event in ("WIFI_DOWN", "WIFI_UP", "PUBLISH_OK", "ACK_OK"):
            hits = by_event[(run_id, str(cycle), event)]
            if len(hits) != 1:
                errors.append(f"{event} cantidad={len(hits)}")
            else:
                try:
                    point[event] = int(hits[0]["millis"])
                    if event != "WIFI_UP" and event != "WIFI_DOWN" and hits[0]["seq"] != str(cycle):
                        errors.append(event + " secuencia distinta")
                except ValueError:
                    errors.append(event + " millis no numerico")
        if by_event[(run_id, str(cycle), "WIFI_DOWN_AGAIN")]:
            errors.append("corte Wi-Fi repetido dentro del ciclo")
        received = by_recv[(run_id, str(cycle), str(cycle))]
        if len(received) != 1:
            errors.append(f"recepciones MQTT={len(received)} (esperado 1)")
        elif received[0]["ack_publish_rc"] != "0":
            errors.append("ACK rechazado por la biblioteca MQTT del receptor")
        if result:
            if result["status"] != "ACK_OK" or result["seq"] != str(cycle):
                errors.append("resultado no exitoso o secuencia no coincide")
        wifi_ms = post_ms = total_ms = ""
        if len(point) == 4:
            down, up, pub, ack = (point[e] for e in ("WIFI_DOWN", "WIFI_UP", "PUBLISH_OK", "ACK_OK"))
            if not (down < up <= pub <= ack):
                errors.append("orden temporal incorrecto")
            else:
                wifi_ms, post_ms, total_ms = up-down, ack-up, ack-down
                if result:
                    if (result["wifi_ms"], result["post_wifi_ack_ms"], result["total_ms"]) != \
                       (str(wifi_ms), str(post_ms), str(total_ms)):
                        errors.append("tiempos C_RESULT no coinciden con eventos")
                    if wifi_ms + post_ms != total_ms:
                        errors.append("suma no corresponde")
        output.append({"run_id": run_id, "cycle": cycle,
                       "wifi_ms": wifi_ms, "post_wifi_ack_ms": post_ms,
                       "total_ms": total_ms, "status": "CROSSCHECK_OK" if not errors else "NO_VALIDADO",
                       "issues": " | ".join(errors)})

    valid = [r for r in output if r["status"] == "CROSSCHECK_OK"]
    summary_ok = (len(summary) == 5 and summary[1:] == [run_id, str(expected), str(expected), "0"])
    full_ok = len(valid) == expected and len(results) == expected and summary_ok
    lines = ["VERIFICACION DOCUMENTAL - EXPERIMENTO C (NUEVA EJECUCION)",
             f"run_id: {run_id}", f"Ciclos exigidos: {expected}",
             f"Ciclos con evidencia correlacionada: {len(valid)}/{expected}",
             f"C_SUMMARY presente y coherente: {'SI' if summary_ok else 'NO'}",
             f"Dictamen documental: {'COMPLETO' if full_ok else 'INCOMPLETO'}",
             "Advertencia: esto no certifica mediciones historicas ni demuestra por si solo su origen fisico.", ""]
    if full_ok:
        totals = [int(r["total_ms"]) for r in valid]
        wifi = [int(r["wifi_ms"]) for r in valid]
        post = [int(r["post_wifi_ack_ms"]) for r in valid]
        lines += [f"Wi-Fi promedio: {statistics.mean(wifi):.2f} ms",
                  f"Posterior hasta ACK promedio: {statistics.mean(post):.2f} ms",
                  f"Total promedio: {statistics.mean(totals):.2f} ms",
                  f"Total mediana: {statistics.median(totals):.2f} ms",
                  f"Total minimo/maximo: {min(totals)} / {max(totals)} ms", ""]
    for r in output:
        if r["issues"]:
            lines.append(f"Ciclo {r['cycle']}: {r['issues']}")
    lines += ["", "SHA-256 de archivos originales al momento de verificar:"]
    for name, path in paths.items():
        lines.append(f"{name}: {sha256(path)}")
    report = directory / "verification_report.txt"
    table = directory / "verified_c_results.csv"
    report.write_text("\n".join(lines) + "\n", encoding="utf-8")
    with table.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(output[0].keys()))
        w.writeheader()
        w.writerows(output)
    return full_ok, "\n".join(lines[:12])


def main():
    parser = argparse.ArgumentParser(description="Cruzar eventos del ESP32, ACK y CSV sin rellenar faltantes")
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--cycles", type=int, default=30)
    args = parser.parse_args()
    try:
        ok, report = verify_folder(args.output_dir, args.cycles)
        print(report)
    except (ValueError, FileNotFoundError) as e:
        parser.error(str(e))
    if not ok:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
