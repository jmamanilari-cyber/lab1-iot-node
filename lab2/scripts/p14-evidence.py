"""P14: marcas manuales, captura existente y SQL de lectura. Nunca controla servicios."""

import argparse
from collections import Counter, defaultdict
from datetime import datetime, timedelta, timezone
import csv
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
import uuid

ROOT = Path(__file__).resolve().parents[1]
BASE = ROOT / "logs" / "evidence" / "P14"
NODES = ("esp32-01", "esp32-02", "esp32-03")
ORDER = ("ready", "interrupt-intent", "down", "restore-intent", "restored")
UNREADABLE_KINDS = frozenset(("serial_corruption", "serial_discarded", "serial_partial", "serial_malformed"))
FRAME_START = re.compile(r"HTTP=|PUBACK broker seq=|MQTT encolado seq=|Muestra NVS seq=|Muestra esp32-01 \|")
HTTP_FRAME = re.compile(r"HTTP=(-?\d+) seq=(\d{1,16}) ack=(\d+)(?: PIR=(?:REAL|SIMULADO))?")
PUBACK_FRAME = re.compile(r"PUBACK broker seq=(\d{1,16})(?:; MariaDB=NO_CONFIRMADA\.)?")
ACTIONS = {"A": "Desactivar/reactivar manualmente el hotspot Android, sin cambiar su configuracion.",
           "B": "Detener/restaurar solo Apache dedicado mediante XAMPP; PHP, MariaDB y D2 siguen activos.",
           "C": "Interrumpir/restaurar solo la instancia D2 verificada; no el servicio Windows Mosquitto."}


class P14Error(ValueError):
    """Mensaje operativo controlado; no texto arbitrario de una dependencia."""


class EvidenceIntegrityError(P14Error):
    """Una fuente ya corrupta no se recupera esperando mas secuencias."""


def utc():
    return datetime.now(timezone.utc).isoformat(timespec="microseconds").replace("+00:00", "Z")


def date(value):
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


def write_json(path, data, exclusive=False):
    text = json.dumps(data, ensure_ascii=True, indent=2) + "\n"
    if exclusive:
        with path.open("x", encoding="utf-8", newline="\n") as file:
            file.write(text)
    else:
        temp = path.with_name(path.name + "." + uuid.uuid4().hex + ".tmp")
        try:
            temp.write_text(text, encoding="utf-8")
            temp.replace(path)
        finally:
            temp.unlink(missing_ok=True)


def load_json(path):
    return json.loads(path.read_text(encoding="utf-8-sig"))


def run_path(value):
    path = Path(value).resolve()
    if path.parent != BASE.resolve() or not (path / "metadata.json").is_file():
        raise P14Error("Use el directorio exacto de una ejecucion P14, dentro de logs/evidence/P14.")
    return path


def sql(php, observed=None, start=None, end=None, timeout=30):
    request = {"observed": observed or {}}
    if start and end:
        request.update(start_utc=start, end_utc=end)
    try:
        result = subprocess.run([php, str(ROOT / "scripts/p14-sql.php")],
                                input=json.dumps(request), text=True, encoding="utf-8",
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=timeout)
        data = json.loads(result.stdout)
        if result.returncode or data.get("status") != "OK":
            raise ValueError()
        return data
    except (OSError, ValueError, subprocess.TimeoutExpired):
        return {"status": "INCOMPLETO", "utc_finished": utc(), "nodes": {},
                "errors": ["Consulta SQL no disponible o incompleta; detalles privados omitidos."]}


def inspect_services():
    shell = Path(os.environ.get("SystemRoot", r"C:\Windows")) / "System32/WindowsPowerShell/v1.0/powershell.exe"
    try:
        result = subprocess.run([str(shell), "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File",
                                 str(ROOT / "scripts/p14-inspect.ps1")],
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                text=True, encoding="utf-8-sig", errors="replace", timeout=20)
        data = json.loads(result.stdout)
        if result.returncode or data.get("status") != "OK":
            raise ValueError()
        return data
    except (OSError, ValueError, subprocess.TimeoutExpired):
        return {"status": "INCOMPLETO", "reason": "No se verificaron procesos/sockets. No interrumpir servicios."}


def service_check(data, scenario, phase):
    if data.get("status") != "OK":
        return False
    ports = {s["port"] for s in data["sockets"]}
    if not {18082, 3306}.issubset(ports):
        return False
    apache = data["apache"]["verified"]
    d2 = data["d2"]["verified"] and not data["d2"]["windows_service_owned"]
    if phase == "down" and scenario == "B":
        return 8443 not in ports and d2
    if phase == "down" and scenario == "C":
        return not ({1884, 8883} & ports) and apache
    return apache and d2


def serial_directory(run):
    candidates = [p for p in (run / "serial").glob("*") if p.is_dir()]
    return candidates[0] if len(candidates) == 1 else None


