/*
===========================================================
 LAB 1 - SOFTWARE DE TELECOMUNICACIONES
 Sistema IoT de Control de Iluminacion

 EXPERIMENTO D - PERIODICIDAD

 Condiciones automaticas:
 1 s  -> 30 transmisiones
 5 s  -> 30 transmisiones
 10 s -> 30 transmisiones

 Metricas:
 - mensajes/minuto
 - bytes/minuto
 - RSSI
 - publicaciones exitosas
===========================================================
*/

#include <WiFi.h>
// AGREGADO: RELE Y LEDS - tarea independiente del envio de datos.
#include <freertos/FreeRTOS.h>
#include <freertos/task.h>

#include <PubSubClient.h>
#include <time.h>

// =======================================================
// 1. CONFIGURACION
// =======================================================

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


// -------------------------------------------------------
// WIFI
// Copia EXACTAMENTE los valores del firmware que funciono
// -------------------------------------------------------

const char* ssid = "CONFIGURAR_EN_EQUIPO_LOCAL";
const char* password = "CONFIGURAR_EN_EQUIPO_LOCAL";

// -------------------------------------------------------
// MQTT
// -------------------------------------------------------

const char* MQTT_BROKER = "IP_BROKER_LOCAL";
const int MQTT_PORT = 1883;

const char* MQTT_TOPIC =
  "telecom/lab1/ESP32_LUZ_01/telemetry";

// =======================================================
// 2. EXPERIMENTO
// =======================================================

// Las tres condiciones
const unsigned long PERIODOS[] = {
  1000,   // 1 segundo
  5000,   // 5 segundos
  10000   // 10 segundos
};

const int NUM_CONDICIONES = 3;

const int NUM_PRUEBAS = 30;

// Condicion actual:
// 0 -> 1 s
// 1 -> 5 s
// 2 -> 10 s

int condicionActual = 0;

// =======================================================
// 3. OBJETOS
// =======================================================

WiFiClient wifiClient;
PubSubClient mqttClient(wifiClient);

// =======================================================
// 4. VARIABLES DEL SISTEMA
// =======================================================

int estadoPIR = 0;
int estadoLuz = 0;

unsigned long secuencia = 0;

// =======================================================
// 5. VARIABLES DEL EXPERIMENTO
// =======================================================

int mensajesIntentados = 0;
int mensajesExitosos = 0;

unsigned long bytesTotal = 0;

unsigned long inicioCondicion = 0;
unsigned long ultimoEnvio = 0;

bool experimentoTerminado = false;

// =======================================================
// 6. WIFI
// =======================================================

bool conectarWiFi() {

  Serial.println();
  Serial.println("Conectando Wi-Fi...");

  Serial.print("SSID: ");
  Serial.println(ssid);

  WiFi.mode(WIFI_STA);

  WiFi.begin(
    ssid,
    password
  );

  unsigned long inicio =
    millis();

  while (
    WiFi.status() != WL_CONNECTED &&
    millis() - inicio < 20000
  ) {

    Serial.print(".");
    delay(500);
  }

  Serial.println();

  if (
    WiFi.status() == WL_CONNECTED
  ) {

    Serial.println(
      ">>> WI-FI CONECTADO"
    );

    Serial.print(
      "IP ESP32: "
    );

    Serial.println(
      WiFi.localIP()
    );

    Serial.print(
      "RSSI: "
    );

    Serial.print(
      WiFi.RSSI()
    );

    Serial.println(
      " dBm"
    );

    configTime(
      0,
      0,
      "pool.ntp.org",
      "time.nist.gov"
    );

    return true;
  }

  Serial.println(
    "ERROR WIFI"
  );

  return false;
}

// =======================================================
// 7. MQTT
// =======================================================

bool conectarMQTT() {

  if (
    WiFi.status() != WL_CONNECTED
  ) {

    return false;
  }

  if (
    mqttClient.connected()
  ) {

    return true;
  }

  Serial.println(
    "Conectando MQTT..."
  );

  String clientId =
    String(NODE_ID) +
    "_" +
    String(
      (uint32_t)ESP.getEfuseMac(),
      HEX
    );

  if (
    mqttClient.connect(
      clientId.c_str()
    )
  ) {

    Serial.println(
      ">>> MQTT CONECTADO"
    );

    return true;
  }

  Serial.print(
    "Error MQTT: "
  );

  Serial.println(
    mqttClient.state()
  );

  return false;
}

