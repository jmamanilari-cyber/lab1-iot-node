# ROADMAP — Laboratorio 2: backend multinodo y demostración

**Checkpoint:** 19 de octubre de 2026, cierre nocturno.  
**Producto:** https://github.com/jmamanilari-cyber/lab1-iot-node  
**Continuidad:** https://github.com/FAJIFBEFJHA/ai-assisted-development-workflow/blob/main/projects/lab1-iot-node/PROJECT_CONTEXT.md  
**Último commit del producto verificado en GitHub:** `a21b529` (`feat(lab2): add interactive demonstration tool`, versión modificada del commit anterior).  
**Guía:** `docs/Laboratior 2 guia.pdf`, disponible localmente; no asumir que los archivos PDF no versionados están en GitHub.

## Alcance y criterios

Backend PHP 8.2/PDO/MariaDB para **tres ESP32**, dos transportes (HTTP REST y MQTT), un **TelemetryService** de dominio, autenticación, ACL MQTT, validación, persistencia, idempotencia, pruebas y evidencias. No modificar el Laboratorio 1 ni añadir Edge Computing/Laboratorio 3 o dashboard fuera de requisitos.

Rutas implementadas: `POST /api/v1/telemetry`, `GET /api/v1/devices`, `GET /api/v1/devices/{id}/telemetry`, `PUT /api/v1/devices/{id}/config`, `GET /api/v1/events`.

**Formato de evaluación:** el miércoles **21/10** el profesor visita el puesto de trabajo para comprobar el avance y observar el funcionamiento; **no se necesita exposición formal**. El miércoles **28/10** evalúa en el puesto según una rúbrica mediante preguntas sobre características y demostraciones prácticas. Preparar ejecución visible y reproducible, no solamente respuestas teóricas. Es esperable que pida mostrar MQTT, errores, autenticación, persistencia y los nodos; la rúbrica exacta aún no se ha proporcionado en esta sesión.

## Estado de desarrollo y verificación

| Etapa | Situación | Evidencia |
|---|---|---|
| A. Arquitectura y contratos | Completa | Controller, Service, Repository, Subscriber; código aislado en `lab2/` |
| B. MariaDB/PDO | Probada en escritorio **y laptop** | Cuatro tablas, tres identidades, consulta preparada/UTC; commit `e94abd4` |
| C1. Telemetría HTTP | Probada en escritorio **y laptop** | T01–T04, errores y concurrencia; `e0069b3` |
| C2. API REST restante | Probada en escritorio **y laptop** | Cuatro endpoints, filtros, permisos, configuración; `fc49604` |
| D1. MQTT seguro de loopback | Probada en escritorio **y laptop** | ACL, subscriber, duplicados, reanudación; `c26627d` |
| H. Herramienta de demostración | Código publicado, validado **en escritorio** | `lab2/scripts/demo.ps1`, `demo-data.php`, `demo-acl.php`; commit `a21b529`; **falta probarla en la laptop** |
| D2. Broker MQTT para ESP32 por LAN | Pendiente | 1884 escucha solo en 127.0.0.1, no accesible al ESP32 |
| E0. Diagnóstico de hardware | Parcial | 1 ESP32 confirmada; PIR básico funciona; relé por BC548 funciona; calibración pendiente |
| E1. Firmware de un ESP32 | Pendiente | Datos físicos y transmisión HTTP/MQTT; reinicio/seq |
| E2. Tres nodos físicos | Pendiente | Verificar 2 ESP32 restantes e integración concurrente |
| F. T05–T08, carga | Parcial | Concurrencia HTTP 4 procesos probada; faltan ensayo multinodo físico, carga, p50/p95 |
| G. Informe y evidencias | Parcial | Completar diagramas, resultados, documentos y demostración final |

### Laptop de presentación — 19/10

Directorio: `C:\Proyecto\lab1-iot-node`. Git en `main`; sincronizada inicialmente con `b7e7824` y limpia. Aún **no se confirmó en la laptop la descarga del commit de demo `a21b529`**. XAMPP: PHP CLI **8.2.12** (PDO, pdo_mysql, JSON, OpenSSL); MariaDB **10.4.32**, cuatro tablas `devices/telemetry/configurations/events`, tres dispositivos `esp32-01/02/03`. Mosquitto **2.1.2** instalado y 1883 en escucha; ese listener no está declarado seguro. Arduino IDE y VS Code instalados; Codex CLI opcional, no necesario para demostrar.

