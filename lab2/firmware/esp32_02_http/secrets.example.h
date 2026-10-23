#pragma once
// Copiar a secrets.h (ignorado). Nunca versionar valores reales.
constexpr char WIFI_SSID[] = "REEMPLAZAR";
constexpr char WIFI_PASSWORD[] = "REEMPLAZAR";
// Destino TCP 192.168.0.3:8443 y nombre TLS telemetry.lab.test fijados en el sketch.
// Token hexadecimal de 64 caracteres perteneciente SOLO a esp32-02.
constexpr char DEVICE_TOKEN[] = "REEMPLAZAR";
// CA publica de confianza, NO certificado/clave privada del servidor.
constexpr char TLS_ROOT_CA[] = R"PEM(REEMPLAZAR_POR_CA_PEM)PEM";
// Solo al estrenar NVS: elegir > MAX(seq) existente para esp32-02.
constexpr uint64_t INITIAL_SEQUENCE = 0;