def read_events(run, start, end):
    """Extraer solo tipos y campos publicos; los originales no se reescriben."""
    folder = serial_directory(run)
    events = {n: [] for n in NODES}
    problems = []
    if folder is None:
        return events, ["No existe una unica carpeta de captura serie."]
    first, last = date(start), date(end)
    for node in NODES:
        seen_messages = set()
        path = folder / (node + ".log")
        if not path.is_file():
            problems.append("Falta log original de " + node)
            continue
        with path.open(encoding="utf-8", errors="replace") as file:
            for number, line in enumerate(file, 1):
                match = re.match(r"^(\d{4}-\d{2}-\d{2}T[0-9:.]+Z) \[(esp32-0[123])\] \[(COM\d+)\] (.*)\n$", line)
                if not match:
                    continue  # Fragmento aun abierto o diagnostico: no fabricar eventos.
                stamp, label, port, message = match.groups()
                if not first <= date(stamp) <= last:
                    continue
                if label != node:
                    problems.append(f"Identidad serie discordante en {node}, linea {number}.")
                    continue
                base = {"utc": date(stamp).isoformat(timespec="microseconds").replace("+00:00", "Z"),
                        "source_utc": stamp, "node_id": node, "port": port, "line": number,
                        "repeated_message": message in seen_messages}
                seen_messages.add(message)  # Anotar, nunca filtrar ni reescribir originales.
                http = re.search(r"\bHTTP=(-?\d+) seq=(\d{1,16}) ack=(\d+)\b", message)
                ack = re.search(r"\bPUBACK broker seq=(\d{1,16})\b", message)
                queued = re.search(r"\bMQTT encolado seq=(\d{1,16})\b", message)
                sample = re.search(r"Muestra esp32-01 \| PIR=([01]) RSSI=(-?\d+) seq=(\d{1,16})", message)
                nvs = re.search(r"Muestra NVS seq=(\d{1,16}) value=([01]) RSSI=(-?\d+)", message)
                if "\ufffd" in message:
                    base.update(kind="serial_corruption", replacement_characters=message.count("\ufffd"))
                elif "[LINEA DESCARTADA:" in message:
                    base.update(kind="serial_discarded")
                elif "[LINEA PARCIAL]" in message:
                    base.update(kind="serial_partial")
                elif len(FRAME_START.findall(message)) > 1:
                    base.update(kind="serial_malformed", reason="concatenated_messages")
                elif (("HTTP=" in message and not HTTP_FRAME.fullmatch(message)) or
                      ("PUBACK broker seq=" in message and not PUBACK_FRAME.fullmatch(message)) or
                      (not http and re.search(r"\bseq=\d+.*\back=", message))):
                    # No aceptar por re.search un acuse pegado a basura o un
                    # fragmento inicial. La linea original permanece intacta.
                    base.update(kind="serial_malformed", reason="incomplete_or_prefixed_confirmation")
                elif http:
                    base.update(kind="http", code=int(http[1]), seq=int(http[2]), ack=int(http[3]))
                elif ack:
                    base.update(kind="puback", seq=int(ack[1]))
                elif queued:
                    base.update(kind="mqtt_enqueue", seq=int(queued[1]))
                elif sample:
                    base.update(kind="sample", seq=int(sample[3]), value=int(sample[1]), rssi=int(sample[2]))
                elif nvs:
                    base.update(kind="sample", seq=int(nvs[1]), value=int(nvs[2]), rssi=int(nvs[3]))
                elif "Reintento MQTT/UDP" in message:
                    base.update(kind="mqtt_retry")
                elif "Reintento en " in message:
                    base.update(kind="http_retry")
                elif "MQTT TLS conectado" in message:
                    base.update(kind="mqtt_connected")
                elif "desconectado | Motivo:" in message or "sin IP (LOST_IP/STA_STOP)" in message:
                    base.update(kind="wifi_down")
                elif "GOT_IP" in message:
                    base.update(kind="wifi_up")
                elif "STOP:" in message:
                    base.update(kind="stop")
                elif re.search(r"^(?:(?:P|E|D)\d+ esp32-0[123]:|rst:0x[0-9a-f]+)", message, re.I):
                    base.update(kind="boot")
                else:
                    continue
                events[node].append(base)
    return events, sorted(set(problems))


def confirmed(event, node):
    return (event["kind"] == "puback" if node == "esp32-03" else
            event["kind"] == "http" and event["code"] in (200, 201) and event["ack"] == 1)


def observed_keys(events):
    return {node: sorted({e["seq"] for e in rows if "seq" in e}) for node, rows in events.items()}


def analysis_start(metadata):
    """Ventana congelada al marcar ready; el inicio original de captura no cambia."""
    return metadata.get("baseline_start_utc") or metadata["start_utc"]


def confirmation_progress(events, node, allowed=None):
    # Una repeticion tardia no convierte un lote inicial en progreso estable.
    first = {}
    for event in events[node]:
        if confirmed(event, node) and (allowed is None or event["seq"] in allowed):
            first.setdefault(event["seq"], event["utc"])
    times = sorted(first.values())
    return first, (date(times[-1]) - date(times[0])).total_seconds() if times else 0