Credenciales privadas de PDO, tokens de dispositivo y token administrativo **creados y excluidos por Git**. `check-connection.php` devuelve `OK: conexion PDO, consulta preparada nativa y sesion UTC.`

Ejecuciones exitosas **en la laptop**:
- `lab2/tests/c1-http.php`: 201, 401, 422, 200 duplicado, 409 conflicto, 400, 403 y verificación SQL.
- `lab2/tests/c2-http.php`: consulta de dispositivos, historia temporal, filtros/paginación, autorización y errores, PUT de configuración, eventos.
- `lab2/tests/d1-security.php`: acceso autorizado y rechazo de anónimo/contraseña incorrecta/violaciones ACL.
- `lab2/tests/d1-mqtt.php`: MQTT → suscriptor PHP → TelemetryService → MariaDB; duplicado, conflicto, identidad, reanudación controlada.
- `lab2/tests/c1-concurrency.php`: cuatro procesos, una creación, tres duplicados y una fila persistida.

Estas ejecuciones prueban **backend local**, no el enlace inalámbrico ni tres nodos físicos. Las pruebas pueden añadir registros a MariaDB; no borrar ni resembrar.

### Herramienta de demostración — 19/10, escritorio

Los tres archivos están presentes en el remoto `main` verificado: `lab2/scripts/demo.ps1`, `demo-data.php`, `demo-acl.php`. El usuario confirmó en el **escritorio** la ejecución correcta y suficientemente detallada de todas las opciones:
1. Inventario de ejecutables y prueba real de PDO (avisa si MySQL/XAMPP está detenido).
2. Iniciar PHP HTTP en `127.0.0.1:18082` y Mosquitto D1 en `127.0.0.1:1884`, sin alterar 1883.
3. Mostrar salidas originales de pruebas HTTP, códigos y comparación de registros SQL.
4. Mostrar seguridad/ACL MQTT, ingesta, auditoría y evidencia SQL.
5. Demostrar cuatro procesos concurrentes y una única fila.
6. Consultar dispositivos, últimas mediciones, eventos, configuraciones y auditoría sin imprimir secretos.
7. Detener **solo** procesos iniciados por este menú y comprobar puertos.

El estado final observado después de detener procesos: REST 18082 y MQTT 1884 sin escucha; PDO volvió a funcionar tras iniciar MariaDB en XAMPP. El programa usa rutas absolutas de instalación conocidas sin requerir PATH. El archivo descargado originalmente necesitó `Unblock-File`; tras traerlo mediante Git puede no necesitarlo. No afirmar que el menú v2 se ejecutó todavía en la laptop.

**Comandos para mañana en la laptop** (desde la raíz, tras comprobar rama limpia):

```powershell
cd C:\Proyecto\lab1-iot-node
git status --short
git pull --ff-only origin main
.\lab2\scripts\demo.ps1
```

Si PowerShell bloqueara la ejecución por marca de origen, revisar política y aplicar `Unblock-File` solo al archivo confiable. Antes de demostrar: abrir XAMPP e iniciar MySQL; opción 1 → 2 → 3 → 4 → 5 → 6 → 7. **No ejecutar `schema.sql` ni `seed.sql` de nuevo**.

## Hardware real — etapa E0

- Tres ESP32 disponibles, pero solo una **NodeMCU ESP-32S V1.1 (38 pines)** verificada. Las otras dos placas aún no se han probado ni tienen sensores conectados.
- Relé JQC-3FF-S-Z 5V: la conexión directa GPIO26 no liberaba correctamente con nivel HIGH de 3.3 V. Solución **probada aisladamente**: BC548 NPN, resistencia 1 kΩ GPIO26→base, emisor a GND y colector a IN del módulo; relé VCC 5V y GND común. Bornes COM/NO/NC sin carga.
- PIR compatible HC-SR501/BISS0001, OUT→GPIO27, detección básica observada. **Sensibilidad y rango aparentemente excesivos; falta ajuste de potenciómetros, tiempo de retención y medición del rango**. Sin prueba suficiente de fallos falsos o defecto.
- LED externos sin reconectar. Aún no se ha probado el montaje PIR + relé + LED integrado.
- No dar por concluida E0 antes de verificar placas 2/3. No es necesario comprar automáticamente dos conjuntos idénticos de periféricos; confirmar si la rúbrica requiere sensores físicos en cada nodo. Sin PIR, otros ESP32 pueden comunicar RSSI e identidad, pero `value` actual se valida como PIR binario: no representar datos sintéticos como lecturas físicas.
- Seguridad: solo USB/baja tensión; desconectar antes de modificar conexiones; no conectar red eléctrica a bornes del relé.

