import json
import paho.mqtt.client as mqtt

BROKER = "TU_IP_BROKER"
PORT = 1883

TOPIC_TELEMETRY = "telecom/lab1/ESP32_LUZ_01/telemetry"
TOPIC_ACK = "telecom/lab1/ESP32_LUZ_01/ack"


def on_connect(client, userdata, flags, reason_code, properties):
    print("Conectado al broker MQTT")
    print("Esperando telemetria...")
    print()

    client.subscribe(TOPIC_TELEMETRY)


def on_message(client, userdata, msg):

    try:
        mensaje = json.loads(msg.payload.decode())

        seq = mensaje.get("seq")

        print("==============================")
        print("TELEMETRIA RECIBIDA")
        print("==============================")

        print("Nodo:", mensaje.get("node_id"))
        print("Seq:", seq)
        print("PIR:", mensaje.get("value"))
        print("Luz:", mensaje.get("luz"))
        print("RSSI:", mensaje.get("rssi"))
        print()

        ack = {
            "seq": seq
        }

        ack_json = json.dumps(
            ack,
            separators=(",", ":")
        )

        client.publish(
            TOPIC_ACK,
            ack_json
        )

        print("ACK enviado:", ack_json)
        print()

    except Exception as e:

        print("ERROR:", e)


client = mqtt.Client(
    mqtt.CallbackAPIVersion.VERSION2
)

client.on_connect = on_connect
client.on_message = on_message

print("==============================")
print(" RESPONDEDOR MQTT ACK")
print("==============================")
print("Broker:", BROKER)
print("Puerto:", PORT)

client.connect(
    BROKER,
    PORT,
    60
)

client.loop_forever()