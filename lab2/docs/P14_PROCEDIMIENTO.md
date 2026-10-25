# P14: nueva evidencia fisica de interrupcion y recuperacion

Este procedimiento prepara **nuevas observaciones del operador**. La preparacion
de las herramientas y sus pruebas unitarias no ejecutan ni aprueban P14.
Orden: **A (Wi-Fi manual), B (indisponibilidad HTTPS por bloqueo de red), C (broker D2)**. Una carpeta independiente
por ensayo, sin reutilizar P15, T05 ni resultados de carga.

## B y C automaticos: un comando por ensayo

Herramienta: `scripts/p14-auto.py`, con control Windows aislado en
`scripts/p14-control.ps1`. Reutiliza `p14-evidence.py`, su trabajador de captura,
`capture-serial.py`, las consultas PDO READ ONLY y el analizador existente.
No automatiza A. No ejecutar dos ensayos o capturadores simultaneamente.

Desde `C:\Proyecto\lab1-iot-node`, comprobar primero las etiquetas de los nodos
y las tomas USB guardadas (`capture-serial.py --list`). `--confirm-locations`
afirma que las placas siguen en esas tomas; no presupone numeros COM.
Mantener encendidos los tres nodos y todos los servicios. No ejecutar LOAD/C1.

Para B, obtener del hotspot o de observaciones originales las IP actuales de
**esp32-01 y esp32-02**, en ese orden. No inferirlas del numero COM ni usar la IP
de la laptop, del hotspot o de esp32-03. Las siguientes variables son entradas
del operador y deben contener esas dos IP reales confirmadas:

```powershell
$ip01 = 'IP_REAL_ESP32_01'
$ip02 = 'IP_REAL_ESP32_02'
```

B requiere una consola PowerShell **como administrador desde el inicio**.
No hay solicitud de elevacion durante la prueba. Si no hay permisos, no se
puede aislar el bloqueo, las IP no son privadas/distintas/en enlace local o el
firewall no esta habilitado, se rechaza antes de abrir USB. No se altera la
configuracion del firewall para eludir esos requisitos.

Previsualizaciones de solo lectura (no crean evidencia fisica, no abren USB):

```powershell
python .\lab2\scripts\p14-auto.py run B --https-clients $ip01 $ip02 --dry-run
python .\lab2\scripts\p14-auto.py run C --dry-run
```

Muestran PID/creacion observados, ejecutable/configuracion D2, puertos,
dependencias y el identificador unico del recurso que se usaria. Una ejecucion
real obtiene un plan nuevo; no reutiliza el identificador del dry-run.

Ensayos fisicos: primero B; revisar su resultado y restauracion antes de C.

```powershell
python .\lab2\scripts\p14-auto.py run B --https-clients $ip01 $ip02 --confirm-locations
python .\lab2\scripts\p14-auto.py run C --confirm-locations
```

No se necesita marcar eventos manualmente. Secuencia automatica:

1. Verificar Apache, PHP, MariaDB, D2 y el suscriptor/transportador MQTT.
   Abrir captura e iniciar **STABILIZING**, sin interrumpir servicios. Conservar
   toda la salida de apertura, incluidos fragmentos, corrupcion y duplicados.
   Antes de `ready`, cada evento ilegible mueve el candidato basal global a
   despues de ese evento; los tres nodos deben aportar de nuevo dos claves
   **distintas**, completas, confirmadas serie/SQL y con identidad correcta.
   Se exige progreso observado de al menos 10 s entre primeras confirmaciones
   de secuencias distintas por nodo (`--stability-seconds 2..60`) y progreso
   reciente (30 s, o dos veces ese minimo si es mayor). Dormir o repetir una
   secuencia no cumple esas condiciones. No se aceptan acuses concatenados.
   Preparacion y baseline comparten el limite de 180 s, configurable mediante
   `--baseline-timeout 30..300`; el ruido continuo agota ese limite sin actuar.
   Solo al pasar SQL y estabilidad se fija `baseline_start_utc` y se marca
   `ready`. `start_utc` sigue siendo el inicio original de captura. Se guardan
   candidatos, motivos, conteos iniciales y comprobaciones en `preparation`.
   La evaluacion y consultas experimentales usan esa ventana ya congelada;
   nunca vuelven a desplazarla tras `ready`. Cualquier corrupcion desde su
   inicio, incluida la posterior a `ready`, sigue impidiendo PASS. Los logs y
   hashes cubren tambien toda la preparacion, sin borrar ni reconstruir datos.
   Una baseline incompleta aborta sin interrumpir componentes.
