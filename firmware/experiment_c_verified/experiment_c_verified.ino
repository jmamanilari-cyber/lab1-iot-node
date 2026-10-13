/*
 * EXPERIMENTO C INSTRUMENTADO (nuevo ensayo; NO valida registros antiguos)
 * Punto de partida: firmware/experiment_c_wifi_recovery, manteniendo
 * la tarea del PIR, rele y LED. Adicion: Wi-Fi + MQTT + ACK de aplicacion.
 *
 * CSV de eventos: C_EVENT,run_id,ciclo,millis,tipo,seq
 * CSV de resultado: C_RESULT,run_id,ciclo,wifi_ms,post_wifi_acker_ms,total_ms,seq,estado
 * Definicion: t_wifi = WIFI_UP - WIFI_DOWN (incluye duracion de la interrupcion).
 * t_post = ACK_OK - WIFI_UP (incluye MQTT, publicacion y RTT del ACK).
 * NO mide desde la restauracion del AP, salvo que se instrumente externamente.
 */
#include <WiFi.h>
#include <PubSubClient.h>
#include <esp_system.h>
#include <freertos/FreeRTOS.h>
#include <freertos/task.h>
#include "secrets.h" // Copiar secrets.example.h a secrets.h y editar (ignorado por Git).

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


// --- Configuracion de prueba ---
const int NUM_CICLOS = 30;
const unsigned long REINTENTO_WIFI_MS = 2000UL;
const unsigned long REINTENTO_MQTT_MS = 1500UL;
const unsigned long LIMITE_ACK_MS = 10000UL;
const unsigned long LIMITE_SERVICIO_MS = 20000UL;
const unsigned long ESPERA_ENTRE_CICLOS_MS = 1500UL;
const char* TOPIC_TELEMETRIA = "telecom/lab1/ESP32_LUZ_01/telemetry";
const char* TOPIC_ACK = "telecom/lab1/ESP32_LUZ_01/ack";

WiFiClient transporte;
PubSubClient mqtt(transporte);
String runId;
bool wifiAnteriorC = false;
bool activo = false;
bool finalImpreso = false;
bool brokerListoAlgunaVez = false;
bool publicacionPendienteACK = false;
bool multipleCortes = false;
bool yaPublico = false;
int cicloActual = 0;
int exitos = 0;
int fallidos = 0;
unsigned long ultimaPruebaWiFi = 0;
unsigned long ultimaPruebaMQTT = 0;
unsigned long finCicloMs = 0;
unsigned long instanteCaida = 0;
unsigned long instanteWiFiUp = 0;
unsigned long instantePublicado = 0;
unsigned long ultimaPublicacionIntentada = 0;
unsigned long seqPendiente = 0;

void evento(const char* tipo, unsigned long seq = 0, unsigned long instante = 0) {
  if (instante == 0) instante = millis();
  Serial.printf("C_EVENT,%s,%d,%lu,%s,%lu\n", runId.c_str(), cicloActual,
                instante, tipo, seq);
}

void imprimirResultado(const char* estado, unsigned long fin = 0) {
  if (fin == 0) fin = millis();
  long tWifi = instanteWiFiUp ? (long)(instanteWiFiUp - instanteCaida) : -1L;
  long tPost = -1L;
  long tTotal = -1L;
  bool valido = strcmp(estado, "ACK_OK") == 0 && !multipleCortes;
  if (valido) {
    tPost = (long)(fin - instanteWiFiUp);
    tTotal = (long)(fin - instanteCaida);
    exitos++;
  } else {
    fallidos++;
  }
  Serial.printf("C_RESULT,%s,%d,%ld,%ld,%ld,%lu,%s\n", runId.c_str(),
                cicloActual, tWifi, tPost, tTotal, seqPendiente,
                multipleCortes ? "MULTIPLE_OUTAGES" : estado);
  activo = false;
  publicacionPendienteACK = false;
  yaPublico = false;
  finCicloMs = fin;
  Serial.printf("C_PROGRESS,%s,%d,%d,%d\n", runId.c_str(), cicloActual, exitos, fallidos);
  if (cicloActual < NUM_CICLOS) {
    Serial.println("Esperar C_READY antes del siguiente corte.");
  }
}