def prepare_baseline(run, metadata, events, stamp):
    """Solo antes de ready: candidato limpio global, sin alterar logs ni start_utc."""
    if metadata.get("marks") or metadata.get("baseline_start_utc"):
        raise EvidenceIntegrityError("La ventana experimental ya esta congelada; no se permite estabilizar de nuevo.")
    settings = metadata["automation"]["serial_preparation"]
    minimum = settings["minimum_progress_seconds"]
    bad = [e for own in events.values() for e in own if e["kind"] in UNREADABLE_KINDS]
    candidate = metadata["start_utc"]
    if bad:
        candidate = (max(date(e["utc"]) for e in bad) + timedelta(microseconds=1)).isoformat(timespec="microseconds").replace("+00:00", "Z")
    preparation = metadata.setdefault("preparation", {"phase": "STABILIZING", "started_utc": metadata["start_utc"], "candidates": []})
    candidate = max(candidate, preparation.get("candidate_start_utc", candidate))
    if candidate != preparation.get("candidate_start_utc"):
        preparation["candidates"].append({"observed_utc": stamp, "start_utc": candidate,
            "cause": "after_last_unreadable_event" if bad else "capture_start"})
    preparation.update(candidate_start_utc=candidate, checked_utc=stamp,
        initial_unreadable_counts={node: sum(e["kind"] in UNREADABLE_KINDS for e in own) for node, own in events.items()},
        initial_repeated_counts={node: sum(e.get("repeated_message", False) and date(e["utc"]) < date(candidate) for e in own) for node, own in events.items()})
    clean = {node: [e for e in own if date(e["utc"]) >= date(candidate)] for node, own in events.items()}
    progress = {}
    stable = True
    for node in NODES:
        first, span = confirmation_progress(clean, node)
        latest = max(first.values()) if first else None
        fresh = latest is not None and 0 <= (date(stamp) - date(latest)).total_seconds() <= settings["maximum_silence_seconds"]
        progress[node] = {"distinct_sequences": sorted(first), "progress_seconds": span, "fresh": fresh}
        stable = stable and len(first) >= 2 and span >= minimum and fresh
    preparation["progress"] = progress
    write_json(run / "metadata.json", metadata)
    if not stable:
        raise P14Error("Estabilizacion en curso: faltan dos secuencias completas distintas y progreso reciente de cada nodo.")
    return clean, candidate


