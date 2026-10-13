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
// AGREGADO: RELE Y LEDS - tarea independiente del envio de datos.
#include <freertos/FreeRTOS.h>
#include <freertos/task.h>

#include <HTTPClient.h>
#include <time.h>

// =======================================================
// 1. CONFIGURACION
// =======================================================

const char* NODE_ID = "ESP32_LUZ_01";

// ---------------- PIR ----------------

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


// ---------------- WIFI ----------------

// USA EXACTAMENTE EL MISMO WIFI
// QUE TE FUNCIONO CON MQTT

const char* ssid = "CONFIGURAR_EN_EQUIPO_LOCAL";
const char* password = "CONFIGURAR_EN_EQUIPO_LOCAL";

// ---------------- HTTP ----------------

// IP actual de tu PC

const char* HTTP_URL =
  "http://192.168.1.40:5000/telemetry";

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

// AGREGADO: comentario original conservado; ahora SI hay rele fisico.
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

  // AGREGADO: comentario original conservado; ahora SI hay rele fisico.
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

  // AGREGADO: RELE Y LEDS - capturar la ultima orden fisica completa.
  capturarActuadoresParaJson(estadoPIR, estadoLuz);

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

  // AGREGADO: RELE Y LEDS
  iniciarActuadores();


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

  // AGREGADO: RELE Y LEDS - antes de cualquier return del loop.
  mostrarActuadores();


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