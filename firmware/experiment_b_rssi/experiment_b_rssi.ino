#include <WiFi.h>
#include <PubSubClient.h>
#include <time.h>

const char* NODE_ID = "ESP32_LUZ_01";

const int PIR_PIN = 27;

// =====================================================
// WIFI
// =====================================================

// Pon EXACTAMENTE el mismo nombre que ya te funcionó.
// Respeta espacios.
const char* ssid = "TU_SSID";
const char* password = "TU_CLAVE_WIFI";

// =====================================================
// MQTT
// =====================================================

const char* MQTT_BROKER = "TU_IP_BROKER";
const int MQTT_PORT = 1883;

const char* TOPIC_TELEMETRY =
  "telecom/lab1/ESP32_LUZ_01/telemetry";

const char* TOPIC_ACK =
  "telecom/lab1/ESP32_LUZ_01/ack";

// =====================================================
// EXPERIMENTO B
// =====================================================

// PRIMERA VEZ:
const char* CONDICION = "LEJOS";

// Cuando acabes las 30 de CERCA,
// cambia solamente a:
// const char* CONDICION = "LEJOS";

const int NUM_PRUEBAS = 30;
const unsigned long PERIODO_MS = 5000;
const unsigned long TIMEOUT_ACK = 3000;

// =====================================================
// OBJETOS
// =====================================================

WiFiClient wifiClient;
PubSubClient mqttClient(wifiClient);

// =====================================================
// VARIABLES
// =====================================================

int estadoPIR = 0;
int estadoLuz = 0;

unsigned long secuencia = 0;
unsigned long ultimoEnvio = 0;

int pruebas = 0;
int exitos = 0;

bool esperandoACK = false;
bool terminado = false;

unsigned long inicioACK = 0;
unsigned long seqPendiente = 0;

int rssiPendiente = 0;
int bytesPendiente = 0;

// =====================================================
// WIFI
// =====================================================

bool conectarWiFi() {

  Serial.println("Conectando Wi-Fi...");
  Serial.print("SSID: ");
  Serial.println(ssid);

  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid, password);

  unsigned long inicio = millis();

  while (
    WiFi.status() != WL_CONNECTED &&
    millis() - inicio < 20000
  ) {
    Serial.print(".");
    delay(500);
  }

  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {

    Serial.println("WIFI CONECTADO");

    Serial.print("IP ESP32: ");
    Serial.println(WiFi.localIP());

    Serial.print("RSSI inicial: ");
    Serial.print(WiFi.RSSI());
    Serial.println(" dBm");

    return true;
  }

  Serial.println("ERROR WIFI");
  return false;
}

// =====================================================
// TIMESTAMP
// =====================================================

unsigned long obtenerTimestamp() {

  time_t ahora;
  time(&ahora);

  if (ahora < 100000)
    return 0;

  return (unsigned long)ahora;
}

// =====================================================
// CREAR JSON
// =====================================================

String crearJson() {

  secuencia++;

  estadoPIR = digitalRead(PIR_PIN);
  estadoLuz = estadoPIR;

  String json;

  json += "{";

  json += "\"node_id\":\"";
  json += NODE_ID;
  json += "\",";

  json += "\"seq\":";
  json += secuencia;
  json += ",";

  json += "\"timestamp\":";
  json += obtenerTimestamp();
  json += ",";

  json += "\"value\":";
  json += estadoPIR;
  json += ",";

  json += "\"luz\":";
  json += estadoLuz;
  json += ",";

  json += "\"rssi\":";
  json += WiFi.RSSI();
  json += ",";

  json += "\"uptime_ms\":";
  json += millis();

  json += "}";

  return json;
}

// =====================================================
// CALLBACK ACK
// =====================================================

void callbackMQTT(
  char* topic,
  byte* payload,
  unsigned int length
) {

  String mensaje = "";

  for (unsigned int i = 0; i < length; i++) {
    mensaje += (char)payload[i];
  }

  int pos = mensaje.indexOf("\"seq\":");

  if (pos < 0)
    return;

  unsigned long seqACK =
    mensaje.substring(pos + 6).toInt();

  if (
    esperandoACK &&
    seqACK == seqPendiente
  ) {

    unsigned long rtt =
      millis() - inicioACK;

    esperandoACK = false;
    exitos++;

    Serial.print("ACK recibido | seq=");
    Serial.print(seqACK);

    Serial.print(" | RTT=");
    Serial.print(rtt);
    Serial.println(" ms");

    // CSV
    Serial.print("CSV,");
    Serial.print(pruebas);
    Serial.print(",");
    Serial.print(CONDICION);
    Serial.print(",");
    Serial.print(rssiPendiente);
    Serial.print(",");
    Serial.print(1);
    Serial.print(",");
    Serial.print(rtt);
    Serial.print(",");
    Serial.println(bytesPendiente);

    Serial.print("Progreso: ");
    Serial.print(pruebas);
    Serial.print("/");
    Serial.println(NUM_PRUEBAS);
  }
}

