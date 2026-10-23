# ROADMAP — Laboratorio 2: backend multinodo y demostración

**Checkpoint:** cierre de sesión del 23 de octubre de 2026; revisión presencial del 21/10 completada sin observaciones. Los ensayos físicos de este cierre fueron confirmados por el usuario; no se repitieron durante esta actualización documental.
**Producto:** https://github.com/jmamanilari-cyber/lab1-iot-node  
**Continuidad:** https://github.com/FAJIFBEFJHA/ai-assisted-development-workflow/blob/main/projects/lab1-iot-node/PROJECT_CONTEXT.md  
**Commit de referencia comprobado:** `bd66035`, último commit publicado confirmado por el usuario; el historial local contiene el avance desde `4328fba`. Serie E1: `04b29a3` (firmware HTTPS), `6e3816a` (pruebas E1 y sketch PIR/relé), `bd66035` (documentación E1 y ROADMAP). `4328fba` corresponde exclusivamente a la corrección MQTT PUBACK 0/16; antecedentes `81f334b` (roadmap anterior) y `a21b529` (demostración). Estas correcciones documentales posteriores quedan locales para un commit nuevo y separado del usuario; no se modifica el historial publicado ni se realizan commits/push aquí.
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
| D2. Broker MQTT para ESP32 por LAN | Pendiente; tarea explícita de integración D2 abajo | D1 sigue en 127.0.0.1:1884; falta acceso desde la red de demostración con autenticación/ACL y recepción/persistencia de mensajes desde ESP32 físicos |
| E0. Diagnóstico de hardware | Parcial; tres placas comprobadas en diagnóstico básico | Prueba independiente PIR/relé y retención funcional aproximada de 10 s verificadas; montaje posterior con LED y 330 Ω; caracterización cuantitativa, calibración e integración pendientes |
| E1. Firmware de un ESP32 | Implementado y parcialmente validado en #2; HTTPS portátil bloqueado por preparar red/servidor | 201 seq 37–39 en escritorio; hotspot obtiene IP en 1227 ms pero HTTP=-1 con destino anterior. MQTT físico sigue pendiente |
| E2. Tres nodos físicos | Pendiente de integración conjunta | #1 físico; #2/#3 PIR simulado con radio real; falta completar firmware #1/#3 y ensayo concurrente |
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

**Al cierre 23/10:** la preparación local previa de PHP/MariaDB y herramientas sigue siendo válida como antecedente. Apache/HTTPS, certificado, reglas de acceso y conectividad desde la nueva red del hotspot todavía deben adaptarse y verificarse en esta laptop. No confundir los ensayos locales del 20/10 con la demostración portátil definitiva.

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
  Las transmisiones físicas posteriores constan abajo; siguen pendientes las pruebas
  C++ en host, reinicio/cortes reales y evidencia experimental completa. E1 no se considera completada ni prueba tres nodos.
- Git confirma `4328fba`: corrección de diagnóstico PUBACK ya publicada. La
  incidencia descrita arriba es histórica; no se repitieron aquí las 12 ACL.

## Cierre 23/10 — E1, diagnóstico Wi-Fi y arquitectura portátil

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

### Prioridades por dependencias

Esta lista ordena los pendientes de E0/E1/E2/D2/F/G existentes, sin sustituirlos ni repetir backend ya probado. **Completado** exige evidencia; **parcial** separa lo probado de lo faltante; **pendiente** aún requiere ejecución; **bloqueado** depende de requisitos anteriores sin verificar.