// Extrae solo campos del JSON compacto emitido por el receptor C.
// Se comprueban run_id, ciclo y seq para ignorar ACK viejos o ajenos.
String extraerTexto(const String& json, const char* clave) {
  String patron = String("\"") + clave + "\":\"";
  int p = json.indexOf(patron);
  if (p < 0) return "";
  p += patron.length();
  int f = json.indexOf('"', p);
  return f < 0 ? "" : json.substring(p, f);
}
long extraerNumero(const String& json, const char* clave) {
  String patron = String("\"") + clave + "\":";
  int p = json.indexOf(patron);
  if (p < 0) return -1;
  p += patron.length();
  while (p < (int)json.length() && json[p] == ' ') p++;
  int f = p;
  while (f < (int)json.length() && json[f] >= '0' && json[f] <= '9') f++;
  return f > p ? json.substring(p, f).toInt() : -1;
}

void callbackMQTT(char* topic, byte* payload, unsigned int length) {
  if (strcmp(topic, TOPIC_ACK) != 0 || !activo || !publicacionPendienteACK) return;
  String respuesta;
  for (unsigned int i = 0; i < length; i++) respuesta += (char)payload[i];
  if (extraerTexto(respuesta, "experiment") != "C" ||
      extraerTexto(respuesta, "run_id") != runId ||
      extraerNumero(respuesta, "cycle") != cicloActual ||
      extraerNumero(respuesta, "seq") != (long)seqPendiente) {
    evento("ACK_IGNORADO", seqPendiente);
    return;
  }
  unsigned long t = millis();
  evento("ACK_OK", seqPendiente, t);
  imprimirResultado("ACK_OK", t);
}

bool conectarMQTT() {
  if (WiFi.status() != WL_CONNECTED) return false;
  if (mqtt.connected()) return true;
  unsigned long ahora = millis();
  if (ahora - ultimaPruebaMQTT < REINTENTO_MQTT_MS) return false;
  ultimaPruebaMQTT = ahora;
  String id = String("C_") + runId;
  if (!mqtt.connect(id.c_str())) return false;
  if (!mqtt.subscribe(TOPIC_ACK)) {
    mqtt.disconnect();
    return false;
  }
  evento("MQTT_UP");
  brokerListoAlgunaVez = true;
  return true;
}

void intentarWiFi() {
  unsigned long ahora = millis();
  if (ahora - ultimaPruebaWiFi >= REINTENTO_WIFI_MS) {
    ultimaPruebaWiFi = ahora;
    WiFi.reconnect();
    if (activo) evento("WIFI_RETRY");
  }
}

void publicarPrimeraTelemetria() {
  if (yaPublico || !activo || instanteWiFiUp == 0 || !mqtt.connected()) return;
  unsigned long ahora = millis();
  if (ahora - ultimaPublicacionIntentada < 1000UL) return;
  ultimaPublicacionIntentada = ahora;
  int pir, luz;
  capturarActuadoresParaJson(pir, luz);
  String json = String("{\"experiment\":\"C\",\"run_id\":\"") + runId +
    "\",\"node_id\":\"" + NODE_ID +
    "\",\"cycle\":" + String(cicloActual) +
    ",\"seq\":" + String(seqPendiente) +
    ",\"value\":" + String(pir) +
    ",\"luz\":" + String(luz) +
    ",\"rele\":" + String(releMuestra) +
    ",\"led_verde\":" + String(verdeMuestra) +
    ",\"led_rojo\":" + String(rojoMuestra) +
    ",\"rssi\":" + String(WiFi.RSSI()) +
    ",\"uptime_ms\":" + String(ahora) + "}";
  if (!mqtt.publish(TOPIC_TELEMETRIA, json.c_str())) {
    evento("PUBLISH_FAIL", seqPendiente);
    return;
  }
  instantePublicado = millis();
  evento("PUBLISH_OK", seqPendiente, instantePublicado);
  mostrarActuadoresTransmitidos(seqPendiente, pir);
  yaPublico = true;
  publicacionPendienteACK = true;
}

