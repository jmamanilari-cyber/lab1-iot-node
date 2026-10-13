/*
===========================================================
 LAB 1 - SOFTWARE DE TELECOMUNICACIONES
 Sistema IoT de Control de Iluminacion

 EXPERIMENTO C
 CORTE Y RECUPERACION DE WI-FI

 Nodo:
 ESP32_LUZ_01

 PIR:
 OUT -> GPIO 27

 OBJETIVO:
 - Detectar perdida de Wi-Fi
 - Intentar reconexion
 - Medir tiempo de recuperacion
 - Repetir 5 veces
 - Calcular promedio, minimo y maximo
===========================================================
*/

#include <WiFi.h>
// AGREGADO: RELE Y LEDS - tarea independiente del envio de datos.
#include <freertos/FreeRTOS.h>
#include <freertos/task.h>


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
// Usa EXACTAMENTE los mismos datos que ya te funcionaron
// -------------------------------------------------------

const char* ssid = "CONFIGURAR_EN_EQUIPO_LOCAL";
const char* password = "CONFIGURAR_EN_EQUIPO_LOCAL";

// -------------------------------------------------------
// EXPERIMENTO
// -------------------------------------------------------

const int NUM_PRUEBAS = 5;

// Intentar reconectar cada 2 segundos
const unsigned long INTERVALO_RECONEXION_MS = 2000;


// =======================================================
// 2. VARIABLES PIR
// =======================================================

int estadoPIR = 0;
int estadoAnteriorPIR = 0;

unsigned long numeroDetecciones = 0;


// =======================================================
// 3. VARIABLES WI-FI
// =======================================================

bool wifiAnterior = false;

bool enCorte = false;

unsigned long inicioCorte = 0;
unsigned long ultimoIntentoReconexion = 0;


// =======================================================
// 4. VARIABLES EXPERIMENTO
// =======================================================

int pruebaActual = 0;

unsigned long tiemposRecuperacion[NUM_PRUEBAS];

bool experimentoTerminado = false;


// =======================================================
// 5. CONECTAR WI-FI INICIALMENTE
// =======================================================

bool conectarWiFiInicial() {

  Serial.println();
  Serial.println("====================================");
  Serial.println(" CONEXION INICIAL WI-FI");
  Serial.println("====================================");

  Serial.print("SSID: ");
  Serial.println(ssid);

  WiFi.mode(WIFI_STA);

  WiFi.setAutoReconnect(true);
  WiFi.persistent(false);

  WiFi.begin(
    ssid,
    password
  );

  Serial.print("Conectando");

  unsigned long inicio = millis();

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

    Serial.println();
    Serial.println(
      ">>> WI-FI CONECTADO CORRECTAMENTE"
    );

    Serial.print("IP ESP32: ");
    Serial.println(WiFi.localIP());

    Serial.print("Gateway: ");
    Serial.println(WiFi.gatewayIP());

    Serial.print("RSSI: ");
    Serial.print(WiFi.RSSI());
    Serial.println(" dBm");

    return true;
  }

  Serial.println();
  Serial.println(
    "ERROR: no se pudo conectar inicialmente."
  );

  return false;
}


// =======================================================
// 6. PIR
// =======================================================

void procesarPIR() {

  estadoPIR =
    digitalRead(PIR_PIN);

  if (
    estadoPIR != estadoAnteriorPIR
  ) {

    if (
      estadoPIR == HIGH
    ) {

      numeroDetecciones++;

      Serial.print(
        "PIR = 1 | PRESENCIA DETECTADA | #"
      );

      Serial.println(
        numeroDetecciones
      );

    } else {

      Serial.println(
        "PIR = 0 | SIN PRESENCIA"
      );
    }

    estadoAnteriorPIR =
      estadoPIR;
  }
}


// =======================================================
// 7. INTENTAR RECONEXION
// =======================================================

void intentarReconexion() {

  if (
    millis() - ultimoIntentoReconexion
    < INTERVALO_RECONEXION_MS
  ) {

    return;
  }

  ultimoIntentoReconexion =
    millis();

  Serial.println(
    "Intentando reconectar Wi-Fi..."
  );

  WiFi.reconnect();
}


// =======================================================
// 8. MONITOREAR WI-FI
// =======================================================

