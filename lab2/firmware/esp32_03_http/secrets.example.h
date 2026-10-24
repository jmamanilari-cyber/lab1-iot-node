#pragma once
#include <stdint.h>
// Copiar SOLO este ejemplo a secrets.h en esta carpeta (ignorado por Git).
// Completar localmente; nunca versionar ni imprimir valores privados.
constexpr char WIFI_SSID[] = "REEMPLAZAR";
constexpr char WIFI_PASSWORD[] = "REEMPLAZAR";
// Token hexadecimal de 64 caracteres asignado exclusivamente a esp32-03.
// No reutilizar los tokens de esp32-01 ni esp32-02.
constexpr char DEVICE_TOKEN[] = "REEMPLAZAR";
// CA publica que valida telemetry.lab.test; nunca una clave privada.
// UDP descubre la IP; HTTPS conserva telemetry.lab.test:8443.
constexpr char TLS_ROOT_CA[] = R"PEM(REEMPLAZAR_POR_CA_PEM)PEM";
// Solo para NVS nueva: elegir un valor > MAX(seq) existente de esp32-03.
// Sin registros previos puede comenzar en 0. No reemplaza estados pendientes.
constexpr uint64_t INITIAL_SEQUENCE = 0;
