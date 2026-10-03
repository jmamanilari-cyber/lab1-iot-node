/*
===========================================================
 LAB 1 - SOFTWARE DE TELECOMUNICACIONES
 Sistema IoT de Control de Iluminacion

 EXPERIMENTO A - HTTP
 30 TRANSMISIONES

 Nodo:
 ESP32_LUZ_01

 PIR:
 OUT -> GPIO 27

 Servidor HTTP:
 PC = 192.168.1.40
 Puerto = 5000
 Ruta = /telemetry

 Se registra:
 - Prueba
 - Protocolo
 - RSSI
 - Latencia HTTP
 - Exito
 - Bytes

 Periodo:
 1 mensaje cada 5 segundos
===========================================================
*/

#include <WiFi.h>
#include <HTTPClient.h>
#include <time.h>

// =======================================================
// 1. CONFIGURACION
// =======================================================

const char* NODE_ID = "ESP32_LUZ_01";

// ---------------- PIR ----------------

const int PIR_PIN = 27;

// ---------------- WIFI ----------------

// USA EXACTAMENTE EL MISMO WIFI
// QUE TE FUNCIONO CON MQTT

const char* ssid = "TU_SSID";
const char* password = "TU_CLAVE_WIFI";

// ---------------- HTTP ----------------

// IP actual de tu PC

const char* HTTP_URL =
  "http://TU_IP_PC:5000/telemetry";

// ---------------- PRUEBAS ----------------

const int NUM_PRUEBAS_HTTP = 30;

// 5 segundos

const unsigned long PERIODO_ENVIO_MS = 5000;

// Reintento Wi-Fi cada 15 segundos

const unsigned long PERIODO_REINTENTO_WIFI = 15000;


// =======================================================
// 2. VARIABLES
// =======================================================

// PIR

int estadoPIR = 0;
int estadoAnteriorPIR = 0;

// Aun no hay rele.
// Este es el estado LOGICO de la luz.

int estadoLuz = 0;

unsigned long numeroDetecciones = 0;

// JSON

unsigned long secuencia = 0;

// Temporizadores

unsigned long ultimoEnvio = 0;
unsigned long ultimoReintentoWiFi = 0;

// Experimento

int pruebasHTTP = 0;
int exitosHTTP = 0;

bool experimentoTerminado = false;


// =======================================================
// 3. CONECTAR WIFI
// =======================================================

