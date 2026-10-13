"""Receptor/ACK de experimento C. Registra telemetria realmente recibida.

No sustituye las medidas del ESP32. Se usa junto con c_serial_capture.py.
"""
import argparse
import csv
import json
import os
from datetime import datetime, timezone
from pathlib import Path

import paho.mqtt.client as mqtt

TOPIC_TELEMETRY = "telecom/lab1/ESP32_LUZ_01/telemetry"
TOPIC_ACK = "telecom/lab1/ESP32_LUZ_01/ack"
CSV_COLUMNS = ["timestamp_utc", "run_id", "cycle", "seq", "node_id", "topic", "ack_publish_rc"]


def utc_now():
    return datetime.now(timezone.utc).isoformat(timespec="milliseconds")


def main():
    parser = argparse.ArgumentParser(description="Registrar el receptor MQTT y enviar ACK correlacionados del experimento C")
    parser.add_argument("--broker", required=True, help="IPv4/hostname del broker Mosquitto")
    parser.add_argument("--port", type=int, default=1883)
    parser.add_argument("--output-dir", required=True, type=Path, help="Misma carpeta que c_serial_capture.py")
    args = parser.parse_args()
    args.output_dir.mkdir(parents=True, exist_ok=True)
    csv_path = args.output_dir / "receiver_events.csv"
    raw_path = args.output_dir / "receiver_raw.jsonl"
    if csv_path.exists() or raw_path.exists():
        parser.error("Ya existen registros del receptor en esta carpeta. Usa una carpeta NUEVA por ensayo.")

    with csv_path.open("w", newline="", encoding="utf-8") as data_file, raw_path.open("w", encoding="utf-8") as raw_file:
        writer = csv.DictWriter(data_file, fieldnames=CSV_COLUMNS)
        writer.writeheader()
        data_file.flush()

        def on_connect(client, userdata, flags, reason_code, properties):
            if reason_code != 0:
                print(f"ERROR conexion broker: {reason_code}", flush=True)
                return
            client.subscribe(TOPIC_TELEMETRY, qos=0)
            print("C_RECEPTOR_READY: suscrito a", TOPIC_TELEMETRY, flush=True)

        def on_message(client, userdata, message):
            stamp = utc_now()
            try:
                payload = json.loads(message.payload.decode("utf-8"))
                if not isinstance(payload, dict) or payload.get("experiment") != "C":
                    return  # No alterar experimentos A/B/D.
                run_id = payload["run_id"]
                cycle = payload["cycle"]
                seq = payload["seq"]
                node_id = payload["node_id"]
                if not (isinstance(run_id, str) and run_id and
                        isinstance(cycle, int) and 1 <= cycle <= 30 and
                        isinstance(seq, int) and seq == cycle and
                        node_id == "ESP32_LUZ_01"):
                    raise ValueError("campos del experimento C invalidos")

                ack = {"experiment": "C", "run_id": run_id, "cycle": cycle, "seq": seq}
                ack_json = json.dumps(ack, ensure_ascii=True, separators=(",", ":"))
                info = client.publish(TOPIC_ACK, payload=ack_json, qos=0, retain=False)
                record = dict(timestamp_utc=stamp, run_id=run_id, cycle=cycle, seq=seq,
                              node_id=node_id, topic=message.topic, ack_publish_rc=info.rc)
                writer.writerow(record)
                data_file.flush()
                raw_file.write(json.dumps({"timestamp_utc": stamp, "telemetry": payload,
                                           "ack": ack, "ack_publish_rc": info.rc},
                                          ensure_ascii=False) + "\n")
                raw_file.flush()
                print(f"C_RECEIVED,{run_id},{cycle},{seq},{stamp},ACK_RC={info.rc}", flush=True)
            except (json.JSONDecodeError, KeyError, ValueError, UnicodeDecodeError) as error:
                print(f"C_REJECTED,{stamp},{error}", flush=True)

        client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id=f"c_receiver_{os.getpid()}")
        client.on_connect = on_connect
        client.on_message = on_message
        print("Iniciando receptor C. Para salir: Ctrl+C.", flush=True)
        client.connect(args.broker, args.port, keepalive=60)
        try:
            client.loop_forever()
        except KeyboardInterrupt:
            print("Receptor detenido, registros guardados.", flush=True)
        finally:
            client.disconnect()


if __name__ == "__main__":
    main()