| Orden | Etapa / tarea concreta | Estado al cierre | Dependencia / criterio |
|---|---|---|---|
| P01 | Conectar laptop al hotspot Android exclusivo de 2,4 GHz | Pendiente; primera acción | Mantener ESP32 y laptop en el mismo hotspot |
| P02 | Registrar IPv4 de laptop y subred | Pendiente | P01; no reutilizar automáticamente `192.168.0.3` ni asumir que las IP del ensayo persisten |
| P03 | Comprobar comunicación entre clientes del hotspot | Pendiente | P01–P02; verificar ESP32–laptop, no solo acceso a Internet; detectar aislamiento |
| P04 | Preparar/verificar Apache, PHP, MariaDB y HTTPS de laptop | Parcial: backend local ya probado; HTTPS portátil pendiente | P03 para acceso real desde placa; conservar BD existente, CA/SAN y secretos privados |
| P05 | Adaptar destino del firmware a la laptop sin debilitar TLS | Bloqueado por IP/servidor portátil sin confirmar | P02 y P04; mantener nombre esperado `telemetry.lab.test` y CA; no HTTP plano ni modo inseguro |
| P06 | Ajustar reglas de acceso de Apache a la nueva red | Pendiente | P02 y P04; reglas basadas en la subred real, comprobar también acceso de red/firewall |
| P07 | Validar SNTP y transmisión HTTPS en hotspot | Bloqueado | P03–P06; primero IP válida, luego reloj y TLS/API; no borrar el pendiente |
| P08 | Comprobar HTTP 201 y secuencias consecutivas | Bloqueado | P07; un pendiente ya persistido puede responder 200 antes de muestras nuevas 201 |
| P09 | Definir cómo localizar el servidor si cambia su IP | Pendiente | P02–P03; estrategia reproducible y ensayo de reconexión, sin depender de DNS inexistente ni omitir validación TLS |
| P10 | Tres arranques RESET/EN y registro de tiempo real hasta IP | Bloqueado hasta validar entorno | P08–P09; seguir `WIFI_E1_VALIDACION.md`, registrar esperas/fallos, no afirmar éxito por un solo arranque |
| P11 | Continuar integración ESP32 #1 (PIR físico, BC548, relé, LED y comunicación) | Parcial: prueba independiente y retención funcional aproximada de 10 s verificadas | Desarrollo local en paralelo, sin esperar P10; pruebas de red cuando P03–P07 estén disponibles. Caracterización cuantitativa del tiempo y calibración final pendientes |
| P12 | Completar firmware previsto de ESP32 #3, identidad propia y PIR simulado | Pendiente | Desarrollo local en paralelo, sin esperar P10; pruebas de radio real cuando P03–P07 estén disponibles; etiqueta explícita de simulación |
| D2 | Exponer broker MQTT a la red de demostración manteniendo autenticación y ACL; verificar conexión, recepción y persistencia desde ESP32 físicos | Pendiente; no implementado | P02–P03 y servicios PHP/MariaDB de laptop disponibles; puede avanzar en paralelo a P10/P11/P12. Aceptación: conexión/publicación autorizada, rechazos de autenticación/ACL y mensaje recibido y persistido en MariaDB; D1 loopback no basta |
| P13 | Ensayo conjunto de tres nodos conectados al hotspot | Bloqueado por P10, P11, P12 y D2 | Conectividad y servicios verificados, tres arranques de #2 y pruebas necesarias; tres placas físicas, HTTPS y MQTT seguro por LAN con recepción/persistencia comprobadas |
| P14 | Verificar persistencia, reintentos e interrupciones | Pendiente | P13; ensayos reales y contenido idéntico en reintentos, sin borrar NVS ni resembrar BD |
| P15 | Recopilar evidencias experimentales, T05–T08 y métricas | Parcial: evidencia backend y ensayos aislados disponibles | P13–P14, D2/F según transporte; throughput y p50/p95 siguen pendientes |
| P16 | Preparar y ensayar demostración docente e informe final | Pendiente | P15 y G; laptop como servidor, distinguir resultados y límites; cierre técnico 27/10 ~22:30 |

Si el hotspot aísla clientes, **P03 bloquea el transporte local**: documentar y resolver esa conectividad antes de tocar certificados o multiplicar reintentos. No bloquea el desarrollo local de #1/#3. D2 se detalla una sola vez como tarea de integración en esta lista, conservando su etapa en la tabla general; la nueva evidencia ACL 12/12 posterior a `4328fba` sigue pendiente y no se presume cubierta por D2. Migración M sigue vigente, sin prioridad sobre servidor portátil, integración y entrega.

