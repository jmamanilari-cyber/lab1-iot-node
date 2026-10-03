from http.server import BaseHTTPRequestHandler, HTTPServer
import json
from datetime import datetime

class Receptor(BaseHTTPRequestHandler):

    def do_POST(self):

        if self.path != "/telemetry":
            self.send_response(404)
            self.end_headers()
            return

        longitud = int(self.headers.get("Content-Length", 0))
        datos = self.rfile.read(longitud)

        try:
            mensaje = json.loads(datos.decode("utf-8"))

            print("\n================================")
            print("       MENSAJE RECIBIDO")
            print("================================")

            print("Hora PC:", datetime.now().strftime("%H:%M:%S"))
            print("Nodo:", mensaje.get("node_id"))
            print("Secuencia:", mensaje.get("seq"))
            print("Timestamp:", mensaje.get("timestamp"))
            print("PIR:", mensaje.get("value"))
            print("Luz:", mensaje.get("luz"))
            print("RSSI:", mensaje.get("rssi"), "dBm")
            print("Uptime:", mensaje.get("uptime_ms"), "ms")

            print("\nJSON completo:")
            print(json.dumps(mensaje, indent=2))

            respuesta = {
                "status": "ok",
                "seq_recibida": mensaje.get("seq")
            }

            respuesta_json = json.dumps(respuesta).encode("utf-8")

            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(respuesta_json)))
            self.end_headers()

            self.wfile.write(respuesta_json)

        except Exception as e:

            print("ERROR:", e)

            self.send_response(400)
            self.end_headers()


servidor = HTTPServer(("0.0.0.0", 5000), Receptor)

print("================================")
print(" SERVIDOR HTTP - ESP32_LUZ_01")
print("================================")
print("Puerto: 5000")
print("Esperando telemetria...")

servidor.serve_forever()