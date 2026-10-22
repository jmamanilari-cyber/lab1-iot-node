# ROADMAP — Laboratorio 2: backend multinodo y demostración

**Checkpoint:** 21 de octubre de 2026; revisión presencial completada sin observaciones.
**Producto:** https://github.com/jmamanilari-cyber/lab1-iot-node  
**Continuidad:** https://github.com/FAJIFBEFJHA/ai-assisted-development-workflow/blob/main/projects/lab1-iot-node/PROJECT_CONTEXT.md  
**Commit base del presente checkpoint:** `81f334b` (roadmap anterior); el script de demostración quedó publicado en `a21b529`. Verificar Git antes de continuar.
**Guía:** `docs/Laboratior 2 guia.pdf`, disponible localmente; no asumir que los archivos PDF no versionados están en GitHub.

## Alcance y criterios

Backend PHP 8.2/PDO/MariaDB para **tres ESP32**, dos transportes (HTTP REST y MQTT), un **TelemetryService** de dominio, autenticación, ACL MQTT, validación, persistencia, idempotencia, pruebas y evidencias. No modificar el Laboratorio 1 ni añadir Edge Computing/Laboratorio 3 o dashboard fuera de requisitos.

Rutas implementadas: `POST /api/v1/telemetry`, `GET /api/v1/devices`, `GET /api/v1/devices/{id}/telemetry`, `PUT /api/v1/devices/{id}/config`, `GET /api/v1/events`.

**Revisión del miércoles 21/10: realizada, sin observaciones del docente**, según confirmó el estudiante. La evaluación final permanece programada para el **miércoles 28/10**, con demostraciones prácticas. La revisión favorable no equivale a haber concluido firmware, pruebas físicas ni métricas pendientes.

## Estado de desarrollo y verificación

| Etapa | Situación | Evidencia |
|---|---|---|
| A. Arquitectura y contratos | Completa | Controller, Service, Repository, Subscriber; código aislado en `lab2/` |
| B. MariaDB/PDO | Probada en escritorio **y laptop** | Cuatro tablas, tres identidades, consulta preparada/UTC; commit `e94abd4` |
| C1. Telemetría HTTP | Probada en escritorio **y laptop** | T01–T04, errores y concurrencia; `e0069b3` |
| C2. API REST restante | Probada en escritorio **y laptop** | Cuatro endpoints, filtros, permisos, configuración; `fc49604` |
| D1. MQTT seguro de loopback | Probada en escritorio **y laptop** | ACL, subscriber, duplicados, reanudación; `c26627d` |
| H. Herramienta de demostración | **Verificada en laptop y escritorio**; incidencia MQTT puntual pendiente | Menú opciones 1–7; en ejecución posterior `demo-acl.php` confundió PUBACK 16 con error; falta confirmar corrección local y publicarla |
| D2. Broker MQTT para ESP32 por LAN | Pendiente | 1884 escucha solo en 127.0.0.1, no accesible al ESP32 |
| E0. Diagnóstico de hardware | Parcial | 1 ESP32 confirmada; PIR básico funciona; relé por BC548 funciona; calibración pendiente |
| E1. Firmware de un ESP32 | Pendiente | Datos físicos y transmisión HTTP/MQTT; reinicio/seq |
| E2. Tres nodos físicos | Pendiente | Verificar 2 ESP32 restantes e integración concurrente |
| F. T05–T08, carga | Parcial | Concurrencia HTTP 4 procesos probada; faltan ensayo multinodo físico, carga, p50/p95 |
| G. Informe y evidencias | Parcial | Revisión 21/10 sin observaciones; completar resultados, evidencias y documentación final |
| M. Migración y colaboradores | Pendiente | Separar Lab1/Lab2, conservar historial pertinente, invitar a dos compañeros y validar ambos equipos; sin asignarles tareas |

### Laptop de presentación — verificación 20/10

Directorio: `C:\Proyecto\lab1-iot-node`. Tras actualizar el repositorio, el menú se ejecutó en la laptop. XAMPP: PHP CLI **8.2.12** (PDO, pdo_mysql, JSON, OpenSSL); MariaDB **10.4.32**, cuatro tablas `devices/telemetry/configurations/events`, tres dispositivos `esp32-01/02/03`. Mosquitto **2.1.2** instalado y 1883 en escucha; ese listener no está declarado seguro. Arduino IDE y VS Code instalados; Codex CLI no es necesario para demostrar.

Credenciales privadas de PDO, tokens de dispositivo y token administrativo **creados y excluidos por Git**. `check-connection.php` devuelve `OK: conexion PDO, consulta preparada nativa y sesion UTC.`