2. Marcar `ready` e `interrupt-intent`; persistir `resources.json` **antes**
   de actuar. B crea exclusivamente una regla propia `Lab2-P14-B-<UUID>`:
   bloqueo de entrada TCP 8443, programa Apache y las dos IP especificadas.
   No se detiene Apache ni se modifican reglas existentes. C retiene el handle
   del proceso D2 y comprueba exe, config, hash, PID, creacion, listeners y
   ausencia de pertenencia a un servicio Windows antes de terminarlo.
   Es una interrupcion abrupta del broker, no una parada ordenada.
3. Verificar la interrupcion; B requiere regla propia y fallo HTTP de transporte
   (`code <= 0`, `ack=0`) de **ambos** nodos HTTPS. C requiere ausencia de
   listeners D2 y reintento MQTT serie. Solo entonces marcar `down`.
   Timeout 90 s (`--down-timeout 15..120`). Si falla, restaurar de inmediato.
4. Mantener la interrupcion al menos 60 s desde `down`, comprobando estados
   repetidamente. Se conservan las marcas reales; los costes de comprobacion
   pueden alargar ligeramente ese intervalo. MariaDB, PHP, Apache, el padre
   suscriptor y el Mosquitto ajeno conservan su identidad. En B tambien D2.
5. Marcar `restore-intent`. B retira solo su regla despues de verificar todos
   sus filtros. C arranca exclusivamente el broker con el mismo exe y `-c`
   absoluto, usando el patron `Start-Process` de `start-lab2.ps1`; no llama a
   `Stop` general, `Prepare` ni pruebas de seguridad. Rechaza puertos ocupados
   o procesos D2 ambiguos; no termina el Mosquitto Windows de 1883/9883.
6. Verificar listeners y suscriptor; marcar `restored`. Timeout de verificacion
   45 s (`--restore-timeout 10..90`). `restored` describe disponibilidad local;
   la recuperacion desde los ESP32 se acredita despues por serie y SQL.
7. Observar hasta 180 s (`--recovery-timeout 30..180`) o hasta reunir todos los
   requisitos del analizador: claves nuevas persistidas, pendientes y acuses,
   continuidad del transporte no afectado, sin discrepancias. Finalmente
   cerrar captura limpiamente y analizar. Restaurar servicios por si solo
   **nunca acredita PASS**. Un acuse del nodo afectado durante el intervalo
   `down`/`restore-intent` contradice el aislamiento y produce FAIL.

La restauracion se intenta en `finally` **antes** de cerrar captura o consultar
SQL para el informe. Si falla una accion se conserva evidencia parcial y el
resultado es INCOMPLETO, o FAIL si el analisis encuentra contradicciones.
Codigos: 0 PASS; 1 FAIL; 2 INCOMPLETO/error. No repetir automaticamente un ensayo
que termine con restauracion no confirmada.

## Recuperacion de emergencia automatica B/C

Ctrl+C solicita restauracion y cierre; esperar su resultado. Si el interprete
se termina forzosamente o se reinicia Windows, conservar el directorio que
imprimio la herramienta y ejecutar (B requiere administrador):

```powershell
python .\lab2\scripts\p14-auto.py recover --run 'DIRECTORIO_EXACTO_DEL_ENSAYO'
python .\lab2\scripts\p14-evidence.py finish --run 'DIRECTORIO_EXACTO_DEL_ENSAYO' --abort
```

Si el ensayo ya estaba cerrado, sustituir `finish --abort` por `analyze`.
`recover` no aprueba el ensayo ni detiene ningun proceso: retira solo la regla
con nombre, descripcion y filtros exactos del registro, o restaura D2 si esta
ausente y no hay conflictos. Si un proceso desconocido ocupa D2 o cambia su
configuracion, rechaza la accion y requiere revision manual. No borrar reglas
por puerto, no terminar procesos por nombre y no usar `start-lab2 -Mode Stop`.
Un bloqueo del SO impide ejecutar recover mientras el ensayo automatico sigue
activo; se libera incluso al terminar el interprete abruptamente.

