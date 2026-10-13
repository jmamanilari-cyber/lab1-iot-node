"""Guardar el monitor serial original y extraer las filas C_EVENT/C_RESULT sin inventarlas."""
import argparse
import csv
from datetime import datetime, timezone
from pathlib import Path

import serial

EVENT_FIELDS = ["timestamp_pc_utc", "run_id", "cycle", "millis", "event", "seq"]
RESULT_FIELDS = ["timestamp_pc_utc", "run_id", "cycle", "wifi_ms", "post_wifi_ack_ms", "total_ms", "seq", "status"]


def main():
    parser = argparse.ArgumentParser(description="Capturar salida serial ESP32 y registros individuales del experimento C")
    parser.add_argument("--port", required=True, help="COM5, COM6 o /dev/ttyUSB0")
    parser.add_argument("--baud", default=115200, type=int)
    parser.add_argument("--output-dir", type=Path, required=True)
    args = parser.parse_args()
    args.output_dir.mkdir(parents=True, exist_ok=True)
    paths = [args.output_dir / name for name in ("device_raw.log", "device_events.csv", "device_results.csv")]
    if any(p.exists() for p in paths):
        parser.error("Ya existe evidencia del ESP32 en esa carpeta. Usa una carpeta NUEVA por ensayo.")

    # Todos los archivos se abren de forma exclusiva: no se sobreescriben registros anteriores.
    with paths[0].open("x", encoding="utf-8") as raw, \
         paths[1].open("x", encoding="utf-8", newline="") as ef, \
         paths[2].open("x", encoding="utf-8", newline="") as rf:
        events = csv.DictWriter(ef, fieldnames=EVENT_FIELDS)
        results = csv.DictWriter(rf, fieldnames=RESULT_FIELDS)
        events.writeheader()
        results.writeheader()
        ef.flush(); rf.flush()
        print(f"Guardando salida serial {args.port} a {paths[0]}")
        print("Abrir puerto suele reiniciar ESP32; NO abrir simultaneamente monitor Arduino. Ctrl+C para finalizar.")
        with serial.Serial(args.port, args.baud, timeout=1) as connection:
            try:
                while True:
                    line = connection.readline().decode("utf-8", errors="replace").rstrip("\r\n")
                    if not line:
                        continue
                    stamp = datetime.now(timezone.utc).isoformat(timespec="milliseconds")
                    raw.write(f"{stamp}\t{line}\n")
                    raw.flush()
                    print(line, flush=True)
                    parts = line.split(",")
                    if parts[0] == "C_EVENT" and len(parts) == 6:
                        try:
                            events.writerow(dict(zip(EVENT_FIELDS, [stamp] + parts[1:])))
                            ef.flush()
                        except ValueError:
                            pass
                    elif parts[0] == "C_RESULT" and len(parts) == 8:
                        results.writerow(dict(zip(RESULT_FIELDS, [stamp] + parts[1:])))
                        rf.flush()
            except KeyboardInterrupt:
                print("Captura detenida. Archivos guardados.")


if __name__ == "__main__":
    main()