Ejecuciones exitosas **en la laptop**:
- `lab2/tests/c1-http.php`: 201, 401, 422, 200 duplicado, 409 conflicto, 400, 403 y verificación SQL.
- `lab2/tests/c2-http.php`: consulta de dispositivos, historia temporal, filtros/paginación, autorización y errores, PUT de configuración, eventos.
- `lab2/tests/d1-security.php`: acceso autorizado y rechazo de anónimo/contraseña incorrecta/violaciones ACL.
- `lab2/tests/d1-mqtt.php`: MQTT → suscriptor PHP → TelemetryService → MariaDB; duplicado, conflicto, identidad, reanudación controlada.
- `lab2/tests/c1-concurrency.php`: cuatro procesos, una creación, tres duplicados y una fila persistida.

Estas ejecuciones prueban **backend local**, no el enlace inalámbrico ni tres nodos físicos. Las pruebas pueden añadir registros a MariaDB; no borrar ni resembrar.

### Herramienta de demostración — 20–21/10, escritorio y laptop

Los tres archivos están publicados: `lab2/scripts/demo.ps1`, `demo-data.php`, `demo-acl.php`. El usuario verificó sus salidas detalladas en **escritorio y laptop**, con las precisiones siguientes:
1. Inventario de ejecutables y prueba real de PDO (avisa si MySQL/XAMPP está detenido).
2. Iniciar PHP HTTP en `127.0.0.1:18082` y Mosquitto D1 en `127.0.0.1:1884`, sin alterar 1883.
3. Mostrar salidas originales de pruebas HTTP, códigos y comparación de registros SQL.
4. Mostrar seguridad/ACL MQTT, ingesta, auditoría y evidencia SQL.
5. Demostrar cuatro procesos concurrentes y una única fila.
6. Consultar dispositivos, últimas mediciones, eventos, configuraciones y auditoría sin imprimir secretos.
7. Detener **solo** procesos iniciados por este menú y comprobar puertos.

**Evidencia en laptop (20/10):** opción 1 comprobó herramientas, PDO y UTC; opción 2 inició REST 18082 y MQTT 1884; opción 3 validó REST C1/C2 y SQL (6→9 filas); opción 4 validó 12 ACL, ingesta MQTT, auditoría y SQL (9→11); opción 5 confirmó concurrencia de cuatro procesos con una sola inserción (11→12); opción 6 consultó telemetría, eventos, configuraciones y auditoría; opción 7 liberó ambos puertos sin detener MariaDB. No confundir los fixtures con tres dispositivos físicos. PowerShell requirió una política temporal de ejecución solo en la sesión.

**Incidencia posterior (21/10):** sin suscriptor coincidente, tres publicaciones válidas recibieron `PUBACK=16` (MQTT 5: *No matching subscribers*). El diagnóstico `demo-acl.php` publicado exigía `0` y mostró tres discrepancias falsas; los nueve rechazos `135` fueron correctos. Se indicó aceptar **0 o 16** para publicaciones autorizadas. La corrección local presentó errores de edición/sintaxis; **no se recibió evidencia final de 12/12 después de corregirla**. Verificarla en laptop y escritorio antes de un commit manual. No cambiar ACL ni credenciales por este motivo.

**No ejecutar `schema.sql` ni `seed.sql` nuevamente**; las pruebas conservan sus fixtures.

## Hardware real — etapa E0

- Tres ESP32 disponibles, pero solo una **NodeMCU ESP-32S V1.1 (38 pines)** verificada. Las otras dos placas aún no se han probado ni tienen sensores conectados.
- Relé JQC-3FF-S-Z 5V: la conexión directa GPIO26 no liberaba correctamente con nivel HIGH de 3.3 V. Solución **probada aisladamente**: BC548 NPN, resistencia 1 kΩ GPIO26→base, emisor a GND y colector a IN del módulo; relé VCC 5V y GND común. Bornes COM/NO/NC sin carga.
- PIR compatible HC-SR501/BISS0001, OUT→GPIO27, detección básica observada. **Sensibilidad y rango aparentemente excesivos; falta ajuste de potenciómetros, tiempo de retención y medición del rango**. Sin prueba suficiente de fallos falsos o defecto.
- LED externos sin reconectar. Aún no se ha probado el montaje PIR + relé + LED integrado.
- **Tres ESP32 físicos son obligatorios**, pero solo ESP32 #1 necesita PIR, relé y LED físicos. El profesor **autorizó simular el valor PIR** de ESP32 #2 y #3; no comprar periféricos duplicados. Los tres ESP32 deberán enviar mensajes reales por la red con identidad propia; etiquetar los valores PIR simulados y no presentarlos como mediciones reales.
- Seguridad: solo USB/baja tensión; desconectar antes de modificar conexiones; no conectar red eléctrica a bornes del relé.

## Plan de trabajo y restricciones reales (20–28 de octubre)

