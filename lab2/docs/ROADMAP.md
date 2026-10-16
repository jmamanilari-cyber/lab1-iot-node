# ROADMAP — Laboratorio 2: plataforma backend multinodo

**Repositorio del producto:** https://github.com/jmamanilari-cyber/lab1-iot-node  
**Ubicación prevista en el producto:** `lab2/docs/ROADMAP.md`  
**Referencia principal:** `docs/Laboratior 2 guia.pdf`  
**Último checkpoint documentado:** octubre de 2026. Este documento describe el estado verificado en la conversación; antes de modificar código, contrastarlo con Git y con las pruebas locales.

## Objetivo y límites

Implementar y demostrar una plataforma backend para al menos **tres nodos ESP32**, con recepción de telemetría por **HTTP REST y MQTT**, un único servicio de dominio en **PHP**, persistencia mediante **PDO** en **MariaDB/MySQL**, autenticación, idempotencia y pruebas funcionales y de carga.

Los **cinco endpoints obligatorios** son:

| Método | Ruta |
|---|---|
| POST | `/api/v1/telemetry` |
| GET | `/api/v1/devices` |
| GET | `/api/v1/devices/{id}/telemetry` |
| PUT | `/api/v1/devices/{id}/config` |
| GET | `/api/v1/events` |

No incluir características de Edge Computing/Laboratorio 3, panel web o servicios adicionales salvo que la guía o una necesidad comprobada los exijan. El Laboratorio 1 es histórico: no modificarlo para corregir observaciones anteriores.

## Estado general

| Etapa | Estado | Criterio de salida |
|---|---|---|
| A. Diseño y preparación | **COMPLETE (diseño)** | Contratos y responsabilidades definidos; estructura `lab2/` creada |
| B. Esquema SQL y PDO | **COMPLETE (verificación local)** | Cuatro tablas, tres dispositivos y conexión PDO comprobados |
| C. Servicio de dominio, autenticación y REST | **NOT STARTED** | Cinco endpoints operativos, errores y duplicados comprobados |
| D. Entrada MQTT y reutilización del servicio | **NOT STARTED** | Mensajes MQTT llegan al mismo servicio y se persisten una sola vez |
| E. Firmware y tres nodos | **NOT STARTED** | Identidades/secuencias fiables; telemetría real de tres nodos |
| F. Pruebas, carga y observabilidad | **NOT STARTED** | T01–T08, throughput, latencias p50/p95 y evidencias reproducibles |
| G. Documentación y entrega | **NOT STARTED** | Contratos, diagramas, scripts, evidencias y conclusiones verificadas |

**Importante:** «COMPLETE (verificación local)» no significa «commit realizado y subido». El estado de Git posterior a la implementación no se ha confirmado en la conversación.

---

## A. Diseño y preparación — COMPLETE (diseño)

- [x] Analizar guía del Laboratorio 2 y acordar arquitectura Controller / Service / Repository / Subscriber.
- [x] Definir que REST y MQTT deben pasar por **el mismo `TelemetryService`**.
- [x] Elegir PHP 8.2 sin framework, PDO con consultas preparadas y MariaDB local con XAMPP.
- [x] Crear estructura aislada `lab2/{config,docs,firmware,public,sql,src}` y archivos básicos.
- [x] Establecer los cinco endpoints obligatorios y ocho pruebas objetivo.
- [ ] Confirmar en Git el commit de la etapa B y su eventual publicación remota; no darlo por realizado sin comprobar.

**Decisiones de contrato vigentes para implementar:**

- `node_id`: identidad aprovisionada, única por dispositivo.
- `seq`: entero no negativo y único por nodo; la base impone `UNIQUE(device_id,seq)`. Debe evitarse su reutilización después de reinicios; la persistencia se implementará al adaptar firmware.
- `timestamp`: **Unix UTC en segundos o `null`** si el dispositivo no está sincronizado. No inventar horas.
- `received_at`: tiempo UTC independiente asignado por el servidor.
- `value`: medición numérica; para PIR, solo `0` y `1` tras validación de dominio.
- `rssi`: entero de -127 a 0 dBm, o `null`.
- `extensions`: objeto JSON opcional documentado.
- Duplicado con misma identidad, secuencia y contenido: devolver el registro existente (REST **200** propuesto); misma clave con datos diferentes: **409**. Registrar nuevos mensajes: **201**.
- Autenticación REST por token de dispositivo vinculado a identidad; consultas globales/configuración con autorización administrativa mínima. Especificar controles de MQTT al implementarlo.