// =======================================================
// 8. TIMESTAMP
// =======================================================

unsigned long obtenerTimestamp() {

  time_t ahora;

  time(&ahora);

  if (
    ahora < 100000
  ) {

    return 0;
  }

  return
    (unsigned long)ahora;
}

// =======================================================
// 9. CREAR JSON
// =======================================================

String crearJson() {

  secuencia++;

  estadoPIR =
    digitalRead(PIR_PIN);

  estadoLuz =
    estadoPIR;

  // AGREGADO: RELE Y LEDS - capturar la ultima orden fisica completa.
  capturarActuadoresParaJson(estadoPIR, estadoLuz);

  String json;

  json.reserve(220);

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

// =======================================================
// 10. INICIAR UNA CONDICION
// =======================================================

void iniciarCondicion() {

  mensajesIntentados = 0;
  mensajesExitosos = 0;

  bytesTotal = 0;

  inicioCondicion =
    millis();

  ultimoEnvio =
    inicioCondicion;

  Serial.println();
  Serial.println(
    "======================================"
  );

  Serial.println(
    " NUEVA CONDICION"
  );

  Serial.println(
    "======================================"
  );

  Serial.print(
    "Periodicidad: "
  );

  Serial.print(
    PERIODOS[condicionActual] /
    1000
  );

  Serial.println(
    " s"
  );

  Serial.println(
    "Cantidad: 30 transmisiones"
  );

  Serial.println();

  Serial.println(
    "Prueba,Periodo_s,RSSI_dBm,Exito,Bytes"
  );
}

// =======================================================
// 11. RESULTADO DE LA CONDICION
// =======================================================

void finalizarCondicion() {

  unsigned long fin =
    millis();

  unsigned long duracionMs =
    fin - inicioCondicion;

  float duracionMin =
    duracionMs / 60000.0;

  float mensajesPorMinuto =
    mensajesExitosos /
    duracionMin;

  float bytesPorMinuto =
    bytesTotal /
    duracionMin;

  float porcentajeExito =

    (
      (float)mensajesExitosos /
      (float)NUM_PRUEBAS
    )

    * 100.0;


  Serial.println();
  Serial.println(
    "======================================"
  );

  Serial.println(
    " RESULTADO DE LA CONDICION"
  );

  Serial.println(
    "======================================"
  );

  Serial.print(
    "Periodo: "
  );

  Serial.print(
    PERIODOS[condicionActual] /
    1000
  );

  Serial.println(
    " s"
  );

  Serial.print(
    "Duracion medida: "
  );

  Serial.print(
    duracionMs / 1000.0,
    2
  );

  Serial.println(
    " s"
  );

  Serial.print(
    "Mensajes exitosos: "
  );

  Serial.print(
    mensajesExitosos
  );

  Serial.print(
    "/"
  );

  Serial.println(
    NUM_PRUEBAS
  );

  Serial.print(
    "Exito: "
  );

  Serial.print(
    porcentajeExito,
    2
  );

  Serial.println(
    " %"
  );

  Serial.print(
    "Bytes transmitidos: "
  );

  Serial.println(
    bytesTotal
  );

  Serial.print(
    "Mensajes/minuto: "
  );

  Serial.println(
    mensajesPorMinuto,
    2
  );

  Serial.print(
    "Bytes/minuto: "
  );

  Serial.println(
    bytesPorMinuto,
    2
  );


  // Resumen CSV

  Serial.print(
    "RESUMEN,"
  );

  Serial.print(
    PERIODOS[condicionActual] /
    1000
  );

  Serial.print(",");

  Serial.print(
    mensajesExitosos
  );

  Serial.print(",");

  Serial.print(
    mensajesPorMinuto,
    2
  );

  Serial.print(",");

  Serial.print(
    bytesTotal
  );

  Serial.print(",");

  Serial.println(
    bytesPorMinuto,
    2
  );

  Serial.println(
    "======================================"
  );
}

// =======================================================
// 12. SETUP
// =======================================================

void setup() {

  Serial.begin(
    115200
  );

  // AGREGADO: RELE Y LEDS
  iniciarActuadores();


  delay(1000);

  pinMode(
    PIR_PIN,
    INPUT
  );

  Serial.println();
  Serial.println(
    "======================================"
  );

  Serial.println(
    " EXPERIMENTO D - PERIODICIDAD"
  );

  Serial.println(
    " 1 s / 5 s / 10 s"
  );

  Serial.println(
    "======================================"
  );

  if (
    !conectarWiFi()
  ) {

    return;
  }

  mqttClient.setServer(
    MQTT_BROKER,
    MQTT_PORT
  );

  mqttClient.setBufferSize(
    512
  );

  if (
    !conectarMQTT()
  ) {

    return;
  }

  iniciarCondicion();
}

// =======================================================
// 13. LOOP
// =======================================================

void loop() {

  // AGREGADO: RELE Y LEDS - antes de cualquier return del loop.
  mostrarActuadores();


  if (
    experimentoTerminado
  ) {

    return;
  }

  // ----------------------------------------------------
  // Mantener Wi-Fi
  // ----------------------------------------------------

  if (
    WiFi.status() != WL_CONNECTED
  ) {

    Serial.println(
      "Wi-Fi perdido."
    );

    WiFi.reconnect();

    delay(1000);

    return;
  }

  // ----------------------------------------------------
  // Mantener MQTT
  // ----------------------------------------------------

  if (
    !mqttClient.connected()
  ) {

    conectarMQTT();

    delay(500);

    return;
  }

  mqttClient.loop();


  // ----------------------------------------------------
  // ENVIAR SEGUN PERIODICIDAD
  // ----------------------------------------------------

  unsigned long periodo =
    PERIODOS[condicionActual];

  if (
    mensajesIntentados < NUM_PRUEBAS &&
    millis() - ultimoEnvio >= periodo
  ) {

    ultimoEnvio =
      millis();

    String mensaje =
      crearJson();

    int bytesMensaje =
      mensaje.length();

    bool enviado =
      mqttClient.publish(
        MQTT_TOPIC,
        mensaje.c_str()
      );

    mensajesIntentados++;

    if (
      enviado
    ) {

      mensajesExitosos++;

      bytesTotal +=
        bytesMensaje;
    }


    // ----------------------------------
    // CSV DE CADA MENSAJE
    // ----------------------------------

    Serial.print(
      "CSV,"
    );

    Serial.print(
      mensajesIntentados
    );

    Serial.print(",");

    Serial.print(
      periodo / 1000
    );

    Serial.print(",");

    Serial.print(
      WiFi.RSSI()
    );

    Serial.print(",");

    Serial.print(
      enviado ? 1 : 0
    );

    Serial.print(",");

    Serial.println(
      bytesMensaje
    );


    Serial.print(
      "Progreso: "
    );

    Serial.print(
      mensajesIntentados
    );

    Serial.print(
      "/"
    );

    Serial.println(
      NUM_PRUEBAS
    );
  }


  // ----------------------------------------------------
  // TERMINO UNA CONDICION
  // ----------------------------------------------------

  if (
    mensajesIntentados >=
    NUM_PRUEBAS
  ) {

    finalizarCondicion();

    condicionActual++;


    // ==================================================
    // TERMINAR TODO
    // ==================================================

    if (
      condicionActual >=
      NUM_CONDICIONES
    ) {

      experimentoTerminado =
        true;

      Serial.println();
      Serial.println(
        "======================================"
      );

      Serial.println(
        " EXPERIMENTO D TERMINADO"
      );

      Serial.println(
        "======================================"
      );

      Serial.println(
        "Se completaron:"
      );

      Serial.println(
        "30 mensajes a 1 s"
      );

      Serial.println(
        "30 mensajes a 5 s"
      );

      Serial.println(
        "30 mensajes a 10 s"
      );

      return;
    }


    // Pausa entre condiciones

    Serial.println();
    Serial.println(
      "Esperando 3 segundos..."
    );

    delay(3000);

    iniciarCondicion();
  }

  delay(10);
}