Evidencias nuevas bajo `lab2/logs/evidence/P14/<UTC>-B|C-<id>/`:
`metadata.json`, `resources.json` (plan y estado de restauracion), `actions.json`
(intenciones y confirmaciones reales), estados de servicios, snapshots SQL,
logs serie originales, estado de cierre y hashes; resultados en
`analyses/<id>/evidence.json`, `sequences.csv` y `summary.md`.
Las intenciones no confirmadas permanecen registradas. Los logs privados del
broker restaurado quedan en `.runtime/start-lab2/Lab2-P14-C-*.log`, excluidos
de Git; no se incorporan al informe. Nunca se vuelcan comandos del suscriptor,
tokens ni configuraciones privadas. Los ayudantes PowerShell usan
`-ExecutionPolicy Bypass` solo en el proceso hijo; no cambian politicas
persistentes ni elevan privilegios.

### Validacion disponible y pendiente

```powershell
python -m unittest discover -s lab2/tests -p 'test_p14*.py' -v
powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File .\lab2\tests\test_p14_control.ps1
```

Pruebas con mocks/temp: baseline incompleta, fallo de interrupcion/restauracion,
regla propia/ajena, PID reutilizado/servicio Windows, timeout de recuperacion,
orden de marcas, 60 s instrumentados, evidencia parcial, bloqueo concurrente y
dry-run sin acciones. No constituyen una ejecucion fisica de B o C.

Pendiente validar con los ESP32: alcance efectivo de Defender Firewall para
esas IP y conexiones TLS, recuperacion HTTPS de ambos nodos, reconexion del
suscriptor tras restaurar D2, PUBACK y claves SQL concordantes; continuidad
MQTT durante B y HTTPS durante C. La regla por IP no demuestra por si sola
identidad del cliente: la correspondencia IP/nodo la confirma el operador.
PUBACK solo confirma broker; no demuestra escritura SQL. Se informa de
pendientes, huecos y filas ausentes al corte, sin afirmar ausencia global de
perdidas ni igualdad de todo el JSON pendiente sin preimagen completa.

## Diagnostico P14-C de las ejecuciones 2026-10-25 (sin repetir ensayos)

Original analizado: `20261025T013642Z-C-8f2292ae45bf`, analisis
`20261025T014131Z-5bab5626`. El unico impedimento original para PASS es la
cobertura serie de **esp32-02**, no la recuperacion de MQTT. Se conservan
intactos el resultado INCOMPLETO, los originales y sus hashes.

| Nodo | Claves recuperadas serie/SQL | Continuidad HTTPS durante caida | Pendiente MQTT recuperado |
|---|---:|---:|---:|
| esp32-01 | 17 | 6 | no aplica |
| esp32-02 | 13 | 6 | no aplica |
| esp32-03 | 7 | no aplica | 3046: 1/1 |

El log de esp32-02, COM5 durante ese ensayo, tiene lineas 19, 23, 24 y 25 con
64 caracteres U+FFFD cada una, seguidos de `LADO`. No contienen claves ni acuses
legibles. Las secuencias 2764, 2768, 2769 y 2770 existen individualmente en el
snapshot SQL con device_id=2, sin duplicados ni marcador de fixture, a las
01:40:10.191970, 01:40:54.354388, 01:41:05.506011 y 01:41:17.079305 UTC.
La proximidad temporal con las lineas corruptas es evidencia de coincidencia;
**no permite reconstruir ni afirmar el contenido perdido o su HTTP 201**.

Se verifico la secuencia de acciones y marcas: intervalo down/restore-intent
63,482 s; restored/cierre 180,010681 s. D2 cambio de PID 8356 a 13772 con nueva
creacion, ambos verificados y ajenos al servicio Windows. Apache, MariaDB,
PHP, suscriptor y Mosquitto 1883/9883 conservaron los propietarios observados.
El snapshot final se obtuvo entre 01:41:31.181433 y 01:41:31.209140 UTC y
conserva el filtro de la ventana experimental ya cerrada.