def assess(metadata, events, before, after, capture_status):
    """PASS se limita a recuperacion/SQL de claves observadas; nunca a ausencia global de perdida."""
    marks = {m["event"]: m for m in metadata.get("marks", [])}
    incomplete, failures = [], []
    for error in metadata.get("automation_errors", []):
        (failures if error["status"] == "FAIL" else incomplete).append(error["reason"])
    if any(name not in marks for name in ORDER) or not metadata.get("end_utc"):
        incomplete.append("Faltan marcas obligatorias o cierre de escenario.")
    if metadata.get("aborted"):
        incomplete.append("El operador aborto el ensayo.")
    if capture_status.get("exit_code") != 0 or capture_status.get("forced", True):
        incomplete.append("No se acredita cierre limpio y captura completa de los tres nodos.")
    if before.get("status") != "OK" or after.get("status") != "OK":
        incomplete.append("Faltan snapshots SQL completos.")
    for name in ("ready", "down", "restored"):
        if not marks.get(name, {}).get("services_ok", False):
            incomplete.append("Aislamiento/estado de servicios no verificado en " + name)
    if all(name in marks for name in ORDER):
        timestamps = [marks[n]["utc"] for n in ORDER]
        if timestamps != sorted(timestamps) or len(set(timestamps)) != len(timestamps):
            failures.append("Marcas manuales fuera de orden.")
    start = analysis_start(metadata)
    # Defensa adicional: aun si un llamador aporta toda la captura, solo la
    # preparacion anterior a la ventana congelada se informa por separado.
    events = {node: [e for e in own if date(e["utc"]) >= date(start)] for node, own in events.items()}
    if metadata.get("automation", {}).get("serial_preparation"):
        ready_mark = marks.get("ready", {})
        if (not metadata.get("baseline_start_utc") or not ready_mark or
                not date(metadata["start_utc"]) <= date(start) <= date(ready_mark["utc"])):
            incomplete.append("Falta una ventana basal estabilizada y congelada antes de ready.")
    end = metadata.get("end_utc") or start
    ready = marks.get("ready", {}).get("utc", start)
    intent = marks.get("interrupt-intent", {}).get("utc", ready)
    down = marks.get("down", {}).get("utc", intent)
    restore_intent = marks.get("restore-intent", {}).get("utc", down)
    restored = marks.get("restored", {}).get("utc", restore_intent)
    results = {}
    for node in NODES:
        node_failure_start = len(failures)
        node_incomplete_start = len(incomplete)
        rows = after.get("nodes", {}).get(node, {}).get("rows", [])
        sql_available = (after.get("status") == "OK" and node in after.get("nodes", {})
                         and not after["nodes"][node].get("truncated", False))
        old = {r["seq"]: r for r in before.get("nodes", {}).get(node, {}).get("rows", [])}
        grouped = defaultdict(list)
        for row in rows:
            grouped[row["seq"]].append(row)
            if row.get("fixture_marker") is None and (row["value"] not in (0, 1) or row["rssi"] is None or not -127 <= row["rssi"] <= 0):
                failures.append(node + ": valor PIR/RSSI SQL invalido o incompleto.")
        own = events[node]
        keys = sorted({e["seq"] for e in own if "seq" in e})
        span = keys[-1] - keys[0] + 1 if keys else 0
        range_keys = list(range(keys[0], keys[-1] + 1)) if keys and span <= 2001 else keys
        if span > 2001:
            incomplete.append(node + ": rango amplio; no se enumeran huecos no acotados.")
        acks = [e for e in own if confirmed(e, node)]
        ack_keys = {e["seq"] for e in acks}
        baseline = {e["seq"] for e in acks if e["utc"] <= ready}
        recovered = {e["seq"] for e in acks if e["utc"] >= restore_intent}
        sql_ok = {seq for seq, matches in grouped.items() if len(matches) == 1 and matches[0]["node_id"] == node
                  and matches[0]["device_id"] == after.get("nodes", {}).get(node, {}).get("identity", {}).get("id")
                  and matches[0].get("fixture_marker") is None}
        missing = sorted(ack_keys - sql_ok) if sql_available else []
        duplicates = {str(k): len(v) for k, v in grouped.items() if len(v) > 1}
        gaps = sorted(set(range_keys) - set(grouped)) if sql_available else []
        inflight = {e["seq"] for e in own if "seq" in e and intent <= e["utc"] < restore_intent
                    and (e["kind"] in ("sample", "mqtt_enqueue") or
                         e["kind"] == "http" and (e["code"] not in (200, 201) or e["ack"] != 1))}
        # Una muestra con acuse anterior a la interrupcion ya no es un pendiente.
        before_restore_acked = {e["seq"] for e in acks if e["utc"] < restore_intent}
        pending = inflight - before_restore_acked
        pending_recovered = pending & recovered & sql_ok
        watermark = before.get("nodes", {}).get(node, {}).get("totals", {}).get("max_id")
        new_recovered = {seq for seq in recovered & sql_ok
                         if grouped[seq][0]["received_at"].replace(" ", "T") + "Z" >= restore_intent
                         and (watermark is None or grouped[seq][0]["id"] > int(watermark))}
        down_observed = any(intent <= e["utc"] <= restored and
                            (e["kind"] == "wifi_down" if metadata["scenario"] == "A" else
                             e["kind"] == "mqtt_retry" if node == "esp32-03" else
                             e["kind"] == "http" and (e["code"] not in (200, 201) or e["ack"] != 1)) for e in own)
        wifi_up = any(e["kind"] == "wifi_up" and e["utc"] >= restore_intent for e in own)
        mqtt_up = any(e["kind"] == "mqtt_connected" and e["utc"] >= restore_intent for e in own)
        during = {e["seq"] for e in acks if down <= e["utc"] <= restore_intent and e["seq"] in sql_ok
                  and down <= grouped[e["seq"]][0]["received_at"].replace(" ", "T") + "Z" <= restore_intent}
        affected = metadata["scenario"] == "A" or (metadata["scenario"] == "B" and node != "esp32-03") or (metadata["scenario"] == "C" and node == "esp32-03")
        if metadata.get("automation") and affected and any(down < e["utc"] < restore_intent for e in acks):
            failures.append(node + ": acuse exitoso durante la interrupcion automatica; aislamiento no acreditado durante todo el intervalo.")
        if metadata.get("automation") and any(e["kind"] == "wifi_down" and intent <= e["utc"] <= restored for e in own):
            incomplete.append(node + ": tambien hubo caida Wi-Fi; no se acredita aislamiento exclusivo de HTTPS/MQTT.")
        reasons = []
        if not sql_available:
            reasons.append("SQL no disponible/completo; no se infiere ausencia de filas.")
        if len(baseline & sql_ok) < 2:
            reasons.append("No hay dos claves basales confirmadas y persistidas.")
        if len(recovered & sql_ok) < 2:
            reasons.append("No hay dos claves de recuperacion confirmadas y persistidas.")
        if not new_recovered:
            reasons.append("No se acredita progreso SQL nuevo tras restaurar; reenviar claves antiguas no basta.")
        if affected and not down_observed:
            reasons.append("No se observo el fallo de conectividad/transporte esperado.")
        if metadata["scenario"] == "A" and not wifi_up:
            reasons.append("No se observo GOT_IP tras restaurar la red.")
        if metadata["scenario"] in ("A", "C") and node == "esp32-03" and not mqtt_up:
            reasons.append("No se observo nueva conexion MQTT TLS.")
        if not affected and len(during) < 2:
            reasons.append("No hay dos claves SQL/serie que acrediten continuidad del transporte no afectado durante la caida.")
        if affected and metadata["scenario"] in ("B", "C") and not pending:
            reasons.append("No se identifico una secuencia pendiente durante la interrupcion.")
        if any(e["kind"] == "boot" and e["utc"] >= intent for e in own):
            reasons.append("Se observo arranque tras interrumpir; no se acredita recuperacion automatica sin RESET.")
        for e in own:
            if e["kind"] == "sample" and e["seq"] in sql_ok:
                actual = grouped[e["seq"]][0]
                if actual["value"] != e["value"] or actual["rssi"] != e["rssi"]:
                    failures.append(node + ": PIR/RSSI SQL diferentes de una muestra serie explicita.")
            if e["kind"] == "stop":
                failures.append(node + ": parada STOP observada; no afirmar recuperacion automatica completa.")
        for seq, row in old.items():
            if seq in grouped and len(grouped[seq]) == 1:
                if any(row[k] != grouped[seq][0][k] for k in ("id", "device_id", "node_id", "seq", "ts", "value", "rssi", "received_at")):
                    failures.append(node + ": una fila previa cambio para la misma clave.")
        if missing or duplicates:
            failures.append(node + ": faltan claves confirmadas en SQL o hay duplicados al corte consultado.")
        if sql_available and pending - pending_recovered:
            skipped = [seq for seq in pending - sql_ok if any(later > seq for later in recovered)]
            if skipped:
                failures.append(node + ": avance posterior con secuencia pendiente ausente en SQL.")
            else:
                reasons.append("Hay pendientes sin acuse y persistencia de recuperacion comprobados al corte; falta observacion.")
        if gaps:
            incomplete.append(node + ": huecos SQL en el rango observado; no se puede afirmar ausencia de perdidas.")
        serial_gaps = sorted(set(range_keys) - set(keys))
        # El rango serie no cubre una ultima linea ilegible: contrastar tambien
        # todas las filas fisicas de la ventana, sin inventar sus acuses.
        sql_without_serial = sorted({r["seq"] for r in rows if r.get("fixture_marker") is None
            and r["node_id"] == node and r["device_id"] == after.get("nodes", {}).get(node, {}).get("identity", {}).get("id")
            and start <= r["received_at"].replace(" ", "T") + "Z" <= end} - set(keys)) if sql_available else []
        unreadable = [e for e in own if e["kind"] in UNREADABLE_KINDS]
        if serial_gaps:
            incomplete.append(node + ": hay secuencias intermedias sin eventos serie; SQL se informa por separado.")
        if sql_without_serial:
            incomplete.append(node + ": filas SQL de la ventana sin evento serie legible para seq=" + ",".join(map(str, sql_without_serial)) + ". No se infiere un acuse ni perdida SQL.")
        if unreadable:
            incomplete.append(node + ": fuente serie ilegible/parcial en lineas " + ",".join(str(e["line"]) for e in unreadable) + "; no reconstruir contenido desde SQL.")
        if after.get("nodes", {}).get(node, {}).get("truncated"):
            incomplete.append(node + ": consulta SQL truncada.")
        incomplete.extend(node + ": " + reason for reason in reasons)
        counts = Counter(e["seq"] for e in acks)
        node_status = "FAIL" if len(failures) > node_failure_start else (
            "INCOMPLETO" if len(incomplete) > node_incomplete_start else "PASS")
        results[node] = {"status": node_status, "sql_available": sql_available, "sql_totals_before": before.get("nodes", {}).get(node, {}).get("totals"),
            "sql_totals_after": after.get("nodes", {}).get(node, {}).get("totals"),
            "new_recovered_keys": sorted(new_recovered), "observed_sequences": keys, "serial_range_gaps": serial_gaps,
            "sql_without_serial": sql_without_serial, "serial_unreadable_lines": unreadable,
            "sql_range_gaps": gaps, "sql_duplicates": duplicates,
            "confirmed_missing_sql": missing, "baseline_keys": sorted(baseline & sql_ok),
            "recovered_keys": sorted(recovered & sql_ok), "unaffected_keys_during_down": sorted(during),
            "pending_keys": sorted(pending), "pending_recovered": sorted(pending_recovered),
            "repeated_serial_ack_occurrences": sum(c - 1 for c in counts.values()),
            "failure_observed": down_observed, "wifi_up_observed": wifi_up, "mqtt_up_observed": mqtt_up,
            "recovery_observed": len(recovered & sql_ok) >= 2, "incomplete_reasons": reasons,
            "sequences": [{"node_id": node, "seq": seq, "serial_events": [e for e in own if e.get("seq") == seq],
                           "sql_rows": grouped.get(seq, []), "confirmed": seq in ack_keys,
                           "pending": seq in pending, "sql_unique_correct_identity": seq in sql_ok}
                          for seq in sorted(set(range_keys) | set(keys))]}
    status = "FAIL" if failures else ("INCOMPLETO" if incomplete else "PASS")
    return {"status": status, "scope": "Recuperacion observable y persistencia de claves identificadas; no garantia global de entrega.",
            "nodes": results, "failures": sorted(set(failures)), "incomplete": sorted(set(incomplete)),
            "limits": ["PUBACK confirma recepcion por el broker; SQL se comprueba independientemente.",
                       "No se prueba ausencia global de perdidas, ni se inspecciona o modifica NVS.",
                       "Se comparan seq y campos PIR/RSSI expuestos; sin preimagen completa no se demuestra igualdad de todo el JSON pendiente.",
                       "Las marcas UTC acotan acciones y comprobaciones; no son instantes exactos de desconexion ni commit.",
                       "Filas ausentes lo estan al corte SQL indicado; esto no prueba una perdida irreversible."]}


