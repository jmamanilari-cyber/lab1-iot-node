#include <WiFi.h>
// AGREGADO: RELE Y LEDS - tarea independiente del envio de datos.
#include <freertos/FreeRTOS.h>
#include <freertos/task.h>

#include <PubSubClient.h>
#include <time.h>

const char* NODE_ID = "ESP32_LUZ_01";

const int PIR_PIN = 27;

// =======================================================
// AGREGADO: RELE Y LEDS - CONFIGURACION Y CONTROL LOCAL
// Se conserva el programa original completo debajo.
// Primera nueva deteccion: rele ON, verde ON, rojo OFF.
// Siguiente nueva deteccion: rele OFF, verde OFF, rojo ON.
// Se mantiene el estado entre detecciones. Reiniciar restablece OFF.
// =======================================================

const int RELE_PIN = 26;
const int LED_VERDE_PIN = 25;
const int LED_ROJO_PIN = 33;

// true: modulo activado con LOW. false: activado con HIGH.
// Usar modulo con entrada IN compatible con logica de 3.3 V.
const bool RELE_ACTIVO_BAJO = true;
const unsigned long INTERVALO_ACTUADORES_MS = 20;

portMUX_TYPE mutexActuadores = portMUX_INITIALIZER_UNLOCKED;
int pirActuador = 0;
int releActuador = 0;
int verdeActuador = 0;
int rojoActuador = 1;
unsigned long cambiosActuador = 0;

// Instantanea que corresponde al JSON de una transmision.
int releMuestra = 0;
int verdeMuestra = 0;
int rojoMuestra = 1;

int nivelElectricoRele(int encendido) {

  if (RELE_ACTIVO_BAJO) {
    return encendido ? LOW : HIGH;
  }

  return encendido ? HIGH : LOW;
}

void leerActuadores(
  int& pir,
  int& rele,
  int& verde,
  int& rojo,
  unsigned long& cambios
) {

  // Copiar juntos los estados de la ultima actualizacion.
  portENTER_CRITICAL(&mutexActuadores);
  pir = pirActuador;
  rele = releActuador;
  verde = verdeActuador;
  rojo = rojoActuador;
  cambios = cambiosActuador;
  portEXIT_CRITICAL(&mutexActuadores);
}

void tareaActuadores(void* parametro) {

  (void)parametro;
  TickType_t ultimaEjecucion = xTaskGetTickCount();
  int releAnterior = 0;

  // AGREGADO: estado mantenido entre detecciones, mientras haya alimentacion.
  int estadoGuardado = 0;

  // Si arranca con el PIR activo, esperar a LOW antes de otra deteccion.
  int pirAnterior = digitalRead(PIR_PIN) == HIGH ? 1 : 0;
  unsigned long cambios = 0;

  for (;;) {

    int pir = digitalRead(PIR_PIN) == HIGH ? 1 : 0;
    // AGREGADO: alternar solo con una nueva deteccion (flanco 0 -> 1).
    // Un HIGH sostenido cuenta una vez; volver a LOW conserva el estado.
    if (pir == 1 && pirAnterior == 0) {
      estadoGuardado = estadoGuardado ? 0 : 1;
    }

    pirAnterior = pir;

    // El rele y ambos LED representan el estado guardado.
    int rele = estadoGuardado;
    int verde = rele;
    int rojo = rele ? 0 : 1;

    digitalWrite(RELE_PIN, nivelElectricoRele(rele));
    digitalWrite(LED_VERDE_PIN, verde ? HIGH : LOW);
    digitalWrite(LED_ROJO_PIN, rojo ? HIGH : LOW);

    if (rele != releAnterior) {
      cambios++;
      releAnterior = rele;
    }

    portENTER_CRITICAL(&mutexActuadores);
    pirActuador = pir;
    releActuador = rele;
    verdeActuador = verde;
    rojoActuador = rojo;
    cambiosActuador = cambios;
    portEXIT_CRITICAL(&mutexActuadores);

    // Esta tarea sigue funcionando durante HTTP, esperas y cortes Wi-Fi.
    // No modifica los periodos ni los contadores del experimento original.
    vTaskDelayUntil(
      &ultimaEjecucion,
      pdMS_TO_TICKS(INTERVALO_ACTUADORES_MS)
    );
  }
}