**Primera tarea de la siguiente sesión:** P01 y registro de P02: conectar la laptop al hotspot Android de 2,4 GHz e identificar su IPv4/subred. Esta corrección solo actualiza seguimiento, sin implementar P01–P16 ni D2.

## Hardware real — etapa E0

- **Tres ESP32 disponibles y comprobadas mediante diagnósticos básicos**; una identificada como NodeMCU ESP-32S V1.1 (38 pines). La integración de los tres nodos y su transmisión concurrente siguen pendientes.
- Relé JQC-3FF-S-Z 5V: la conexión directa GPIO26 no liberaba correctamente con nivel HIGH de 3.3 V. Solución **probada aisladamente**: BC548 NPN, resistencia 1 kΩ GPIO26→base, emisor a GND y colector a IN del módulo; relé VCC 5V y GND común. **Prueba inicial histórica:** contactos COM/NO/NC sin carga. **Montaje posterior confirmado por el usuario:** LED de baja tensión con resistencia de **330 Ω** para representar iluminación; no describir el montaje actual como necesariamente sin carga.
- PIR compatible HC-SR501/BISS0001, OUT→GPIO27: prueba independiente PIR/relé completada con **retención funcional aproximada de 10 s**, renovada ante nueva detección. En `firmware/pruebas/pir_rele_10s/pir_rele_10s/pir_rele_10s.ino`, `HOLD_TIME_MS=10000UL`; `lastMotionTime` se renueva mientras OUT está HIGH y el apagado exige OUT LOW y al menos 10000 ms desde la última detección registrada. Esto no caracteriza cuantitativamente el tiempo real ni el temporizador interno del PIR. Calibración final, rango y caracterización temporal pendientes; no hay prueba suficiente de defecto.
- ESP32 #1 con PIR físico, BC548, relé y LED para simular iluminación; integración con el firmware definitivo/multinodo pendiente. El sketch de prueba controla GPIO27 (PIR) y GPIO26 (relé), pero no documenta el cableado del LED, el borne conmutado COM/NO/NC ni su alimentación exacta: **pendientes de confirmación**, sin inferirlos de esquemas históricos de Lab1. No equiparar prueba funcional independiente con validación final.
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
| **Sáb 24** | 13:30–17:00 y 19:30–22:00 | Prioridad P01–P10: servidor portátil y E1 HTTPS reproducible; avanzar D2 MQTT seguro por LAN después de resolver conectividad |
| **Dom 25** | Solo 20:30–22:00, flexible | Documentación o migración de repositorios, sin comprometer la entrega |
| **Lun 26** | 10:00–12:30, 17:30–20:00, reserva 21:00–22:30 | Tres ESP32 físicos (PIR simulado permitido en #2/#3); T05–T07, diagramas y evidencias |
| **Mar 27** | 14:00–17:15, 21:30–22:30 | T08, throughput, latencias p50/p95, informe, commits; **cierre técnico ~22:30** |
| **Mié 28** | 17:00–18:15 y evaluación 19:20–21:00 | **Entrega final presencial:** solo ensayo y demostración con laptop, sin implementación nueva |

**Contingencias:** si falla una placa, no presentar un cliente de computadora como ESP32 físico; comunicar el bloqueo. PIR simulado en los ESP32 #2/#3 está autorizado, pero la comunicación de las tres placas debe ser real. Si D2 LAN se retrasa, distinguirlo claramente del broker D1 local. Priorizar los criterios del laboratorio sobre la migración del repositorio.

El cronograma mantiene la disponibilidad y metas vigentes; su ejecución queda subordinada a P01–P16 y D2, permitiendo desarrollo local paralelo de #1/#3 antes de completar P10. No volver al panel Claro ni solicitar separación de bandas como requisito del laboratorio. La red principal de ensayo y presentación es el hotspot Android con laptop.

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