def report_files(folder, metadata, result, sources, sql_after):
    report = {"scenario": "P14-" + metadata["scenario"], "generated_utc": utc(), "metadata": metadata,
              "result": result, "sources": sources, "sql_after_file": str(sql_after)}
    write_json(folder / "evidence.json", report, exclusive=True)
    with (folder / "sequences.csv").open("x", encoding="utf-8", newline="") as file:
        writer = csv.writer(file)
        writer.writerow(["node_id", "seq", "serial_events", "sql_rows", "confirmed", "pending", "sql_unique_correct_identity"])
        for node in NODES:
            for row in result["nodes"][node]["sequences"]:
                writer.writerow([node, row["seq"], len(row["serial_events"]), len(row["sql_rows"]), row["confirmed"], row["pending"], row["sql_unique_correct_identity"]])
    lines = [f"# P14-{metadata['scenario']} — {result['status']}", "", f"UTC de analisis: {report['generated_utc']}", "",
             result["scope"], "", str(metadata.get("automation", {}).get("description", "Ensayo manual")),
             "", "## Marcas UTC", "", "| Marca | UTC laptop | Servicios comprobados |", "|---|---|---|"]
    lines += [f"| {m['event']} | {m['utc']} | {m.get('services_ok', 'no aplica')} |" for m in metadata["marks"]]
    if metadata.get("automation", {}).get("serial_preparation"):
        preparation = metadata.get("preparation", {})
        lines += ["", "## Preparacion y limites UTC", "",
                  "Inicio de captura original: " + metadata["start_utc"],
                  "Inicio basal congelado: " + str(metadata.get("baseline_start_utc", "NO ESTABLECIDO")),
                  "Fin de estabilizacion: " + str(preparation.get("completed_utc", "NO COMPLETADA")),
                  "Lineas iniciales ilegibles por nodo: " + json.dumps(preparation.get("initial_unreadable_counts", {}), sort_keys=True),
                  "Los originales incluyen toda la preparacion; las exclusiones previas y candidatos UTC constan en metadata.json. No se vuelve a mover la ventana despues de ready."]
    lines += ["", "## Resultados", "", "| Nodo | Basales SQL/serie | Recuperadas SQL/serie | Continuidad ajena | Pendientes recuperadas | Acuses sin SQL | Huecos SQL |", "|---|---:|---:|---:|---:|---:|---:|"]
    for node, r in result["nodes"].items():
        missing = len(r['confirmed_missing_sql']) if r['sql_available'] else 'ND'
        gaps = len(r['sql_range_gaps']) if r['sql_available'] else 'ND'
        lines.append(f"| {node} | {len(r['baseline_keys'])} | {len(r['recovered_keys'])} | {len(r['unaffected_keys_during_down'])} | {len(r['pending_recovered'])}/{len(r['pending_keys'])} | {missing} | {gaps} |")
    lines += ["", "## Integridad serie y correlacion SQL", "",
              "| Nodo | Estado del nodo | Lineas ilegibles/parciales | Claves SQL sin evento serie |", "|---|---|---|---|"]
    for node, r in result["nodes"].items():
        lines.append(f"| {node} | {r['status']} | {','.join(str(e['line']) for e in r['serial_unreadable_lines']) or '-'} | {','.join(map(str, r['sql_without_serial'])) or '-'} |")
    lines += ["", "## Fallos y evidencia incompleta", ""] + ["- " + s for s in result["failures"] + result["incomplete"]]
    lines += ["", "## Alcance", ""] + ["- " + s for s in result["limits"]]
    lines += ["", "Los logs originales se conservan sin reescritura en serial/. Las filas y consultas estan en los snapshots SQL; evidence.json conserva cada evento y su linea fuente. Una repeticion serie no es un duplicado SQL.",
              "", ("Acciones automaticas y restauracion: resources.json y actions.json. No se modifica firmware, NVS ni credenciales."
                     if metadata.get("automation") else "No se ejecutaron acciones automaticas sobre hotspot, Apache, D2, NVS ni credenciales.")]
    with (folder / "summary.md").open("x", encoding="utf-8", newline="\n") as file:
        file.write("\n".join(lines) + "\n")