void iniciarActuadores() {

  // Estado inicial OFF; despues cada nueva deteccion alterna ON/OFF.
  digitalWrite(RELE_PIN, nivelElectricoRele(0));
  pinMode(RELE_PIN, OUTPUT);
  digitalWrite(RELE_PIN, nivelElectricoRele(0));

  pinMode(LED_VERDE_PIN, OUTPUT);
  pinMode(LED_ROJO_PIN, OUTPUT);
  digitalWrite(LED_VERDE_PIN, LOW);
  digitalWrite(LED_ROJO_PIN, HIGH);

  pinMode(PIR_PIN, INPUT);

  if (
    xTaskCreate(
      tareaActuadores,
      "rele_leds",
      2048,
      nullptr,
      2,
      nullptr
    ) != pdPASS
  ) {

    Serial.println("ERROR: no se pudo iniciar el control de rele y LEDs.");
    // Mantener OFF si no se pudo crear la tarea.
    for (;;) {
      delay(1000);
    }
  }

  Serial.println("RELE GPIO26 | VERDE GPIO25 | ROJO GPIO33");
  Serial.println("ACTUADORES,Millis,WiFi,PIR,Rele,LED_verde,LED_rojo,Cambios");
  Serial.println("ACTUADORES_TX,Secuencia,PIR,Rele,LED_verde,LED_rojo");
}

void mostrarActuadores() {

  static unsigned long ultimaImpresion = 0;
  static int releAnterior = -1;
  static int wifiAnterior = -1;

  int pir, rele, verde, rojo;
  unsigned long cambios;
  leerActuadores(pir, rele, verde, rojo, cambios);

  int wifi = WiFi.status() == WL_CONNECTED ? 1 : 0;

  if (
    rele != releAnterior ||
    wifi != wifiAnterior ||
    millis() - ultimaImpresion >= 1000
  ) {

    ultimaImpresion = millis();
    releAnterior = rele;
    wifiAnterior = wifi;

    Serial.printf(
      "ACTUADORES,%lu,%d,%d,%d,%d,%d,%lu\n",
      ultimaImpresion,
      wifi,
      pir,
      rele,
      verde,
      rojo,
      cambios
    );
  }
}

void capturarActuadoresParaJson(int& pir, int& luz) {

  unsigned long cambios;
  leerActuadores(pir, releMuestra, verdeMuestra, rojoMuestra, cambios);

  // "luz" ahora corresponde a la orden fisica del rele.
  // No es realimentacion del contacto mecanico ni de una lampara.
  luz = releMuestra;
}

void mostrarActuadoresTransmitidos(unsigned long seq, int pir) {

  Serial.printf(
    "ACTUADORES_TX,%lu,%d,%d,%d,%d\n",
    seq,
    pir,
    releMuestra,
    verdeMuestra,
    rojoMuestra
  );
}

// FIN AGREGADO: RELE Y LEDS


// =====================================================
// WIFI
// =====================================================

// Pon EXACTAMENTE el mismo nombre que ya te funcionó.
// Respeta espacios.
const char* ssid = "CONFIGURAR_EN_EQUIPO_LOCAL";
const char* password = "CONFIGURAR_EN_EQUIPO_LOCAL";

// =====================================================
// MQTT
// =====================================================

const char* MQTT_BROKER = "IP_BROKER_LOCAL";
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

  // AGREGADO: RELE Y LEDS - capturar la ultima orden fisica completa.
  capturarActuadoresParaJson(estadoPIR, estadoLuz);

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


  // AGREGADO: RELE Y LEDS - campos de la misma muestra del PIR.
  json += ",\"rele\":";
  json += releMuestra;
  json += ",\"led_verde\":";
  json += verdeMuestra;
  json += ",\"led_rojo\":";
  json += rojoMuestra;
  // FIN AGREGADO: RELE Y LEDS

  json += "}";

  // AGREGADO: relacionar los estados con la secuencia original.
  mostrarActuadoresTransmitidos(secuencia, estadoPIR);

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

  // AGREGADO: RELE Y LEDS
  iniciarActuadores();

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

  // AGREGADO: RELE Y LEDS - antes de cualquier return del loop.
  mostrarActuadores();


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