MQTT TLS reconecto a las 01:40:40.200 UTC, 129,531 s despues de `restored`.
El pendiente 3046 se encolo a las 01:40:40.218, se persistio en SQL a las
01:40:41.291218 y produjo PUBACK a las 01:40:41.430. PIR/RSSI concuerdan con
la muestra NVS registrada antes de recuperar. Esto acredita esa recuperacion;
PUBACK sigue sin equivaler a confirmacion SQL. Los reintentos crecientes
observados son compatibles con el backoff existente. No se demostro un fallo
causal del firmware esp32-03 y no se prepara ni carga un cambio especulativo.

La ejecucion `20261025T012022Z-C-4262bf100ce4` tambien tiene ocho huecos serie
internos de esp32-02, ademas del timeout de State. El cruce con todas las filas
SQL de la ventana revela tambien 2688 sin evento legible, fuera del rango
inferido de las claves serie. Quitar solamente el falso aborto no completaba
esas evidencias. Su pendiente MQTT 2963 si se recupero y persistio.

Correcciones instrumentales actuales:

- El evaluador explicita lineas ilegibles, claves SQL sin evento serie
  (incluidos extremos del rango) y estado por nodo. No elimina datos ni
  sustituye evidencia serie por SQL para acreditar un acuse.
- Una baseline corrupta/parcial no autoriza interrupciones. En B/C automaticos
  se permite recuperarse del ruido inicial mediante STABILIZING y una nueva
  ventana limpia anterior a `ready`, conservando integros los originales.
  Despues de `ready` no se permite reestablecer la baseline para ocultar ruido.
- Los rechazos controlados conservan su motivo: AutoError hereda ValueError y
  antes se atrapaba como fallo generico. Solo TimeoutExpired produce ahora
  ControlTimeout; JSON invalido, rechazo de permisos o cambio de identidad
  nunca se silencian por estar cerca del fin de observacion.
- P14 activa `capture-serial.py --diagnostics`: contadores RX, histograma,
  errores UTF-8 por linea y hasta ocho muestras del primer tramo de bytes
  demostrado invalido (hasta ocho bytes/muestra). No guarda prefijos ASCII ni
  contexto de mensajes, ni muestras de lineas redactadas. Los logs serie
  conservan su comportamiento y los duplicados reales. No se usa
  `--diagnostic-bytes`, que habilitaria muestras crudas de contexto.

La decodificacion historica con reemplazo es irreversible y no habia muestras
de bytes del puerto: no se puede decidir retrospectivamente entre firmware,
UART, adaptador, cable o controlador USB. La coherencia de contadores demuestra
contabilizacion, no integridad textual de las lineas. No declarar solucionada
la causa fisica ni repetir P14 para diagnosticarla.

Siguiente comprobacion minima, **pasiva y sin interrumpir servicios**, cerrando
primero otros monitores USB y confirmando las tomas guardadas:

```powershell
python .\lab2\scripts\capture-serial.py --confirm-locations --diagnostics --duration 120 --no-console-lines
```

Conservar los nuevos `*.diagnostic.log`, resumen RX y logs de esa captura en
`lab2/logs/serial/<UTC>/`; no mezclarlos con los P14 historicos. Para esp32-02
se necesita: bytes/LF/lineas/escrituras, invalid_utf8_lines, replacement_lines,
first_invalid_hex, raw_line_bytes y timestamps. Esto distingue bytes invalidos
realmente entregados por read() de U+FFFD ya codificado por la fuente; no
identifica por si solo en que capa anterior se corrompieron. Si la corrupcion
persiste y no se logra estabilidad, o reaparece durante el experimento,
diagnosticar USB/UART pasivamente. El ruido transitorio de apertura puede
quedar documentado en la preparacion sin invalidar una ventana posterior
demostrablemente estable. No hay motivo para modificar NVS.

## Flujo manual anterior (A; B solo con Apache dedicado)

Las instrucciones siguientes conservan el flujo manual existente. En la
arquitectura actual Apache comparte 80/443: **no usar la parada manual de B**;
usar el bloqueo de red anterior. A permanece exclusivamente manual.

## Dependencias y aislamiento previo

Desde `C:\Proyecto\lab1-iot-node`, usar Windows PowerShell 5.1, Python con el
pyserial ya instalado y `C:\xampp\php\php.exe` con PDO MySQL. Las consultas usan
`Lab2\Database::connect()` y una transaccion READ ONLY; no hay generacion de
fixtures ni inicializacion de bases/colas.

