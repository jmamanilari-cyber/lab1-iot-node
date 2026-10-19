# ROADMAP — Laboratorio 2: backend multinodo

Actualizado: 2026-10-18. Producto: https://github.com/jmamanilari-cyber/lab1-iot-node

## Objetivo
Plataforma PHP/MariaDB para tres ESP32, telemetría HTTP y MQTT, autenticación, idempotencia, pruebas y evidencia real. No modificar Laboratorio 1 ni incluir funcionalidades del Laboratorio 3.

| Etapa | Estado | Evidencia o pendiente |
|---|---|---|
| A. Diseño | Completa | Contratos, arquitectura y estructura `lab2/` |
| B. MariaDB/PDO | Verificada y publicada | Cuatro tablas, tres identidades, conexión PDO/UTC; commit `e94abd4` |
| C1. Telemetría HTTP | Verificada y publicada | T01–T04, autenticación, idempotencia y concurrencia; `e0069b3` |
| C2. API REST restante | Verificada y publicada | Cuatro endpoints, filtros, paginación, autorización; `fc49604` |
| D1. MQTT local seguro | Verificada en loopback y publicada | Broker aislado 127.0.0.1:1884, ACL, subscriber PHP y MariaDB; `c26627d` |
| D2. MQTT por LAN | Pendiente | Broker accesible y autenticado para ESP32 físicos |
| E0. Diagnóstico hardware | En curso | Relé ya funciona; PIR requiere calibración; placas 2 y 3 sin verificar |
| E1. Firmware de un nodo | Pendiente | PIR/relé/LED, secuencias resistentes a reinicio, HTTP/MQTT |
| E2. Tres nodos físicos | Pendiente | Tres ESP32 comprobados y telemetría concurrente |
| F. Pruebas y carga | Parcial | Faltan T05–T08 completos, throughput, p50/p95 y evidencia |
| G. Documentación/entrega | Parcial | Diagramas, contratos, evidencias y conclusiones |

## Punto verificado de software
- REST: POST `/api/v1/telemetry`, GET `/api/v1/devices`, GET `/api/v1/devices/{id}/telemetry`, PUT `/api/v1/devices/{id}/config`, GET `/api/v1/events`.
- HTTP: 201 nuevo, 200 duplicado, 409 conflicto; 400/401/403/404/422 según caso. Regresión de C1 tras C2 pasó.
- MQTT: D1 integra el **mismo `TelemetryService`**; pruebas de cuentas/ACL e ingesta. Ver `lab2/docs/MQTT_D1.md`.
- Límites D1: broker 1884 solo loopback, 1883 sin seguridad confirmada; `mosquitto_sub` puede confirmar MQTT antes del commit MariaDB, por lo que no se garantiza pérdida cero ante interrupciones bruscas.
- La base `lab2` existe: **no repetir** `schema.sql` o `seed.sql`.

## E0 — Hardware, siguiente trabajo
- Hay tres ESP32, pero solo una NodeMCU ESP-32S V1.1 de 38 pines ha sido verificada físicamente. Las otras dos placas no tienen componentes y no se ha comprobado si funcionan.
- Relé JQC-3FF-S-Z de 5 V activo en LOW: GPIO26 directo lo activaba pero 3,3 V HIGH no lo desactivaba. **Resuelto en prueba aislada** con BC548 NPN + resistencia 1 kΩ en la base. GPIO26→resistencia→base; emisor→GND; colector→IN; relé VCC→5V, GND común. El usuario confirmó clics y conmutación con el código HIGH/LOW; terminales de tornillo sin carga.
- PIR tipo HC-SR501/BISS0001: pines GND/OUT/VCC identificados; OUT→GPIO27; detección básica confirmada por el usuario con videos. **Sensibilidad y alcance excesivos** según observación; aún no se ha cuantificado alcance, duración ni falsas detecciones. LED externos pendientes de reconectar.
- Próxima sesión: identificar ajustes de sensibilidad y tiempo/jumper, modificar un ajuste por vez, esperar estabilización, registrar detecciones a distancias conocidas y tiempo de retorno a LOW; luego unir PIR+relé+LED y comprobar las otras dos placas.

## E1–G — Criterios pendientes
- Firmware nuevo **solo en `lab2/firmware/`**, sin sobrescribir Lab1. `node_id` autenticado, `seq` único tras reinicio (sin escrituras de flash por muestra), reintentos con igual contenido, `timestamp` Unix UTC o `null`, `received_at` del servidor, PIR `value` 0/1 y RSSI.
- Probar un nodo real HTTP/MQTT vía LAN; luego tres nodos y T05–T08, carga con throughput/p50/p95 y evidencias observadas, no simuladas como si fueran físicas.
- Documentar límites técnicos, diagramas y conclusiones. Mantener credenciales y colas privadas fuera de Git.

## Reglas de continuidad
La realidad verificada del código, el hardware y las pruebas prevalece sobre este documento. Revisar `git status`, los commits y la etapa pertinente antes de trabajar. No repetir auditorías; encargos breves a Codex. Este roadmap vive en el repositorio de producto; el checkpoint operativo en el repositorio de workflow.