void monitorearWiFi() {

  bool wifiActual =
    WiFi.status() == WL_CONNECTED;


  // =====================================================
  // SE PERDIO LA CONEXION
  // =====================================================

  if (
    wifiAnterior &&
    !wifiActual &&
    !enCorte &&
    !experimentoTerminado
  ) {

    enCorte = true;

    inicioCorte =
      millis();

    Serial.println();
    Serial.println(
      "===================================="
    );

    Serial.print(
      " CORTE WI-FI DETECTADO - PRUEBA "
    );

    Serial.println(
      pruebaActual + 1
    );

    Serial.println(
      "===================================="
    );

    Serial.print(
      "Inicio corte [ms]: "
    );

    Serial.println(
      inicioCorte
    );
  }


  // =====================================================
  // MIENTRAS SIGUE DESCONECTADO
  // =====================================================

  if (
    !wifiActual &&
    enCorte
  ) {

    intentarReconexion();
  }


  // =====================================================
  // WI-FI RECUPERADO
  // =====================================================

  if (
    !wifiAnterior &&
    wifiActual &&
    enCorte &&
    !experimentoTerminado
  ) {

    unsigned long finCorte =
      millis();

    unsigned long tiempoRecuperacion =
      finCorte - inicioCorte;

    tiemposRecuperacion[pruebaActual] =
      tiempoRecuperacion;

    pruebaActual++;

    enCorte = false;

    Serial.println();
    Serial.println(
      ">>> WI-FI RECUPERADO"
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

    Serial.print(
      "Tiempo de recuperacion: "
    );

    Serial.print(
      tiempoRecuperacion
    );

    Serial.println(
      " ms"
    );


    // ---------------------------------------------------
    // FILA CSV
    // ---------------------------------------------------

    Serial.print(
      "CSV,"
    );

    Serial.print(
      pruebaActual
    );

    Serial.print(
      ",RECUPERACION_WIFI,"
    );

    Serial.println(
      tiempoRecuperacion
    );


    Serial.print(
      "Progreso: "
    );

    Serial.print(
      pruebaActual
    );

    Serial.print(
      "/"
    );

    Serial.println(
      NUM_PRUEBAS
    );


    // ---------------------------------------------------
    // FINAL
    // ---------------------------------------------------

    if (
      pruebaActual >= NUM_PRUEBAS
    ) {

      experimentoTerminado =
        true;
    }
  }


  wifiAnterior =
    wifiActual;
}


// =======================================================
// 9. RESULTADOS FINALES
// =======================================================

void mostrarResultadosFinales() {

  unsigned long suma = 0;

  unsigned long minimo =
    tiemposRecuperacion[0];

  unsigned long maximo =
    tiemposRecuperacion[0];


  for (
    int i = 0;
    i < NUM_PRUEBAS;
    i++
  ) {

    suma +=
      tiemposRecuperacion[i];

    if (
      tiemposRecuperacion[i] < minimo
    ) {

      minimo =
        tiemposRecuperacion[i];
    }

    if (
      tiemposRecuperacion[i] > maximo
    ) {

      maximo =
        tiemposRecuperacion[i];
    }
  }


  float promedio =
    (float)suma /
    NUM_PRUEBAS;


  Serial.println();
  Serial.println(
    "========================================"
  );

  Serial.println(
    " EXPERIMENTO C TERMINADO"
  );

  Serial.println(
    "========================================"
  );

  Serial.print(
    "Numero de cortes: "
  );

  Serial.println(
    NUM_PRUEBAS
  );

  Serial.print(
    "Tiempo promedio: "
  );

  Serial.print(
    promedio,
    2
  );

  Serial.println(
    " ms"
  );

  Serial.print(
    "Tiempo minimo: "
  );

  Serial.print(
    minimo
  );

  Serial.println(
    " ms"
  );

  Serial.print(
    "Tiempo maximo: "
  );

  Serial.print(
    maximo
  );

  Serial.println(
    " ms"
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


  delay(1000);

  pinMode(
    PIR_PIN,
    INPUT
  );

  estadoAnteriorPIR =
    digitalRead(PIR_PIN);


  Serial.println();
  Serial.println(
    "========================================"
  );

  Serial.println(
    " EXPERIMENTO C"
  );

  Serial.println(
    " CORTE Y RECUPERACION WI-FI"
  );

  Serial.println(
    "========================================"
  );

  Serial.println(
    "Se realizaran 5 cortes de Wi-Fi."
  );

  Serial.println();


  bool conectado =
    conectarWiFiInicial();


  if (conectado) {

    wifiAnterior = true;

    Serial.println();
    Serial.println(
      "LISTO PARA LA PRUEBA."
    );

    Serial.println(
      "Ahora corta temporalmente el Wi-Fi."
    );
  }
}


// =======================================================
// 11. LOOP
// =======================================================

void loop() {

  // AGREGADO: RELE Y LEDS - antes de cualquier return del loop.
  mostrarActuadores();


  procesarPIR();

  monitorearWiFi();


  // Mostrar resultado solo una vez

  static bool resultadoMostrado =
    false;

  if (
    experimentoTerminado &&
    !resultadoMostrado
  ) {

    resultadoMostrado =
      true;

    mostrarResultadosFinales();
  }

  delay(50);
}