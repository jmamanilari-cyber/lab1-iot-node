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

// =======================================================
// 1. CONFIGURACION
// =======================================================

const char* NODE_ID = "ESP32_LUZ_01";

const int PIR_PIN = 27;

// -------------------------------------------------------
// WIFI
// Usa EXACTAMENTE los mismos datos que ya te funcionaron
// -------------------------------------------------------

const char* ssid = "TU_SSID";
const char* password = "TU_CLAVE_WIFI";

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