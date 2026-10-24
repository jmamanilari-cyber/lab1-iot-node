#pragma once
#include <stdint.h>
// Copiar SOLO este ejemplo a secrets.h en esta carpeta (ignorado por Git).
// Completar localmente; nunca versionar ni imprimir los valores privados.
constexpr char WIFI_SSID[] = "REEMPLAZAR";
constexpr char WIFI_PASSWORD[] = "REEMPLAZAR";
// Token hexadecimal de 64 caracteres asignado SOLO a esp32-01.
// No reutilizar el token de esp32-02.
constexpr char DEVICE_TOKEN[] = "REEMPLAZAR";
// CA publica que valida telemetry.lab.test; nunca una clave privada.
// UDP descubre la IP; HTTPS usa siempre telemetry.lab.test:8443.
constexpr char TLS_ROOT_CA[] = R"PEM(REEMPLAZAR_POR_CA_PEM)PEM";
// Solo para NVS nueva: elegir un valor > MAX(seq) existente de esp32-01.
// Si no existen registros, puede comenzar en 0. No borra ni reemplaza pendientes.
constexpr uint64_t INITIAL_SEQUENCE = 0;
