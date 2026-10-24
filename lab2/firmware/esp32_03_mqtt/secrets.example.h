#pragma once
#include <stdint.h>
// Copiar SOLO este ejemplo a secrets.h en esta carpeta (ignorado por Git).
// Completar manualmente sin copiar archivos privados del firmware HTTP.
constexpr char WIFI_SSID[] = "REEMPLAZAR";
constexpr char WIFI_PASSWORD[] = "REEMPLAZAR";
constexpr char MQTT_USERNAME[] = "esp32-03";
// Password MQTT del usuario esp32-03 de D1/D2; NO es DEVICE_TOKEN HTTP.
// Nunca usar el token HTTP como password MQTT ni imprimir credenciales.
constexpr char MQTT_PASSWORD[] = "REEMPLAZAR";
// CA publica de Lab2TLS que valida telemetry.lab.test; nunca una clave privada.
constexpr char TLS_ROOT_CA[] = R"PEM(REEMPLAZAR_POR_CA_PEM)PEM";
// Se IGNORA si existe NVS HTTPS #3: mismo namespace, blob, magic y secuencias.
// Solo para NVS realmente nueva: elegir > MAX(seq) de esp32-03, o 0 sin registros.
constexpr uint64_t INITIAL_SEQUENCE = 0;
