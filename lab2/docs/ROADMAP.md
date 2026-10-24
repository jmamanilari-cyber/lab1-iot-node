# ROADMAP — Laboratorio 2: backend multinodo y demostración

**Checkpoint:** cierre de sesión del 24/10/2026. P13 y P14 ejecutados y observados físicamente, sin evidencias específicas guardadas; P15 con campaña física y métricas internas PHP documentadas. Revisión presencial del 21/10 sin observaciones. Esta actualización no ejecutó pruebas; conserva los antecedentes del 22–23/10 como históricos.
**Producto:** https://github.com/jmamanilari-cyber/lab1-iot-node  
**Continuidad:** https://github.com/FAJIFBEFJHA/ai-assisted-development-workflow/blob/main/projects/lab1-iot-node/PROJECT_CONTEXT.md  
**Commit local comprobado:** `ffc3653` (instrumentación y resultados de latencia P15); anteriores: `807eba0` (ESP32 #3 MQTT) y `a8b0323` (MQTT seguro por LAN), según `git log -3 --oneline`. No se confirma publicación de estos commits. Antecedentes E1: `04b29a3`, `6e3816a`, `bd66035`; `4328fba` corrige PUBACK 0/16. `git status --short` inicial muestra únicamente sin seguimiento `P15_LATENCIA.patch` (raíz del producto), `lab2/logs/` y `lab2/scripts/capture-serial.py`: conservarlos sin agregar, borrar ni modificar. Este cierre documental queda local, sin commits/push.
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
| H. Herramienta de demostración | **Verificada en laptop y escritorio**; corrección PUBACK publicada | Menú opciones 1–7; `4328fba` acepta PUBACK 0/16; falta nueva evidencia de 12/12 ACL tras la corrección |
| D2. Broker MQTT para ESP32 por LAN | Integrado y validado con #3 | MQTT QoS 1/TLS en 8883, backend portátil; persistencia documentada en P15. No equiparar PUBACK con commit SQL |
| E0. Diagnóstico de hardware | Integración funcional validada; caracterización parcial | #1 con PIR físico, BC548, relé/LED; retención funcional ~10 s. Calibración y medición temporal cuantitativa pendientes |
| E1. Firmware de un ESP32 | HTTPS portátil validado | #1/#2 transmiten por HTTPS a laptop mediante hotspot; antecedente de escritorio conservado abajo |
| E2. Tres nodos físicos | P13 ejecutado y observado; sin evidencia específica guardada | #1 PIR físico/HTTPS; #2 PIR simulado/HTTPS; #3 PIR simulado/MQTT QoS 1/TLS |
| F. T05–T08, carga | Parcial; P14 ejecutado sin evidencia específica guardada y P15 medido | 336 filas nuevas en ~20 min y percentiles internos PHP disponibles; faltan carga controlada y revisión de criterios T05–T08 |
| G. Informe y evidencias | Parcial; P15 documentado | [P15_RESULTADOS.md](P15_RESULTADOS.md); P16 informe y demostración pendientes |
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

**Antecedente al cierre 23/10:** Apache/HTTPS y acceso en hotspot aún estaban pendientes. **Actualización 24/10:** laptop con PHP, MariaDB, Apache HTTPS y Mosquitto validada como backend portátil para los tres nodos; no confundir esta evidencia física con las suites locales del 20/10.

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

**Incidencia posterior (21/10, histórica):** sin suscriptor coincidente, tres publicaciones válidas recibieron `PUBACK=16` (MQTT 5: *No matching subscribers*). El diagnóstico de entonces exigía `0` y mostró tres discrepancias falsas; los nueve rechazos `135` fueron correctos. La edición inicial presentó errores de sintaxis. **La corrección para aceptar 0 o 16 ya está publicada en `4328fba`**, comprobado en Git; no volver a implementarla. Sigue sin recibirse nueva evidencia final de 12/12 posterior a esa corrección en laptop/escritorio. Conservar ese pendiente sin desplazar la prioridad portátil ni cambiar ACL/credenciales.

**No ejecutar `schema.sql` ni `seed.sql` nuevamente**; las pruebas conservan sus fixtures.

## Inicio E1 — 22/10, primera unidad verificable

Registro histórico del inicio de E1. El despliegue y pruebas físicas posteriores se actualizan en el cierre del 23/10 siguiente; no interpretar los pendientes de este registro como ausencia actual de HTTPS en escritorio.

- Se añadió `firmware/esp32_02_http/` sin modificar `pruebas/pir_rele_10s/`.
  Contrato POST existente, `timestamp=null`, PIR alternado con
  `extensions.pir_simulated=true` y RSSI obtenido del enlace Wi-Fi real.
- Secuencia y JSON pendiente en un blob NVS antes del envío; recuperación tras
  reinicio y reintentos con contenido idéntico. Fallos permanentes detienen el
  cliente; fallos transitorios usan backoff. Sin `delay()`; E/S HTTP en tarea propia.
- HTTPS obligatorio con CA y nombre de servidor verificados; sin modo inseguro,
  fallback HTTP ni redirecciones. Plantilla Apache TLS → PHP loopback y guía
  [FIRMWARE_E1_HTTP.md](FIRMWARE_E1_HTTP.md). Proxy/certificados aún no desplegados en aquel checkpoint inicial; posteriormente probados en escritorio.
- En aquel checkpoint: compilación con core Arduino-ESP32 3.3.8 y marcadores ficticios, sin flashear.
  Entonces estaban pendientes pruebas C++ en host, TLS por LAN y envío desde placa.
  En aquel momento seguían pendientes pruebas C++ en host, cortes y evidencia multinodo.
  P13/P14 actualizan integración e interrupciones el 24/10; no acreditan pruebas C++ en host.
- Git confirma `4328fba`: corrección de diagnóstico PUBACK ya publicada. La
  incidencia descrita arriba es histórica; no se repitieron aquí las 12 ACL.

## Antecedente histórico 23/10 — E1, diagnóstico Wi-Fi y arquitectura portátil

Los bloqueos e IP de esta sección describen el 23/10. El estado vigente figura en el cierre del 24/10 siguiente; no reutilizar estas IP como configuración actual.

### Evidencia confirmada y límites

- Backend PHP/MariaDB y sus pruebas satisfactorias conservados; tres identidades `esp32-01`, `esp32-02`, `esp32-03` con tokens individuales. Apache HTTPS del **escritorio** configurado y probado, certificado con SAN DNS `telemetry.lab.test`, destino actual del firmware **192.168.0.3:8443**. Nada de esto acredita todavía el nuevo entorno HTTPS de laptop/hotspot.
- Firmware `lab2/firmware/esp32_02_http/esp32_02_http.ino`: HTTPS/Bearer con verificación TLS estricta; RSSI real, PIR simulado explícito, SNTP, secuencias y mensaje pendiente persistidos en NVS, reintentos, muestreo aproximado de 10 s y tarea FreeRTOS. Conexión TCP por IP conservando nombre TLS/SNI y HTTP; no depende de resolver `telemetry.lab.test` por DNS.
- En la red anterior se observaron:

  ```text
  HTTP=201 seq=37 ack=1 PIR=SIMULADO
  HTTP=201 seq=38 ack=1 PIR=SIMULADO
  HTTP=201 seq=39 ack=1 PIR=SIMULADO
  ```

  **Validación parcial:** prueba de HTTPS desde la placa en aquella configuración, no validación de la arquitectura definitiva ni de todas las condiciones de fallo.

| Entorno | Resultado confirmado | Interpretación y estado |
|---|---|---|
| Claro CastleNet Infinity601 | Ambas bandas con SSID `RODO`; 2,4 GHz/canal 11 comprobados; sketch diagnóstico conecta | No afirmar ausencia de 2,4 GHz ni fallo de placa/credenciales por defecto |
| E1 sobre Claro | `WIFI_REASON_AUTH_FAIL` 202; un intento tardó cerca de 10 min | Causa no establecida; no atribuir definitivamente a Band Steering |
| E1 tras corrección Wi-Fi | Reconexión exclusiva del core, eventos y SNTP tras IP; compila en Arduino-ESP32 3.3.8; prueba posterior >240 s sin IP | **Comportamiento Claro no solucionado**; compilación no equivale a éxito físico |
| Hotspot Android exclusivo 2,4 GHz | `AP conectado` canal 6, auth 3; IP `10.229.155.191`, tiempo hasta IP **1227 ms**; SNTP iniciado tras IP | Asociación y DHCP exitosos en aproximadamente **1,227 s**; no generalizar a tres arranques o tres nodos |
| HTTPS desde hotspot | `HTTP=-1 seq=82 ack=0 PIR=SIMULADO`; destino seguía en `192.168.0.3` | **No funcional todavía en este entorno**; verificar laptop en la misma red y ruta/acceso/TLS. `-1` no identifica por sí solo la causa. Pendiente seq 82 conservado |

### Decisión principal de demostración

**Teléfono Android (hotspot 2,4 GHz) → laptop + ESP32 #1, #2 y #3.** La laptop ejecutará Apache con HTTPS, API PHP, MariaDB y herramientas/scripts de demostración. El escritorio queda para desarrollo y **no se trasladará a la universidad**. Se descarta continuar separando bandas desde el panel Claro o depender de una intervención del operador. Evaluación final: **miércoles 28 de octubre de 2026**.

## Cierre 24/10 — integración portátil, recuperación y métricas

**P13 ejecutado y observado físicamente, sin evidencia específica guardada de esa etapa:** tres ESP32 físicos transmitiendo simultáneamente por Wi-Fi del hotspot Android de 2,4 GHz a la laptop con PHP, MariaDB, Apache HTTPS y Mosquitto. USB se usó para alimentación y captura serie, no para transportar telemetría.

| Nodo | PIR | Transporte | Captura |
|---|---|---|---|
| ESP32 #1 | Físico; relé/LED existentes | HTTPS 8443 | COM5 |
| ESP32 #2 | Simulado declarado; RSSI real | HTTPS 8443 | COM8 |
| ESP32 #3 | Simulado declarado; RSSI real | MQTT QoS 1/TLS 8883 | COM9 |

**P14 ejecutado y observado físicamente:** pruebas reales de interrupción y recuperación de Wi-Fi, servicio HTTP y MQTT, sin evidencias específicas guardadas. P15 respalda la transmisión simultánea de los tres ESP32, pero no documenta los escenarios de recuperación de P14; no inferir garantía universal de cero pérdidas, cobertura de todos los reinicios ni identidad de todos los reintentos solo a partir de la campaña estable. PUBACK confirma al broker y no constituye ACK de persistencia recibido por el ESP32.

**P15 documentado:** fuente de métricas y límites en [P15_RESULTADOS.md](P15_RESULTADOS.md), secciones 1–9. La campaña está fechada el 24/10 UTC y comenzó el 23/10 en Perú; el checkpoint de continuidad corresponde a la sesión del 24/10.

- Campaña física: **1200,3180408 s (~20 min), 336 registros nuevos en MariaDB: 108/108/120** por #1/#2/#3. Throughput agregado aproximado **0,280 mensajes/s**; mide carga ofrecida y persistida, no capacidad máxima. Las confirmaciones y contadores son coherentes de forma agregada, sin export SQL exhaustivo fila a fila.
- Dos campañas posteriores e independientes miden duración interna de `TelemetryService::receive()`: validación, transacción, operaciones SQL y commit. Excluyen Wi-Fi, TLS de Apache, autenticación previa, cola MQTT y auditoría posterior. **No son RTT ni latencia extremo a extremo ESP32→MariaDB.**

| Campaña PHP | Operaciones registradas | p50 (ms) | p95 (ms) | Máximo (ms) |
|---|---|---:|---:|---:|
| ~300 s, HTTP | 52 (26 por nodo #1/#2) | 8,708 | 9,869 | 48,345 |
| ~120 s, HTTP | 22 (11 por nodo #1/#2) | 7,409 | 10,728 | 48,535 |
| ~120 s, MQTT | 12 (#3) | 6,380 | 27,407 | 48,811 |

La primera ventana no registró mediciones MQTT: no significa latencia cero ni ausencia demostrada de tráfico. Todas las operaciones registradas fueron `created`; muestras pequeñas y ventanas cortas, con valores atípicos conservados y causa no identificada. No combinar percentiles entre campañas ni concluir superioridad de un transporte. Detalle por nodo, método de interpolación y limitaciones en la sección 9 de P15.

**Anomalía COM5:** 63 650 repeticiones de `HTTP=201 seq=292` antes de la ventana física; incompatibles con el caudal serie y de origen no diagnosticado. No representan miles de solicitudes ni inserts. Corregir/verificar el registrador antes de una campaña nueva; conservar logs originales. La anomalía no invalida por sí sola el incremento SQL de los 20 min.

### Estado de prioridades y continuidad

| Tarea | Estado al 24/10 | Seguimiento |
|---|---|---|
| P01–P08: hotspot, laptop y HTTPS | Integración observada en P13 y documentada en P15 | No volver a tratarlos como bloqueo; conservar TLS, CA/SAN, autenticación y BD |
| P09: localización ante cambio de IP | Revisión documental pendiente | La campaña operativa no acredita por sí sola reproducibilidad ante cualquier cambio de IP |
| P10: tres RESET/EN y tiempos hasta IP | Sin evidencia específica en las fuentes consultadas | No marcar completado ni bloquear retrospectivamente P13 ejecutado |
| P11: integración física #1 | Validada funcionalmente | Caracterización temporal/calibración y detalles de cableado aún no acreditados se conservan abajo |
| P12: firmware #3 | Integrado | PIR simulado declarado, identidad propia, MQTT QoS 1/TLS |
| D2: MQTT seguro LAN | Integrado con #3 | Conservar autenticación/ACL; no presumir nueva salida 12/12 de demo-acl posterior a `4328fba` |
| P13: ensayo conjunto | Ejecutado y observado físicamente; sin evidencia específica guardada | P15 sí documenta transmisión simultánea de tres placas físicas con dos transportes |
| P14: interrupciones y recuperación | Ejecutado y observado físicamente; sin evidencias específicas guardadas | Wi-Fi, HTTP y MQTT; P15 no documenta estos escenarios de recuperación |
| P15: evidencia y métricas | Campañas completadas; cierre de criterios parcial | Carga controlada, corrección COM5 y revisión T05–T08 pendientes |
| P16: informe y demostración | Pendiente | Incorporar métricas y límites; ensayo docente portátil, cierre 27/10 ~22:30 |

**Siguiente sesión:** delimitar una prueba de carga controlada y revisar T05–T08 contra sus definiciones originales, sin asignarles aprobación automática por P13/P14/P15. Antes de otra captura serie, corregir/verificar la anomalía COM5 sin alterar fuentes originales. Después completar P16 con informe y demostración para el **28/10/2026**. Conservar `capture-serial.py`, logs experimentales y parche temporal sin versionar. No repetir suites ni investigaciones ya documentadas. Migración M sigue pendiente y no debe desplazar la entrega.

## Hardware real — etapa E0

- **Tres ESP32 disponibles y comprobadas mediante diagnósticos básicos**; una identificada como NodeMCU ESP-32S V1.1 (38 pines). La integración simultánea y transmisión concurrente se ejecutaron y observaron en P13 sin evidencia específica guardada; P15 sí respalda documentalmente esa transmisión simultánea.
- Relé JQC-3FF-S-Z 5V: la conexión directa GPIO26 no liberaba correctamente con nivel HIGH de 3.3 V. Solución **probada aisladamente**: BC548 NPN, resistencia 1 kΩ GPIO26→base, emisor a GND y colector a IN del módulo; relé VCC 5V y GND común. **Prueba inicial histórica:** contactos COM/NO/NC sin carga. **Montaje posterior confirmado por el usuario:** LED de baja tensión con resistencia de **330 Ω** para representar iluminación; no describir el montaje actual como necesariamente sin carga.
- PIR compatible HC-SR501/BISS0001, OUT→GPIO27: prueba independiente PIR/relé completada con **retención funcional aproximada de 10 s**, renovada ante nueva detección. En `firmware/pruebas/pir_rele_10s/pir_rele_10s/pir_rele_10s.ino`, `HOLD_TIME_MS=10000UL`; `lastMotionTime` se renueva mientras OUT está HIGH y el apagado exige OUT LOW y al menos 10000 ms desde la última detección registrada. Esto no caracteriza cuantitativamente el tiempo real ni el temporizador interno del PIR. Calibración final, rango y caracterización temporal pendientes; no hay prueba suficiente de defecto.
- ESP32 #1 con PIR físico, BC548, relé y LED para simular iluminación; integración funcional con firmware y multinodo observada en P13, sin evidencia específica guardada; caracterización cuantitativa pendiente. El sketch de prueba controla GPIO27 (PIR) y GPIO26 (relé), pero no documenta el cableado del LED, el borne conmutado COM/NO/NC ni su alimentación exacta: **pendientes de confirmación**, sin inferirlos de esquemas históricos de Lab1. No equiparar prueba funcional independiente con validación final.
- **Tres ESP32 físicos son obligatorios**, pero solo ESP32 #1 necesita PIR, relé y LED físicos. El profesor **autorizó simular el valor PIR** de ESP32 #2 y #3; no comprar periféricos duplicados. Los tres ESP32 deberán enviar mensajes reales por la red con identidad propia; etiquetar los valores PIR simulados y no presentarlos como mediciones reales.
- Seguridad: solo USB/baja tensión; desconectar antes de modificar conexiones; no conectar red eléctrica a bornes del relé.

## Plan de trabajo y restricciones reales (20–28 de octubre)

**Restricciones:** jueves, viernes y sábado se cocina habitualmente hasta las 13:00; el domingo visita a su madre y vuelve a la computadora a las **19:00–20:00**; martes clase nocturna hasta las 21:00, miércoles laboratorio de Software de Telecomunicaciones **19:20–21:00**. Habitualmente está despierto hasta las **00:00**, pero **terminar trabajo técnico ~22:30–23:00** salvo necesidad y dejar margen. Reservar **20 min diarios en total** para Arknights Endfield + Wuthering Waves; proyecto personal **45–60 min** en días factibles y **60–90 min** viernes/sábado; miércoles de revisión puede ser descanso.

| Día | Laboratorio — bloque recomendado | Meta |
|---|---|---|
| **Mar 20** | **Completado** | Laptop: siete opciones del menú, REST/MQTT, SQL, concurrencia y apagado de servicios verificados |
| **Mié 21** | **Completado** | Revisión de avance realizada sin observaciones; confirmar corrección del diagnóstico `demo-acl.php` cuando se retome |
| **Jue 22** | Bloques previstos: 13:00–14:15, 17:30–20:00, reserva 21:00–22:30 | Inicio E1 documentado; no dar por completadas calibración y caracterización cuantitativa del tiempo por el plan original |
| **Vie 23** | Cierre parcial; bloques previstos 13:30–17:00 y 20:00–22:30 | Tres placas diagnosticadas, E1 HTTPS probado en escritorio; fallo Claro persiste, hotspot obtiene IP pero HTTPS pendiente. Adoptada laptop/hotspot |
| **Sáb 24** | Cierre de continuidad | P13 y P14 ejecutados y observados, sin evidencias específicas guardadas; P15 documentado: 336 filas y dos campañas internas PHP |
| **Dom 25** | Solo 20:30–22:00, flexible | Revisar criterios T05–T08 y preparar P16; preservar evidencias |
| **Lun 26** | 10:00–12:30, 17:30–20:00, reserva 21:00–22:30 | Corregir/verificar registrador COM5 antes de otra captura; prueba de carga controlada, revisión T05–T08 y diagramas |
| **Mar 27** | 14:00–17:15, 21:30–22:30 | P16: informe con métricas y límites verificados, ensayo de demostración; **cierre técnico ~22:30** |
| **Mié 28** | 17:00–18:15 y evaluación 19:20–21:00 | **Entrega final presencial:** solo ensayo y demostración con laptop, sin implementación nueva |

**Contingencias:** si falla una placa, no presentar un cliente de computadora como ESP32 físico; comunicar el bloqueo. PIR simulado en los ESP32 #2/#3 está autorizado, pero la comunicación de las tres placas debe ser real. Distinguir D2 LAN/TLS ya integrado del broker D1 local. Priorizar los criterios del laboratorio sobre la migración del repositorio.

El cronograma conserva la disponibilidad; las próximas acciones se centran en carga controlada, COM5, T05–T08 y P16. P13/P14 fueron ejecutados y observados físicamente, pero no tienen evidencias específicas guardadas; no están completamente documentados. No volver al panel Claro como requisito; la red de demostración es el hotspot Android con laptop.

## M. Separación de repositorios y colaboración (pendiente)

- Crear un repositorio GitHub exclusivo para el Laboratorio 2, sin alterar el historial o archivos históricos de Lab1. Elegir un método que preserve el historial **pertinente** de `lab2/` y verificar los cambios de rutas y dependencias.
- Migrar código, pruebas, firmware y documentación a Lab2. Revisar `.gitignore` y nunca transferir credenciales, contraseñas, tokens, colas ni configuraciones privadas al remoto. Comprobar funcionamiento del nuevo repo en escritorio y laptop.
- Preparar y enviar **dos invitaciones de colaboración**. **No asignar tareas ni exigir commits** a los compañeros: el usuario asume desarrollo, hardware, pruebas y documentación. La invitación concede acceso, no atribuye commits.
- Actualizar enlaces del ROADMAP y `PROJECT_CONTEXT.md` cuando exista URL definitiva. Retirar `lab2/` del repositorio original **solo después** de validar la migración; aplazar cualquier paso destructivo si compromete la entrega final del 28/10.
- El asistente solo prepara archivos/comandos; **no realiza modificaciones, commits ni pushes** en repositorios remotos.

## Reglas de implementación y cierre

- `node_id` vinculado a credencial, `seq` único incluso tras reinicio, reintentos con contenido inalterado; `timestamp` Unix UTC válido o `null`, `received_at` lo asigna el servidor; PIR `value` 0/1, `rssi` -127..0/null. Distinguir telemetría PIR simulada (nodos #2/#3 autorizados) de mediciones PIR físicas (#1).
- MQTT y REST comparten el mismo `TelemetryService` y transacción PDO. **D1 solo 127.0.0.1:1884**; D2 LAN/TLS en 8883 integrado con #3. QoS1/PUBACK del cliente empleado puede anteceder commit SQL; no se garantiza pérdida cero ante corte brusco.
- No subir `lab2/config/database.local.php`, `test-tokens.local.json`, `admin.local.json`, `mqtt.local.php`, `mqtt-private/`, Wi-Fi, passwords, colas/auditoría privadas.
- El código verificado prevalece sobre el roadmap; revisar Git y pruebas antes de cambios. Encargar tareas pequeñas a Codex solo cuando haga falta; no rehacer etapas completadas.
- La guía y otros PDF siguen locales/sin seguimiento en el escritorio según último `git status`; no agregarlos automáticamente. Antes de cada commit revisar `git status --short`, `git diff --cached --name-only` y `git diff --cached --check`.