## Plan de trabajo y restricciones reales (20–28 de octubre)

**Restricciones:** jueves, viernes y sábado se cocina habitualmente hasta las 13:00; el domingo visita a su madre y vuelve a la computadora a las **19:00–20:00**; martes clase nocturna hasta las 21:00, miércoles laboratorio de Software de Telecomunicaciones **19:20–21:00**. Habitualmente está despierto hasta las **00:00**, pero **terminar trabajo técnico ~22:30–23:00** salvo necesidad y dejar margen. Reservar **20 min diarios en total** para Arknights Endfield + Wuthering Waves; proyecto personal **45–60 min** en días factibles y **60–90 min** viernes/sábado; miércoles de revisión puede ser descanso.

| Día | Laboratorio — bloque recomendado | Meta |
|---|---|---|
| **Mar 20** | 14:00–16:30, opcional repaso 21:30–22:30 | Laptop: `git pull`, verificar menú completo en Windows y arranque en frío; registrar evidencia sin alterar backend |
| **Mié 21** | 17:00–18:15 y revisión 19:20–21:00; 21:30–22:15 para anotar observaciones | **Avance presencial:** mostrar REST, MQTT D1, permisos, errores, idempotencia, SQL. No afirmar firmware terminado |
| **Jue 22** | 13:00–14:15, 17:30–20:00, reserva 21:00–22:30 | Calibrar PIR, medir retención y alcance, integrar relé/LED sin red eléctrica |
| **Vie 23** | 13:30–17:00 y 20:00–22:30 | Probar placas 2/3; firmware del primer nodo, secuencia e ingesta HTTP |
| **Sáb 24** | 13:30–17:00 y 19:30–22:00 | MQTT por LAN autenticado y un ESP32 real con HTTP/MQTT persistiendo datos |
| **Dom 25** | Solo 20:30–22:00, flexible | Documentación, revisión de progreso y límites. No depender de disponibilidad diurna |
| **Lun 26** | 10:00–12:30, 17:30–20:00, reserva 21:00–22:30 | Tres nodos si funcionan; T05–T07, diagramas, evidencias y correcciones prioritarias |
| **Mar 27** | 14:00–17:15, 21:30–22:30 | T08, throughput, latencias p50/p95, informe, commits; **cierre técnico ~22:30** |
| **Mié 28** | 17:00–18:15 y evaluación 19:20–21:00 | **Entrega final presencial:** solo ensayo y demostración con laptop, sin implementación nueva |

**Contingencias:** si falla una de las placas, no presentar cliente emulado como ESP32 físico; usar emulación identificada como tal únicamente en pruebas permitidas. Si se retrasa D2 LAN, conservar D1 demostrado como prueba local y dejar clara la limitación. Priorizar criterios de la rúbrica sobre elementos cosméticos. Ajustar plan según observaciones reales del profesor el día 21.

## Reglas de implementación y cierre

- `node_id` vinculado a credencial, `seq` único incluso tras reinicio, reintentos con contenido inalterado; `timestamp` Unix UTC válido o `null`, `received_at` lo asigna el servidor; PIR `value` 0/1, `rssi` -127..0/null. Nada inventado.
- MQTT y REST comparten el mismo `TelemetryService` y transacción PDO. **D1 solo 127.0.0.1:1884**, no probado por LAN. QoS1/PUBACK del cliente empleado puede anteceder commit SQL; no se garantiza pérdida cero ante corte brusco.
- No subir `lab2/config/database.local.php`, `test-tokens.local.json`, `admin.local.json`, `mqtt.local.php`, `mqtt-private/`, Wi-Fi, passwords, colas/auditoría privadas.
- El código verificado prevalece sobre el roadmap; revisar Git y pruebas antes de cambios. Encargar tareas pequeñas a Codex solo cuando haga falta; no rehacer etapas completadas.
- La guía y otros PDF siguen locales/sin seguimiento en el escritorio según último `git status`; no agregarlos automáticamente. Antes de cada commit revisar `git status --short`, `git diff --cached --name-only` y `git diff --cached --check`.