def worker(run):
    meta = load_json(run / "metadata.json")
    command = [sys.executable, str(ROOT / "scripts/capture-serial.py"), "--confirm-locations", "--no-console-lines", "--diagnostics",
               "--output-dir", str(run / "serial"), "--stop-file", str(run / "capture.stop"), "--duration", str(meta["capture_max_seconds"])]
    env = dict(os.environ, PYTHONIOENCODING="utf-8")
    status = {"started_utc": utc(), "exit_code": None, "forced": False}
    try:
        with (run / "capture-console.log").open("xb") as file:
            child = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=file, stderr=file, env=env)
            status["pid"] = child.pid
            try:
                status["exit_code"] = child.wait(timeout=meta["capture_max_seconds"] + 20)
            except subprocess.TimeoutExpired:
                (run / "capture.stop").touch(exist_ok=True)
                try:
                    status["exit_code"] = child.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    # Unicamente el hijo creado y retenido por Popen, nunca por nombre/PID ajeno.
                    child.terminate()
                    status["forced"] = True
                    status["exit_code"] = child.wait(timeout=10)
    except (OSError, subprocess.TimeoutExpired):
        status["forced"] = True
    finally:
        status["finished_utc"] = utc()
        write_json(run / "capture-status.json", status, exclusive=True)
    return 0