bool conectarWiFi() {

  if (
    WiFi.status() ==
    WL_CONNECTED
  ) {

    return true;
  }

  Serial.println();
  Serial.println(
    "===================================="
  );

  Serial.println(
    " CONEXION WI-FI"
  );

  Serial.println(
    "===================================="
  );

  Serial.print(
    "SSID: "
  );

  Serial.println(
    ssid
  );

  // Detener intento anterior

  WiFi.disconnect(
    false,
    false
  );

  delay(500);

  WiFi.mode(
    WIFI_STA
  );

  Serial.println(
    "Iniciando conexion..."
  );

  WiFi.begin(
    ssid,
    password
  );

  unsigned long inicio =
    millis();

  // Esperar maximo 20 segundos

  while (
    WiFi.status() != WL_CONNECTED &&
    millis() - inicio < 20000
  ) {

    Serial.print(".");
    delay(500);
  }

  Serial.println();

  // ---------------- CONECTADO ----------------

  if (
    WiFi.status() ==
    WL_CONNECTED
  ) {

    Serial.println();
    Serial.println(
      ">>> WI-FI CONECTADO CORRECTAMENTE"
    );

    Serial.print(
      "IP ESP32: "
    );

    Serial.println(
      WiFi.localIP()
    );

    Serial.print(
      "Gateway: "
    );

    Serial.println(
      WiFi.gatewayIP()
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

    // Hora NTP

    configTime(
      0,
      0,
      "pool.ntp.org",
      "time.nist.gov"
    );

    return true;
  }

  // ---------------- ERROR ----------------

  Serial.println();
  Serial.println(
    "ERROR: no se pudo conectar al Wi-Fi"
  );

  Serial.print(
    "WiFi.status() = "
  );

  Serial.println(
    WiFi.status()
  );

  return false;
}


// =======================================================
// 4. MANTENER WIFI
// =======================================================

void mantenerWiFi() {

  if (
    WiFi.status() ==
    WL_CONNECTED
  ) {

    return;
  }

  if (
    millis() - ultimoReintentoWiFi
    >= PERIODO_REINTENTO_WIFI
  ) {

    ultimoReintentoWiFi =
      millis();

    Serial.println();
    Serial.println(
      "Wi-Fi desconectado."
    );

    Serial.println(
      "Nuevo intento..."
    );

    conectarWiFi();
  }
}


// =======================================================
// 5. TIMESTAMP
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
// 6. PROCESAR PIR
// =======================================================

void procesarPIR() {

  estadoPIR =
    digitalRead(
      PIR_PIN
    );

  // Mientras no tenemos rele:
  // PIR = 1 -> luz logica = 1
  // PIR = 0 -> luz logica = 0

  estadoLuz =
    estadoPIR;

  // Mostrar solo cuando cambia

  if (
    estadoPIR !=
    estadoAnteriorPIR
  ) {

    Serial.println();

    if (
      estadoPIR == HIGH
    ) {

      numeroDetecciones++;

      Serial.print(
        "PIR = 1"
      );

      Serial.print(
        " | PRESENCIA DETECTADA"
      );

      Serial.print(
        " | Deteccion #"
      );

      Serial.print(
        numeroDetecciones
      );

    } else {

      Serial.print(
        "PIR = 0"
      );

      Serial.print(
        " | SIN PRESENCIA"
      );
    }

    if (
      WiFi.status() ==
      WL_CONNECTED
    ) {

      Serial.print(
        " | RSSI = "
      );

      Serial.print(
        WiFi.RSSI()
      );

      Serial.print(
        " dBm"
      );
    }

    Serial.println();

    estadoAnteriorPIR =
      estadoPIR;
  }
}


// =======================================================
// 7. CREAR JSON
// =======================================================

String crearJson() {

  secuencia++;

  int rssi = 0;

  if (
    WiFi.status() ==
    WL_CONNECTED
  ) {

    rssi =
      WiFi.RSSI();
  }

  String json;

  json.reserve(220);

  json += "{";

  // node_id

  json += "\"node_id\":\"";
  json += NODE_ID;
  json += "\",";

  // seq

  json += "\"seq\":";
  json += secuencia;
  json += ",";

  // timestamp

  json += "\"timestamp\":";
  json += obtenerTimestamp();
  json += ",";

  // PIR

  json += "\"value\":";
  json += estadoPIR;
  json += ",";

  // Luz

  json += "\"luz\":";
  json += estadoLuz;
  json += ",";

  // RSSI

  json += "\"rssi\":";
  json += rssi;
  json += ",";

  // Uptime

  json += "\"uptime_ms\":";
  json += millis();

  json += "}";

  return json;
}


// =======================================================
// 8. ENVIAR HTTP
// =======================================================

bool enviarHttp(
  const String& mensaje,
  unsigned long& latencia
) {

  latencia = 0;

  if (
    WiFi.status() !=
    WL_CONNECTED
  ) {

    Serial.println(
      "HTTP = SIN WIFI"
    );

    return false;
  }

  HTTPClient http;

  // Servidor

  http.begin(
    HTTP_URL
  );

  // JSON

  http.addHeader(
    "Content-Type",
    "application/json"
  );

  // Timeout máximo

  http.setTimeout(
    3000
  );

  // -----------------------------------
  // MEDIR LATENCIA
  // -----------------------------------

  unsigned long inicio =
    millis();

  int codigoHttp =
    http.POST(
      mensaje
    );

  latencia =
    millis() - inicio;

  // -----------------------------------
  // RESULTADO
  // -----------------------------------

  Serial.print(
    "Codigo HTTP: "
  );

  Serial.println(
    codigoHttp
  );

  Serial.print(
    "Latencia HTTP: "
  );

  Serial.print(
    latencia
  );

  Serial.println(
    " ms"
  );

  bool correcto = false;

  if (
    codigoHttp > 0
  ) {

    String respuesta =
      http.getString();

    Serial.print(
      "Respuesta servidor: "
    );

    Serial.println(
      respuesta
    );

    // HTTP 200-299

    if (
      codigoHttp >= 200 &&
      codigoHttp < 300
    ) {

      correcto = true;
    }

  } else {

    Serial.println(
      "No se pudo contactar al servidor."
    );
  }

  http.end();

  return correcto;
}


// =======================================================
// 9. RESULTADO FINAL
// =======================================================

void mostrarResultadoFinal() {

  float porcentajeEntrega =

    (
      (float)exitosHTTP /
      (float)NUM_PRUEBAS_HTTP
    )

    * 100.0;

  Serial.println();
  Serial.println(
    "========================================"
  );

  Serial.println(
    " EXPERIMENTO HTTP TERMINADO"
  );

  Serial.println(
    "========================================"
  );

  Serial.print(
    "Mensajes enviados: "
  );

  Serial.println(
    NUM_PRUEBAS_HTTP
  );

  Serial.print(
    "Mensajes exitosos: "
  );

  Serial.println(
    exitosHTTP
  );

  Serial.print(
    "Mensajes fallidos: "
  );

  Serial.println(
    NUM_PRUEBAS_HTTP -
    exitosHTTP
  );

  Serial.print(
    "Porcentaje de entrega: "
  );

  Serial.print(
    porcentajeEntrega,
    2
  );

  Serial.println(
    " %"
  );

  Serial.println();
  Serial.println(
    "No se enviaran mas mensajes HTTP."
  );

  Serial.println(
    "========================================"
  );
}


// =======================================================
// 10. SETUP
// =======================================================

void setup() {

  Serial.begin(
    115200
  );

  delay(1500);

  // ---------------- PIR ----------------

  pinMode(
    PIR_PIN,
    INPUT
  );

  estadoPIR =
    digitalRead(
      PIR_PIN
    );

  estadoAnteriorPIR =
    estadoPIR;

  estadoLuz =
    estadoPIR;

  // ---------------- INICIO ----------------

  Serial.println();
  Serial.println(
    "========================================"
  );

  Serial.println(
    " SISTEMA IoT DE CONTROL DE ILUMINACION"
  );

  Serial.println(
    " EXPERIMENTO A - HTTP"
  );

  Serial.println(
    "========================================"
  );

  Serial.print(
    "Nodo: "
  );

  Serial.println(
    NODE_ID
  );

  Serial.print(
    "Servidor: "
  );

  Serial.println(
    HTTP_URL
  );

  Serial.print(
    "Cantidad pruebas: "
  );

  Serial.println(
    NUM_PRUEBAS_HTTP
  );

  Serial.print(
    "Periodo: "
  );

  Serial.print(
    PERIODO_ENVIO_MS / 1000
  );

  Serial.println(
    " segundos"
  );

  // ---------------- WIFI ----------------

  conectarWiFi();

  // ---------------- CSV ----------------

  Serial.println();
  Serial.println(
    "Prueba,Protocolo,RSSI_dBm,Latencia_ms,Exito,Bytes"
  );

  Serial.println();
}


// =======================================================
// 11. LOOP
// =======================================================

void loop() {

  // =====================================================
  // WIFI
  // =====================================================

  mantenerWiFi();


  // =====================================================
  // PIR
  // =====================================================

  procesarPIR();


  // =====================================================
  // EXPERIMENTO HTTP
  //
  // Solo ejecuta si:
  // - Wi-Fi conectado
  // - No termino
  // - Pasaron 5 segundos
  // =====================================================

  if (
    !experimentoTerminado &&

    WiFi.status() ==
    WL_CONNECTED &&

    millis() - ultimoEnvio
    >= PERIODO_ENVIO_MS
  ) {

    ultimoEnvio =
      millis();


    // -----------------------------------
    // CREAR JSON
    // -----------------------------------

    String mensaje =
      crearJson();

    int bytesMensaje =
      mensaje.length();

    int rssiPrueba =
      WiFi.RSSI();


    // -----------------------------------
    // MOSTRAR JSON
    // -----------------------------------

    Serial.println();
    Serial.println(
      "========== MENSAJE JSON =========="
    );

    Serial.println(
      mensaje
    );

    Serial.println(
      "=================================="
    );


    // -----------------------------------
    // ENVIAR HTTP
    // -----------------------------------

    unsigned long latenciaHttp = 0;

    bool enviado =
      enviarHttp(
        mensaje,
        latenciaHttp
      );


    // -----------------------------------
    // CONTADORES
    // -----------------------------------

    pruebasHTTP++;

    if (
      enviado
    ) {

      exitosHTTP++;
    }


    // -----------------------------------
    // RESULTADO
    // -----------------------------------

    if (
      enviado
    ) {

      Serial.println(
        "HTTP = EXITO"
      );

    } else {

      Serial.println(
        "HTTP = FALLO"
      );
    }


    // -----------------------------------
    // CSV
    // -----------------------------------

    Serial.print(
      "CSV,"
    );

    // Prueba

    Serial.print(
      pruebasHTTP
    );

    Serial.print(",");

    // Protocolo

    Serial.print(
      "HTTP"
    );

    Serial.print(",");

    // RSSI

    Serial.print(
      rssiPrueba
    );

    Serial.print(",");

    // Latencia

    Serial.print(
      latenciaHttp
    );

    Serial.print(",");

    // Exito

    Serial.print(
      enviado ? 1 : 0
    );

    Serial.print(",");

    // Bytes

    Serial.println(
      bytesMensaje
    );


    // -----------------------------------
    // PROGRESO
    // -----------------------------------

    Serial.print(
      "Progreso: "
    );

    Serial.print(
      pruebasHTTP
    );

    Serial.print(
      "/"
    );

    Serial.println(
      NUM_PRUEBAS_HTTP
    );


    // -----------------------------------
    // FINAL
    // -----------------------------------

    if (
      pruebasHTTP >=
      NUM_PRUEBAS_HTTP
    ) {

      experimentoTerminado =
        true;

      mostrarResultadoFinal();
    }
  }

  delay(50);
}