1. Cerrar otros monitores serie y capturadores. Mantener alimentados los ESP32.
   No reprogramar, reiniciar, borrar NVS ni cambiar credenciales para forzar una
   recuperacion. Un RESET adicional cambiaria el alcance del ensayo; abortarlo
   y documentarlo como intervencion adicional.
2. Verificar las etiquetas fisicas y asociaciones actuales:

   ```powershell
   python .\lab2\scripts\capture-serial.py --list
   ```

   Si hay dudas, ejecutar la captura habitual con `--save`, asociar manualmente
   y cerrar con Ctrl+C **antes** de iniciar P14. `--confirm-locations` declara
   que las placas siguen en las tomas USB guardadas, no identifica por numero COM.
3. Si es necesario, levantar el entorno normalmente **antes del ensayo** con
   `start-lab2.ps1 -Mode Start`. Verificar que no haya otros ensayos LOAD/C1 en
   ejecucion. No usar `start-lab2.ps1 -Mode Stop` para interrumpir un componente.
4. Comprobar, sin abrir USB ni cambiar servicios:

   ```powershell
   python .\lab2\scripts\p14-evidence.py sql-check
   python .\lab2\scripts\p14-evidence.py inspect
   ```

   Si CIM/sockets no se pueden consultar, usar una consola con permisos de
   consulta suficientes; la herramienta no eleva permisos ni modifica Windows.
   No iniciar interrupciones con identidad/aislamiento no verificables.

### Limites que deben resolverse ANTES de interrumpir

- **A:** usar un hotspot dedicado al ensayo; apagarlo afecta tambien a la laptop
  y a cualquier otro cliente conectado. No cambiar SSID, clave, banda ni TLS.
- **B:** parar Apache puede afectar 80/443 y todos sus virtual hosts, ademas de
  8443. La inspeccion muestra puertos/PID observados, pero **no prueba que Apache
  sea exclusivo del laboratorio**. Si sirve proyectos ajenos y no puede pararse
  aisladamente, **no ejecutar B**. Registrar esa limitacion; no editar su
  configuracion para fabricar aislamiento. `--dedicated-apache` es una
  confirmacion expresa del operador sobre una instancia ya dedicada.
- **C:** D2 debe tener ambos listeners 1884/8883, un unico PID, ejecutable y
  `-c config/mqtt-d2/broker.conf` verificados, sin pertenecer directamente a un
  servicio Windows. La inspeccion reutiliza `mqtt-d2.ps1 -Mode Validate` y
  comprueba proceso/sockets. El Mosquitto ajeno en 1883/9883 no se detiene.
  Un PID no verificable o un puerto D2 ocupado por otro proceso bloquean el
  inicio. No usar `Stop-Service mosquitto` ni detener por nombre de ejecutable.

## Secuencia comun de marcas

Primero iniciar **solo el ensayo A**:

```powershell
python .\lab2\scripts\p14-evidence.py start A --confirm-locations
```

Copiar el directorio que imprime y asignarlo literalmente a una variable de
PowerShell; por ejemplo, sustituyendo `DIRECTORIO_MOSTRADO`:

```powershell
$run = 'C:\Proyecto\lab1-iot-node\lab2\logs\evidence\P14\DIRECTORIO_MOSTRADO'
```

La captura existente se ejecuta en segundo plano, sin competir por la entrada
de consola. Maximo por defecto: 900 segundos; `--max-seconds` admite 120..1800.
No se imprimen las lineas de telemetria en consola: se guardan completas con
la redaccion de secretos propia del capturador. No se guardan configuraciones
privadas ni muestras hexadecimales.

1. **Baseline:** esperar 30-45 s, hasta tener al menos dos seq distintas con
   HTTP 200/201 ack=1 o PUBACK, y las correspondientes filas SQL por nodo:

   ```powershell
   python .\lab2\scripts\p14-evidence.py mark --run $run --event ready
   ```

   Si devuelve INCOMPLETO/error, **no interrumpir nada**. Revisar
   `capture-console.log`, `capture-status.json` si existe y el snapshot
   `sql-ready-attempt-*.json`. Puede repetirse `ready` mientras no se haya aceptado.