**Condición para cambiar una decisión:** nueva evidencia o incompatibilidad real; documentar el motivo antes de modificar código.

## B. Base de datos y conexión PDO — COMPLETE (verificación local)

**Archivos creados (reportados por Codex):**

- `lab2/sql/schema.sql`
- `lab2/sql/seed.sql`
- `lab2/src/Database.php`
- `lab2/config/database.example.php`
- `lab2/sql/check-connection.php`

Se actualizaron `lab2/README.md` y `lab2/.gitignore`.

**Verificación comunicada por el usuario:**

- MariaDB **10.4.32** respondió en XAMPP.
- `schema.sql` se importó **sin errores** y creó `devices`, `telemetry`, `configurations`, `events`.
- `seed.sql` insertó **3** nodos: `esp32-01`, `esp32-02`, `esp32-03`.
- `SHOW INDEX FROM telemetry` confirmó `uq_telemetry_device_seq`, índices `(device_id,ts)` y `(device_id,received_at)`.
- `C:\xampp\php\php.exe lab2\sql\check-connection.php` imprimió exactamente: `OK: conexion PDO, consulta preparada nativa y sesion UTC.`
- `git check-ignore` confirmó que `lab2/config/database.local.php` está ignorado por Git.
- Codex informó revisión de sintaxis satisfactoria de los archivos PHP; no se ha informado de pruebas automatizadas de API.

**Precauciones:** la base `lab2` ya existe, por lo que **no volver a ejecutar `schema.sql` ni `seed.sql`** indiscriminadamente; el seed no es idempotente. Los tres registros parten de `token_hash = NULL`, por lo que todavía no hay dispositivos autenticados. No subir contraseñas, credenciales o `database.local.php`.

**Límite de verificación:** no se ha probado REST, MQTT, escritura de telemetría ni concurrencia.

## C. Servicio de dominio, autenticación y API REST — NOT STARTED

**Objetivo:** recibir, validar, autenticar, deduplicar y consultar datos usando las tablas existentes.

- [ ] Confirmar línea base de Git; realizar un commit separado de etapa B si todavía está pendiente.
- [ ] Implementar `TelemetryService` reutilizable, con validación de dominio e idempotencia transaccional.
- [ ] Crear repositories PDO para las consultas necesarias; evitar SQL en los controladores.
- [ ] Aprovisionar **tokens activos de prueba** como hashes (los nodos de `seed.sql` no tienen token activo). No imprimir ni confirmar secretos en logs.
- [ ] Implementar autenticación/autorización y respuestas JSON uniformes con `ok`, `data`/`error`, `meta`.
- [ ] Implementar los cinco endpoints obligatorios y documentar payloads, validación y errores (400, 401, 403, 404, 409, 422, 500).
- [ ] Probar HTTP válido, token erróneo, JSON incompleto y duplicados; verificar efectos reales en MariaDB.

**Criterio de salida:** los cinco endpoints cumplen su contrato; una petición válida persiste una fila, una inválida no la inserta, y reintentos no generan duplicados. Evidencia de respuestas y consultas SQL.

**No incluir en esta etapa:** MQTT, firmware, dashboard, pruebas de carga completas o refactorización del Laboratorio 1.

## D. Suscriptor MQTT y broker — NOT STARTED

- [ ] Verificar instalación/configuración real de Mosquitto y elegir cliente MQTT compatible con el entorno, evitando dependencias innecesarias.
- [ ] Definir tópico multinodo y credenciales/ACL por identidad; no confiar solamente en el `node_id` del payload.
- [ ] Implementar un Subscriber PHP CLI separado de Apache que invoque **el mismo `TelemetryService`** que REST.
- [ ] Solo confirmar recepción/ACK de aplicación según el diseño después de persistencia confirmada; controlar reintentos y fallos.
- [ ] Deshabilitar receptores legados del Laboratorio 1 que puedan responder ACK sin persistir durante las pruebas del Laboratorio 2.
- [ ] Verificar deduplicación entre HTTP y MQTT para la misma clave `(device_id,seq)`.