// =====================================================
// MQTT
// =====================================================

bool conectarMQTT() {

  if (mqttClient.connected())
    return true;

  String clientId =
    String(NODE_ID) + "_" +
    String((uint32_t)ESP.getEfuseMac(), HEX);

  Serial.println("Conectando MQTT...");

  if (mqttClient.connect(clientId.c_str())) {

    Serial.println("MQTT CONECTADO");

    mqttClient.subscribe(TOPIC_ACK);

    return true;
  }

  Serial.print("Error MQTT: ");
  Serial.println(mqttClient.state());

  return false;
}

// =====================================================
// RESULTADO FINAL
// =====================================================

void resultadoFinal() {

  float entrega =
    ((float)exitos / NUM_PRUEBAS) * 100.0;

  int perdidos =
    NUM_PRUEBAS - exitos;

  Serial.println();
  Serial.println("==============================");
  Serial.println(" EXPERIMENTO B TERMINADO");
  Serial.println("==============================");

  Serial.print("Condicion: ");
  Serial.println(CONDICION);

  Serial.print("Enviados: ");
  Serial.println(NUM_PRUEBAS);

  Serial.print("Recibidos: ");
  Serial.println(exitos);

  Serial.print("Perdidos: ");
  Serial.println(perdidos);

  Serial.print("Entrega: ");
  Serial.print(entrega, 2);
  Serial.println(" %");

  Serial.println("==============================");
}

// =====================================================
// SETUP
// =====================================================

void setup() {

  Serial.begin(115200);
  delay(1000);

  pinMode(PIR_PIN, INPUT);

  Serial.println();
  Serial.println("==============================");
  Serial.println(" EXPERIMENTO B - RSSI");
  Serial.println("==============================");

  Serial.print("Condicion: ");
  Serial.println(CONDICION);

  Serial.println("30 transmisiones");
  Serial.println();

  if (!conectarWiFi())
    return;

  configTime(
    0,
    0,
    "pool.ntp.org",
    "time.nist.gov"
  );

  mqttClient.setServer(
    MQTT_BROKER,
    MQTT_PORT
  );

  mqttClient.setCallback(
    callbackMQTT
  );

  mqttClient.setBufferSize(512);

  conectarMQTT();

  Serial.println();
  Serial.println(
    "Prueba,Condicion,RSSI_dBm,Exito,RTT_ms,Bytes"
  );
}

// =====================================================
// LOOP
// =====================================================

void loop() {

  if (WiFi.status() != WL_CONNECTED)
    return;

  if (!mqttClient.connected())
    conectarMQTT();

  mqttClient.loop();

  // Timeout
  if (
    esperandoACK &&
    millis() - inicioACK >= TIMEOUT_ACK
  ) {

    esperandoACK = false;

    Serial.print("TIMEOUT seq=");
    Serial.println(seqPendiente);

    Serial.print("CSV,");
    Serial.print(pruebas);
    Serial.print(",");
    Serial.print(CONDICION);
    Serial.print(",");
    Serial.print(rssiPendiente);
    Serial.print(",");
    Serial.print(0);
    Serial.print(",");
    Serial.print(-1);
    Serial.print(",");
    Serial.println(bytesPendiente);

    Serial.print("Progreso: ");
    Serial.print(pruebas);
    Serial.print("/");
    Serial.println(NUM_PRUEBAS);
  }

  // Enviar
  if (
    !terminado &&
    !esperandoACK &&
    pruebas < NUM_PRUEBAS &&
    millis() - ultimoEnvio >= PERIODO_MS
  ) {

    ultimoEnvio = millis();

    String mensaje =
      crearJson();

    rssiPendiente =
      WiFi.RSSI();

    bytesPendiente =
      mensaje.length();

    seqPendiente =
      secuencia;

    inicioACK =
      millis();

    bool enviado =
      mqttClient.publish(
        TOPIC_TELEMETRY,
        mensaje.c_str()
      );

    pruebas++;

    if (enviado) {

      esperandoACK = true;

      Serial.print("Publicado | seq=");
      Serial.print(seqPendiente);

      Serial.print(" | RSSI=");
      Serial.print(rssiPendiente);

      Serial.println(" dBm");

    } else {

      esperandoACK = false;

      Serial.print("CSV,");
      Serial.print(pruebas);
      Serial.print(",");
      Serial.print(CONDICION);
      Serial.print(",");
      Serial.print(rssiPendiente);
      Serial.print(",");
      Serial.print(0);
      Serial.print(",");
      Serial.print(-1);
      Serial.print(",");
      Serial.println(bytesPendiente);
    }
  }

  // Final
  if (
    !terminado &&
    pruebas >= NUM_PRUEBAS &&
    !esperandoACK
  ) {

    terminado = true;

    resultadoFinal();
  }

  delay(10);
}