def start(args, automatic=None):
    if not args.confirm_locations:
        raise P14Error("Valide asociaciones con capture-serial.py y confirme las tomas mediante --confirm-locations.")
    services = inspect_services()
    if not service_check(services, args.scenario, "ready"):
        raise P14Error("Preflight incompleto: no iniciar el ensayo ni interrumpir servicios. Ejecute inspect.")
    if args.scenario == "B" and not args.dedicated_apache and automatic is None:
        raise P14Error("Apache puede compartir puertos/vhosts. Solo iniciar B con instancia dedicada y --dedicated-apache.")
    before = sql(args.php)
    if before.get("status") != "OK":
        raise P14Error("MariaDB no se pudo consultar; no iniciar la prueba.")
    run = BASE / (datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ") + "-" + args.scenario + "-" + uuid.uuid4().hex[:12])
    run.mkdir(parents=True, exist_ok=False)
    meta = {"format_version": 1, "scenario": args.scenario, "start_utc": utc(), "end_utc": None,
            "status": "INCOMPLETO", "state": "BASELINE", "marks": [], "php": str(Path(args.php).resolve()),
            "capture_max_seconds": args.max_seconds, "confirmed_saved_usb_locations": True,
            "dedicated_apache_operator_confirmation": args.dedicated_apache, "aborted": False,
            "actions": ACTIONS[args.scenario], "sequence_policy": "Usar seq observadas; nunca MAX(seq) historico (hay fixtures altos)."}
    if automatic is not None:
        meta["automation"] = automatic
        meta["actions"] = automatic["description"]
        if automatic.get("serial_preparation"):
            meta["state"] = "STABILIZING"
    write_json(run / "metadata.json", meta, exclusive=True)
    write_json(run / "sql-before.json", before, exclusive=True)
    write_json(run / "services-before.json", services, exclusive=True)
    flags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
    try:
        process = subprocess.Popen([sys.executable, str(Path(__file__).resolve()), "_capture", "--run", str(run)],
                                   stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                   creationflags=flags, env=dict(os.environ, PYTHONIOENCODING="utf-8"))
        meta["capture_worker_pid"] = process.pid
        write_json(run / "metadata.json", meta)
    except BaseException:
        (run / "capture.stop").touch(exist_ok=True)
        raise
    print(f"Directorio: {run}\nCaptura opcional iniciada por el operador; no se han interrumpido servicios.")
    if automatic is None:
        print("Espere al menos dos secuencias confirmadas por nodo (30-45 s) y ejecute mark --event ready.")
    return run if automatic is not None else 0


def mark(run, event, confirm_action, verified_services=None, deadline=None):
    meta = load_json(run / "metadata.json")
    index = len(meta["marks"])
    if meta.get("end_utc") or index >= len(ORDER) or event != ORDER[index]:
        raise P14Error("Marca fuera de orden o escenario cerrado; no se modifica el historial.")
    if event in ("down", "restored") and not confirm_action:
        raise P14Error("Esta marca declara una accion FISICA terminada; requiere --confirm-action.")
    stamp = utc()
    record = {"event": event, "utc": stamp, "source": "automatic" if meta.get("automation") else "manual",
              "operator_action_only": not meta.get("automation") and event != "ready"}
    if event in ("ready", "down", "restored"):
        services = verified_services if verified_services is not None else inspect_services()
        record["services_ok"] = (services.get("automatic_verified") is True if verified_services is not None
                                 else service_check(services, meta["scenario"], event))
        write_json(run / ("services-" + event + "-" + uuid.uuid4().hex[:8] + ".json"), services, exclusive=True)
        if event == "ready":
            events, problems = read_events(run, meta["start_utc"], stamp)
            window_start = meta["start_utc"]
            preparing = bool(meta.get("automation", {}).get("serial_preparation"))
            if preparing:
                if problems:
                    raise P14Error("Estabilizacion pendiente: fuentes serie o identidades no verificadas.")
                events, window_start = prepare_baseline(run, meta, events, stamp)
            if any(e["kind"] in UNREADABLE_KINDS for own in events.values() for e in own):
                raise EvidenceIntegrityError("Baseline con fuente serie corrupta/parcial. No interrumpir servicios; diagnosticar USB sin repetir P14.")
            snapshot = sql(meta["php"], observed_keys(events), window_start, stamp,
                           timeout=min(30, max(0.1, deadline - time.monotonic())) if deadline is not None else 30)
            write_json(run / ("sql-ready-attempt-" + uuid.uuid4().hex[:8] + ".json"), snapshot, exclusive=True)
            if problems or snapshot.get("status") != "OK" or not record["services_ok"]:
                raise P14Error("Baseline incompleta; no realizar la interrupcion. Revise captura/SQL/servicios.")
            stabilization = {}
            for node in NODES:
                if snapshot["nodes"][node].get("truncated"):
                    raise P14Error("Snapshot basal truncado; no iniciar la interrupcion.")
                keys = {e["seq"] for e in events[node] if confirmed(e, node)}
                counts = Counter(r["seq"] for r in snapshot["nodes"][node]["rows"])
                stored = {r["seq"] for r in snapshot["nodes"][node]["rows"] if not r["fixture_marker"] and counts[r["seq"]] == 1
                          and r["node_id"] == node and r["device_id"] == snapshot["nodes"][node]["identity"]["id"]}
                if len(keys & stored) < 2:
                    raise P14Error("Faltan dos claves basales SQL/serie de " + node + "; espere o revise la captura.")
                if preparing:
                    correlated, span = confirmation_progress(events, node, keys & stored)
                    settings = meta["automation"]["serial_preparation"]
                    if (span < settings["minimum_progress_seconds"] or
                            (date(stamp) - date(max(correlated.values()))).total_seconds() > settings["maximum_silence_seconds"]):
                        raise P14Error("Falta progreso SQL/serie estable y reciente de " + node + ".")
                acknowledgements = [e for e in events[node] if confirmed(e, node)]
                stabilization[node] = {"complete_confirmations": len(acknowledgements),
                    "repeated_messages": sum(e.get("repeated_message", False) for e in events[node]),
                    "distinct_confirmed_sequences": len(keys),
                    "repeated_confirmations": len(acknowledgements) - len(keys),
                    "sql_correlated_sequences": sorted(keys & stored),
                    "first_confirmation_utc": acknowledgements[0]["utc"],
                    "last_confirmation_utc": acknowledgements[-1]["utc"]}
            record["serial_stabilization"] = stabilization
            write_json(run / "sql-ready.json", snapshot, exclusive=True)
            if preparing:
                meta["baseline_start_utc"] = window_start
                meta["preparation"].update(phase="READY", completed_utc=stamp,
                                           baseline_start_utc=window_start)
    meta["marks"].append(record)
    meta["state"] = event.upper()
    write_json(run / "metadata.json", meta)
    print(f"Marca {event}: {stamp}.")
    if event == "interrupt-intent" and not meta.get("automation"):
        print(ACTIONS[meta["scenario"]] + " Al terminar la interrupcion, marque down --confirm-action.")
    if event == "restore-intent" and not meta.get("automation"):
        print("Restaure MANUALMENTE el componente; despues marque restored --confirm-action.")
    if record.get("services_ok") is False:
        print("AISLAMIENTO NO VERIFICADO: restaure servicios y termine con --abort; no se acreditara PASS.")
        return 2
    return 0


def finish(run, abort=False, review=False):
    meta = load_json(run / "metadata.json")
    if not review:
        if meta.get("end_utc"):
            raise P14Error("Escenario ya cerrado; use analyze para una revision nueva sin sobrescribir evidencias.")
        meta["end_utc"] = utc(); meta["aborted"] = abort
        write_json(run / "metadata.json", meta)
        (run / "capture.stop").touch(exist_ok=True)
        deadline = time.monotonic() + 15
        while not (run / "capture-status.json").exists() and time.monotonic() < deadline:
            time.sleep(0.1)
    if not meta.get("end_utc"):
        raise P14Error("Falta cierre del escenario; ejecute finish --abort y restaure servicios manualmente.")
    status_file = run / "capture-status.json"
    capture_status = load_json(status_file) if status_file.is_file() else {"exit_code": None, "forced": None, "closure": "UNCONFIRMED"}
    closed = isinstance(capture_status.get("exit_code"), int)
    events, problems = read_events(run, analysis_start(meta), meta["end_utc"])
    snapshot = sql(meta["php"], observed_keys(events), analysis_start(meta), meta["end_utc"])
    folder = run / "analyses" / (datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ") + "-" + uuid.uuid4().hex[:8])
    folder.mkdir(parents=True, exist_ok=False)
    write_json(folder / "sql-after.json", snapshot, exclusive=True)
    baseline = load_json(run / ("sql-ready.json" if (run / "sql-ready.json").is_file() else "sql-before.json"))
    result = assess(meta, events, baseline, snapshot, capture_status)
    result["capture_status"] = capture_status
    if problems:
        identity_conflicts = [s for s in problems if s.startswith("Identidad serie discordante")]
        result["incomplete"].extend(s for s in problems if s not in identity_conflicts)
        result["failures"].extend(identity_conflicts)
        if identity_conflicts:
            result["status"] = "FAIL"
        elif result["status"] == "PASS":
            result["status"] = "INCOMPLETO"
    sources = {}
    source_dir = serial_directory(run)
    if source_dir and closed:
        for path in sorted(source_dir.glob("*.log")):
            sources[path.name] = {"path": str(path), "bytes": path.stat().st_size,
                                  "sha256": hashlib.sha256(path.read_bytes()).hexdigest()}
    original_hashes = run / "serial-hashes.json"
    if not closed:
        result["incomplete"].append("No se sellaron hashes porque la captura podria seguir abierta; espere y ejecute analyze.")
        if result["status"] == "PASS":
            result["status"] = "INCOMPLETO"
    elif original_hashes.is_file():
        if sources != load_json(original_hashes):
            result["status"] = "FAIL"
            result["failures"].append("Los originales serie cambiaron desde su primer cierre.")
    else:
        write_json(original_hashes, sources, exclusive=True)
    report_files(folder, meta, result, sources, folder / "sql-after.json")
    if not review:
        meta["status"] = result["status"]; meta["first_analysis"] = str(folder)
        write_json(run / "metadata.json", meta)
    print(f"P14-{meta['scenario']}: {result['status']}\nAnalisis: {folder}")
    if not meta.get("automation"):
        print("Restaure/verifique MANUALMENTE hotspot, Apache y D2; la herramienta no cambia servicios.")
    return {"PASS": 0, "FAIL": 1, "INCOMPLETO": 2}[result["status"]]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser("inspect", help="Inspeccion de servicios, sin abrir USB.")
    check = commands.add_parser("sql-check", help="Solo comprobar PDO/identidades, sin crear escenario.")
    check.add_argument("--php", default=r"C:\xampp\php\php.exe")
    begin = commands.add_parser("start", help="El operador inicia una NUEVA captura fisica.")
    begin.add_argument("scenario", choices=["A", "B", "C"])
    begin.add_argument("--php", default=r"C:\xampp\php\php.exe")
    begin.add_argument("--confirm-locations", action="store_true")
    begin.add_argument("--dedicated-apache", action="store_true")
    begin.add_argument("--max-seconds", type=int, default=900, choices=range(120, 1801), metavar="120..1800")
    for name in ("mark", "finish", "analyze", "_capture"):
        command = commands.add_parser(name)
        command.add_argument("--run", required=True)
        if name == "mark":
            command.add_argument("--event", choices=ORDER, required=True)
            command.add_argument("--confirm-action", action="store_true")
        if name == "finish":
            command.add_argument("--abort", action="store_true")
    args = parser.parse_args()
    if args.command == "inspect":
        data = inspect_services(); print(json.dumps(data, ensure_ascii=True, indent=2))
        return 0 if data.get("status") == "OK" else 2
    if args.command == "sql-check":
        data = sql(args.php)
        print(json.dumps({"status": data["status"], "nodes": {n: v["identity"] for n, v in data.get("nodes", {}).items()}}))
        return 0 if data["status"] == "OK" else 2
    if args.command == "start":
        return start(args)
    run = run_path(args.run)
    if args.command == "_capture":
        return worker(run)
    lock = run / "operation.lock"
    try:
        with lock.open("x", encoding="ascii") as file:
            file.write(str(os.getpid()))
    except FileExistsError:
        raise P14Error("Otro comando P14 esta operando sobre este escenario; no se altera su bloqueo.") from None
    try:
        if args.command == "mark":
            return mark(run, args.event, args.confirm_action)
        return finish(run, abort=getattr(args, "abort", False), review=args.command == "analyze")
    except KeyboardInterrupt:
        (run / "capture.stop").touch(exist_ok=True)
        print("Parada de captura solicitada. Restaure servicios manualmente y ejecute finish --abort o analyze.")
        return 130
    finally:
        lock.unlink(missing_ok=True)


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (OSError, ValueError, KeyError, subprocess.SubprocessError):
        print("ERROR: operacion incompleta; revise entrada, archivos, captura y dependencias. No se modificaron servicios.", file=sys.stderr)
        # Los mensajes operativos detallados de ValueError son constantes controladas, no excepciones de PDO.
        if isinstance(sys.exc_info()[1], P14Error):
            print(str(sys.exc_info()[1]), file=sys.stderr)
        sys.exit(2)
