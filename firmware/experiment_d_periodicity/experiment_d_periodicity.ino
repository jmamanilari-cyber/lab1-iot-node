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
#include <PubSubClient.h>
#include <time.h>

// =======================================================
// 1. CONFIGURACION
// =======================================================

const char* NODE_ID = "ESP32_LUZ_01";

const int PIR_PIN = 27;

// -------------------------------------------------------
// WIFI
// Copia EXACTAMENTE los valores del firmware que funciono
// -------------------------------------------------------

const char* ssid = "TU_SSID";
const char* password = "TU_CLAVE_WIFI";

// -------------------------------------------------------
// MQTT
// -------------------------------------------------------

const char* MQTT_BROKER = "TU_IP_BROKER";
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

  json += "}";

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