void setup() {
  Serial.begin(115200);
  delay(500);
  iniciarActuadores();
  // ID nuevo por ejecucion; evita aceptar mensajes del ensayo anterior.
  uint64_t mac = ESP.getEfuseMac();
  runId = String((uint32_t)(mac & 0xFFFFFFFFULL), HEX) + "_" + String((uint32_t)esp_random(), HEX);
  Serial.printf("C_SESSION,%s,%s,%d\n", runId.c_str(), NODE_ID, NUM_CICLOS);
  Serial.println("C_HEADER,C_EVENT,run_id,cycle,millis,event,seq");
  Serial.println("C_HEADER,C_RESULT,run_id,cycle,wifi_ms,post_wifi_ack_ms,total_ms,seq,status");
  Serial.println("Esperar a C_READY antes del primer corte. Se requieren 30 ciclos.");
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.persistent(false);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  mqtt.setServer(MQTT_BROKER_IP, MQTT_BROKER_PORT);
  mqtt.setCallback(callbackMQTT);
  mqtt.setBufferSize(512);
  mqtt.setSocketTimeout(2);
  wifiAnteriorC = false;
}

void loop() {
  mostrarActuadores();
  unsigned long ahora = millis();
  bool wifiActual = WiFi.status() == WL_CONNECTED;

  // Un ciclo comienza cuando se detecta perdida despues de haber verificado el broker.
  if (wifiAnteriorC && !wifiActual && !activo && brokerListoAlgunaVez &&
      cicloActual < NUM_CICLOS && ahora - finCicloMs >= ESPERA_ENTRE_CICLOS_MS) {
    cicloActual++;
    activo = true;
    yaPublico = false;
    publicacionPendienteACK = false;
    multipleCortes = false;
    instanteCaida = ahora;
    instanteWiFiUp = 0;
    seqPendiente = (unsigned long)cicloActual;
    evento("WIFI_DOWN", seqPendiente, ahora);
  }
  if (wifiAnteriorC && !wifiActual && activo && instanteWiFiUp != 0) {
    // Dos cortes en un mismo ciclo invalidan el tiempo como ensayo simple.
    multipleCortes = true;
    evento("WIFI_DOWN_AGAIN", seqPendiente, ahora);
    imprimirResultado("WIFI_DOWN_AGAIN", ahora);
  }
  if (!wifiAnteriorC && wifiActual) {
    if (activo && instanteWiFiUp == 0) {
      instanteWiFiUp = ahora;
      evento("WIFI_UP", seqPendiente, ahora);
    } else if (!activo) {
      evento("WIFI_UP_IDLE");
    }
  }
  wifiAnteriorC = wifiActual;

  if (!wifiActual) {
    intentarWiFi();
  } else {
    conectarMQTT();
    if (mqtt.connected()) {
      mqtt.loop();
      if (activo) publicarPrimeraTelemetria();
      else if (cicloActual < NUM_CICLOS &&
               ahora - finCicloMs >= ESPERA_ENTRE_CICLOS_MS) {
        static int ultimoReady = -1;
        if (ultimoReady != cicloActual) {
          ultimoReady = cicloActual;
          Serial.printf("C_READY,%s,%d\n", runId.c_str(), cicloActual + 1);
        }
      }
    }
  }
  if (activo && instanteWiFiUp != 0) {
    if (publicacionPendienteACK && ahora - instantePublicado >= LIMITE_ACK_MS) {
      evento("ACK_TIMEOUT", seqPendiente);
      imprimirResultado("ACK_TIMEOUT");
    } else if (ahora - instanteWiFiUp >= LIMITE_SERVICIO_MS) {
      evento("SERVICE_TIMEOUT", seqPendiente);
      imprimirResultado("SERVICE_TIMEOUT");
    }
  }
  if (cicloActual >= NUM_CICLOS && !activo && !finalImpreso) {
    finalImpreso = true;
    Serial.printf("C_SUMMARY,%s,%d,%d,%d\n", runId.c_str(), cicloActual, exitos, fallidos);
  }
  delay(20);
}