2. **Antes de tocar el componente**, registrar intencion:

   ```powershell
   python .\lab2\scripts\p14-evidence.py mark --run $run --event interrupt-intent
   ```

   Realizar la accion manual indicada en la tabla inferior y, una vez terminada:

   ```powershell
   python .\lab2\scripts\p14-evidence.py mark --run $run --event down --confirm-action
   ```

   Si la inspeccion no confirma aislamiento/estado, restaurar manualmente y
   cerrar con `finish --abort`. Las marcas son declaraciones del operador;
   los resultados de inspeccion se conservan por separado.
3. Mantener la interrupcion **45-60 s**, observando fallos/reintentos reales.
   Para B/C, verificar que el transporte no afectado sigue confirmando y
   almacenando al menos dos seq durante este intervalo.
4. Registrar intencion de restauracion **antes** de restaurar:

   ```powershell
   python .\lab2\scripts\p14-evidence.py mark --run $run --event restore-intent
   ```

   Restaurar manualmente el mismo componente y despues:

   ```powershell
   python .\lab2\scripts\p14-evidence.py mark --run $run --event restored --confirm-action
   ```

5. Esperar **120-180 s**: el backoff existente puede alcanzar unos 61 s y puede
   intervenir UDP/SNTP/TLS. No reiniciar las placas para obtener un PASS.
   Se requieren dos claves recuperadas por nodo y al menos una insercion SQL
   nueva despues de la intencion de restaurar. Terminar:

   ```powershell
   python .\lab2\scripts\p14-evidence.py finish --run $run
   $LASTEXITCODE
   ```

## Intervenciones y pruebas esperadas por escenario

| Ensayo | Interrupcion MANUAL | Restauracion MANUAL | Evidencia obligatoria observada |
|---|---|---|---|
| A | Desactivar hotspot Android | Reactivar el mismo hotspot 2,4 GHz | Wi-Fi perdido en los tres; GOT_IP posterior; MQTT TLS reconectado en #3; acuses y SQL nuevos de los tres |
| B | Stop de Apache dedicado en XAMPP | Start de ese Apache en XAMPP | Fallos HTTP y seq pendientes en #1/#2; acuses de esas seq y SQL posterior; MQTT #3 con al menos dos claves SQL/serie durante la caida |
| C | Detener solo el PID D2 nuevamente verificado | Arrancar D2 con el mismo broker.conf | Reintento y nueva conexion MQTT; pendiente, PUBACK y SQL de #3; HTTPS #1/#2 con al menos dos claves SQL/serie durante la caida |

Para B y C comenzar **una carpeta nueva**, tras restaurar y estabilizar todos
los servicios del ensayo anterior. Repetir las marcas anteriores:

```powershell
python .\lab2\scripts\p14-evidence.py start B --confirm-locations --dedicated-apache
# Asignar el NUEVO directorio a $run y seguir ready ... finish.

python .\lab2\scripts\p14-evidence.py start C --confirm-locations
# Asignar el NUEVO directorio a $run y seguir ready ... finish.
```

### Detencion manual precisa de D2

Antes de parar el broker, repetir `inspect` inmediatamente y verificar
`d2.verified=true`, un solo `d2.pids`, `windows_service_owned=false` y su
`created_utc`. Si difiere del preflight o no se puede verificar, no detenerlo.
El operador puede pulsar Ctrl+C en **la consola dedicada de D2**. Si la instancia
esta oculta, solo tras esa comprobacion, escribir manualmente:

```powershell
Stop-Process -Id PID_D2_RECIEN_VERIFICADO
```

Sustituir el texto por ese PID numerico exacto. Esta alternativa es una
interrupcion abrupta: indicarlo al conservar la evidencia. No terminar procesos
por nombre y no utilizar el PID del Mosquitto de Windows en 1883/9883.

Para restaurar D2, comprobar primero con `inspect` que **1884 y 8883 estan libres**.
En otra consola, validar y ejecutar manualmente la configuracion existente:

```powershell
.\lab2\scripts\mqtt-d2.ps1 -Mode Validate
& 'C:\Program Files\mosquitto\mosquitto.exe' -c (Resolve-Path .\lab2\config\mqtt-d2\broker.conf).Path
```

Mantener esa consola abierta. No usar Prepare, no ejecutar D1, no regenerar
credenciales/ACL ni borrar broker.db, spool, cursor o mensajes pendientes.

## Parada segura, fallos y revisiones