**Criterio de salida:** telemetría MQTT persistida con identidad comprobable, errores trazables y sin doble inserción; evidencia del recorrido Subscriber → Service → Repository.

## E. Firmware e integración multinodo — NOT STARTED

- [ ] Adaptar/copiar a `lab2/firmware/` solo componentes útiles del firmware histórico, **sin modificar el Laboratorio 1**.
- [ ] Soportar `node_id`, `seq` conservado a través de reinicios (por ejemplo, reserva persistente de bloques; evitar escrituras continuas en flash), `timestamp` real o `null`, RSSI y valor PIR.
- [ ] Reintentos preservan el mismo `seq` y contenido para que la idempotencia funcione.
- [ ] Verificar transmisión HTTP y MQTT desde identidades de nodo distintas.
- [ ] Demostrar tres nodos concurrentes y correspondencia correcta entre nodo y medición; especificar claramente si alguno es emulado, y ajustarse a lo exigido por la guía/docente.

**Criterio de salida:** tres identidades con flujos concurrentes verificables; sin reutilización de secuencias y sin timestamps inventados.

## F. Pruebas, carga y observabilidad — NOT STARTED

**Pruebas T01–T05 de la guía:**

| ID | Caso | Evidencia esperada |
|---|---|---|
| T01 | POST válido | HTTP 201 y exactamente una fila |
| T02 | Token inválido | HTTP 401 y cero inserciones |
| T03 | JSON con campos obligatorios ausentes | HTTP 422 con detalle |
| T04 | Mensaje repetido | Una sola fila, respuesta coherente con el contrato |
| T05 | Tres nodos concurrentes | Asociaciones nodo/registro correctas |

**Pruebas complementarias de diseño:** T06 reinicio y reloj no sincronizado; T07 identidad MQTT falsa y duplicado entre transportes; T08 carga escalonada con throughput, tasa de error, p50 y p95. La sesión 8 sugiere trazabilidad requisitos→pruebas→evidencia y logs útiles.

- [ ] Implementar pruebas reproducibles, scripts o colección HTTP; no fabricar mediciones.
- [ ] Registrar latencias con reloj monotónico del cliente; detallar entorno, concurrencia y tamaño de muestra.
- [ ] Diferenciar solicitudes completadas/segundo de **inserciones únicas/segundo**.
- [ ] Registrar logs sin tokens ni datos sensibles, con identidad del nodo, resultado y duración.

**Criterio de salida:** T01–T08 ejecutadas con resultados reproducibles y tabla de desempeño p50/p95; limitaciones documentadas, no ocultadas.

## G. Documentación y entrega — NOT STARTED

- [ ] README de instalación/ejecución HTTP, CLI MQTT y base de datos; indicar prerrequisitos reales.
- [ ] Contratos y ejemplos JSON, códigos de respuesta y autorización.
- [ ] Diagramas coherentes de contexto, componentes y secuencia, y decisión arquitectónica breve (ADR) donde sea útil.
- [ ] Evidencias REST, MQTT, registros MySQL, tres nodos, pruebas y carga; conclusiones respaldadas por los datos reales.
- [ ] Revisar requisitos funcionales/no funcionales y criterios de aceptación verificables.
- [ ] Revisar archivos sensibles con `git status`, `git diff --cached`, `git check-ignore`, y registrar commits pequeños por etapa.
- [ ] Confirmar commit/push finales del repositorio del laboratorio antes de declarar entrega completada.

**Criterio de salida:** un tercero puede desplegar, ejecutar pruebas, comprobar resultados y distinguir claramente lo probado de lo pendiente.

## Reglas de mantenimiento

1. Fuente de verdad: archivos y pruebas reales > guía > decisiones acordadas > este roadmap > conversación.
2. Solo marcar como COMPLETE cuando existan resultados observables; distinguir «implementado», «probado localmente» y «subido a GitHub».
3. Mantener el Laboratorio 1 sin cambios; implementar solamente en `lab2/` salvo autorización específica.
4. Encargos acotados a Codex: un componente por sesión, sin auditorías repetidas, agentes, web o instalaciones no justificadas.
5. Al cierre de cada sesión: verificar → revisar Git → actualizar `PROJECT_CONTEXT.md` del repositorio workflow → commit/push cuando corresponda.