**Restricciones:** jueves, viernes y sábado se cocina habitualmente hasta las 13:00; el domingo visita a su madre y vuelve a la computadora a las **19:00–20:00**; martes clase nocturna hasta las 21:00, miércoles laboratorio de Software de Telecomunicaciones **19:20–21:00**. Habitualmente está despierto hasta las **00:00**, pero **terminar trabajo técnico ~22:30–23:00** salvo necesidad y dejar margen. Reservar **20 min diarios en total** para Arknights Endfield + Wuthering Waves; proyecto personal **45–60 min** en días factibles y **60–90 min** viernes/sábado; miércoles de revisión puede ser descanso.

| Día | Laboratorio — bloque recomendado | Meta |
|---|---|---|
| **Mar 20** | **Completado** | Laptop: siete opciones del menú, REST/MQTT, SQL, concurrencia y apagado de servicios verificados |
| **Mié 21** | **Completado** | Revisión de avance realizada sin observaciones; confirmar corrección del diagnóstico `demo-acl.php` cuando se retome |
| **Jue 22** | 13:00–14:15, 17:30–20:00, reserva 21:00–22:30 | Calibrar PIR, medir retención, integrar relé/LED; preparar separación de repositorios si hay margen |
| **Vie 23** | 13:30–17:00 y 20:00–22:30 | Verificar placas 2/3 y firmware del primero (HTTP); crear repositorio independiente Lab2 e invitar a colaboradores si no interfiere |
| **Sáb 24** | 13:30–17:00 y 19:30–22:00 | MQTT por LAN autenticado y un ESP32 real con HTTP/MQTT persistiendo datos |
| **Dom 25** | Solo 20:30–22:00, flexible | Documentación o migración de repositorios, sin comprometer la entrega |
| **Lun 26** | 10:00–12:30, 17:30–20:00, reserva 21:00–22:30 | Tres ESP32 físicos (PIR simulado permitido en #2/#3); T05–T07, diagramas y evidencias |
| **Mar 27** | 14:00–17:15, 21:30–22:30 | T08, throughput, latencias p50/p95, informe, commits; **cierre técnico ~22:30** |
| **Mié 28** | 17:00–18:15 y evaluación 19:20–21:00 | **Entrega final presencial:** solo ensayo y demostración con laptop, sin implementación nueva |

**Contingencias:** si falla una placa, no presentar un cliente de computadora como ESP32 físico; comunicar el bloqueo. PIR simulado en los ESP32 #2/#3 está autorizado, pero la comunicación de las tres placas debe ser real. Si D2 LAN se retrasa, distinguirlo claramente del broker D1 local. Priorizar los criterios del laboratorio sobre la migración del repositorio.

## M. Separación de repositorios y colaboración (pendiente)

- Crear un repositorio GitHub exclusivo para el Laboratorio 2, sin alterar el historial o archivos históricos de Lab1. Elegir un método que preserve el historial **pertinente** de `lab2/` y verificar los cambios de rutas y dependencias.
- Migrar código, pruebas, firmware y documentación a Lab2. Revisar `.gitignore` y nunca transferir credenciales, contraseñas, tokens, colas ni configuraciones privadas al remoto. Comprobar funcionamiento del nuevo repo en escritorio y laptop.
- Preparar y enviar **dos invitaciones de colaboración**. **No asignar tareas ni exigir commits** a los compañeros: el usuario asume desarrollo, hardware, pruebas y documentación. La invitación concede acceso, no atribuye commits.
- Actualizar enlaces del ROADMAP y `PROJECT_CONTEXT.md` cuando exista URL definitiva. Retirar `lab2/` del repositorio original **solo después** de validar la migración; aplazar cualquier paso destructivo si compromete la entrega final del 28/10.
- El asistente solo prepara archivos/comandos; **no realiza modificaciones, commits ni pushes** en repositorios remotos.

## Reglas de implementación y cierre

- `node_id` vinculado a credencial, `seq` único incluso tras reinicio, reintentos con contenido inalterado; `timestamp` Unix UTC válido o `null`, `received_at` lo asigna el servidor; PIR `value` 0/1, `rssi` -127..0/null. Distinguir telemetría PIR simulada (nodos #2/#3 autorizados) de mediciones PIR físicas (#1).
- MQTT y REST comparten el mismo `TelemetryService` y transacción PDO. **D1 solo 127.0.0.1:1884**, no probado por LAN. QoS1/PUBACK del cliente empleado puede anteceder commit SQL; no se garantiza pérdida cero ante corte brusco.
- No subir `lab2/config/database.local.php`, `test-tokens.local.json`, `admin.local.json`, `mqtt.local.php`, `mqtt-private/`, Wi-Fi, passwords, colas/auditoría privadas.
- El código verificado prevalece sobre el roadmap; revisar Git y pruebas antes de cambios. Encargar tareas pequeñas a Codex solo cuando haga falta; no rehacer etapas completadas.
- La guía y otros PDF siguen locales/sin seguimiento en el escritorio según último `git status`; no agregarlos automáticamente. Antes de cada commit revisar `git status --short`, `git diff --cached --name-only` y `git diff --cached --check`.