- Para abortar: **restaurar manualmente** el componente afectado y ejecutar
  `python .\lab2\scripts\p14-evidence.py finish --run $run --abort`.
- `finish` crea un archivo de parada exclusivo de esta captura; no mata servicios.
  Si un capturador propio no responde al limite de tiempo, solo su proceso hijo
  retenido por Popen puede terminarse; se registra `forced` y no se acredita PASS.
- Ctrl+C durante `mark`/`finish` solicita esa misma parada. Como `start` retorna
  dejando la captura en segundo plano, para detenerla usar `finish --abort`;
  Ctrl+C en una consola inactiva no sustituye ese comando.
- El limite automatico de captura **no restaura servicios ni aprueba el ensayo**.
  Verificar manualmente hotspot, Apache, D2 y suscriptor al terminar. Puede
  usarse `start-lab2.ps1 -Mode Status`; no su modo Stop durante el aislamiento.
- Si el cierre o SQL no estan disponibles, se conserva INCOMPLETO. Para una
  consulta posterior sin sobrescribir el analisis previo:

  ```powershell
  python .\lab2\scripts\p14-evidence.py analyze --run $run
  ```

  Cada revision tiene otro directorio y otra fecha de consulta. Una fila que
  aparezca despues solo queda acreditada al nuevo corte, no retroactivamente.

## Archivos y alcance de las conclusiones

En `logs/evidence/P14/<UTC-escenario-id>/`:

- `metadata.json`: escenario, marcas y estado del primer cierre.
- `serial/<campana>/esp32-01.log`, `esp32-02.log`, `esp32-03.log`: originales del
  capturador, sin reescritura; `serial-hashes.json` los sella despues del cierre.
- `capture-console.log`, `capture-status.json`: errores y cierre real del capturador.
- `sql-before.json`, `sql-ready.json`: consultas PDO anteriores a interrumpir.
- `services-*.json`: identidad de Apache/D2, PID y comprobaciones de aislamiento.
- `analyses/<UTC-id>/sql-after.json`, `evidence.json`, `sequences.csv`, `summary.md`:
  consultas posteriores, vinculos `(node_id, seq)`, filas, huecos, repeticiones,
  pendientes recuperados y decisiones PASS/FAIL/INCOMPLETO.

Las consultas se limitan a campos publicos y a claves/rangos observados, mas la
ventana temporal. Nunca se toma MAX(seq) como punto de partida: las pruebas
HTTP LOAD/C1 dejan secuencias altas que no son secuencias fisicas. Los totales
SQL generales se conservan como contexto, no se atribuyen todos a P14.

**PASS** acredita recuperacion observable y persistencia SQL de las claves
identificadas bajo el aislamiento registrado. **FAIL** informa contradicciones
observadas (p. ej., acuse sin fila al corte, duplicados SQL, cambio de una fila
previa o STOP). **INCOMPLETO** informa falta de marcas, captura, SQL, reintento,
reconexion, progreso nuevo o continuidad del transporte no afectado.

Un PUBACK es del broker, nunca un ACK de MariaDB. Se comprueban por separado.
Conservar las repeticiones serie y distinguirlas de duplicados SQL. Un hueco
requiere investigacion: no prueba por si solo perdida permanente.

La igualdad de seq y de PIR/RSSI expuestos puede contrastarse; la igualdad de
**todo** el JSON pendiente requiere una preimagen completa adicional, que
estos logs no siempre proporcionan. No se inspecciona NVS ni se demuestra su
durabilidad frente a reinicios en este ensayo. El resultado nunca afirma
ausencia global de perdidas ni almacenamiento de muestras que el firmware
no llego a reservar. Los snapshots no son instantes instrumentados de commit.

## Validacion de herramientas sin ejecutar ensayos fisicos

```powershell
python -m unittest discover -s lab2/tests -p test_p14_evidence.py
python -m unittest discover -s lab2/tests -p test_capture_serial.py
& C:\xampp\php\php.exe -l .\lab2\scripts\p14-sql.php
python .\lab2\scripts\p14-evidence.py sql-check
```

Los fixtures unitarios son aislados y no se guardan como evidencia fisica P14.
Las tres interrupciones deben realizarlas el operador; estas herramientas no
automatizan hotspot, Apache, broker, firmware ni cambios en MariaDB.
