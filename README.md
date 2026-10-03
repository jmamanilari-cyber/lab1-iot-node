# Laboratorio 1 - Nodo IoT ESP32_LUZ_01

Repositorio preparado para documentar el laboratorio de Software de Telecomunicaciones. El nodo ESP32 adquiere el estado de un sensor PIR, construye telemetría JSON y evalúa transporte HTTP/MQTT, calidad de enlace Wi-Fi, reconexión y periodicidad de publicación.

## Estructura

- `firmware/experiment_a_http/`: Experimento A, transporte HTTP.
- `firmware/experiment_b_rssi/`: Experimento B, RSSI CERCA/LEJOS mediante MQTT.
- `firmware/experiment_c_wifi_recovery/`: Experimento C, corte y recuperación de Wi-Fi.
- `firmware/experiment_d_periodicity/`: Experimento D, intervalos de 1 s, 5 s y 10 s.
- `services/receptor_http.py`: receptor HTTP local para `/telemetry`.
- `services/mqtt_ack.py`: receptor MQTT y publicación del ACK de aplicación.
- `broker/mosquitto.conf`: configuración utilizada con Mosquitto.
- `data/`: mediciones CSV de los experimentos.
- `docs/`: informe y diagramas de arquitectura.

## Configuración antes de ejecutar

Los archivos preparados para repositorio no incluyen credenciales Wi-Fi reales. Antes de compilar o ejecutar, configure en su copia local:

- `TU_SSID`
- `TU_CLAVE_WIFI`
- `TU_IP_PC` para el receptor HTTP
- `TU_IP_BROKER` para MQTT

No publique contraseñas o credenciales reales en un repositorio remoto.

## Topics MQTT

- Telemetría: `telecom/lab1/ESP32_LUZ_01/telemetry`
- ACK: `telecom/lab1/ESP32_LUZ_01/ack`

## Resultados principales

- HTTP: 30/30 mensajes; latencia promedio 123.37 ms.
- MQTT + ACK: 30/30 mensajes; latencia promedio 15.60 ms.
- RSSI promedio: CERCA -3.50 dBm; LEJOS -66.83 dBm; sin pérdidas en las pruebas realizadas.
- Recuperación Wi-Fi: 3.43 s a 6.55 s; promedio aproximado 4.45 s.
- Periodicidad: 59.88, 11.99 y 6.00 mensajes/min para 1 s, 5 s y 10 s.

## Evidencias todavía necesarias

Para la entrega académica se deben añadir capturas reales de ejecución: broker Mosquitto, receptor HTTP, receptor MQTT, monitor serie del ESP32 y el historial del repositorio remoto.
