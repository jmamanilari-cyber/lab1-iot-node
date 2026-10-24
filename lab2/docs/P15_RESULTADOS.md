# P15 — Ensayo físico simultáneo y métricas de telemetría

**Proyecto:** Laboratorio 2, Software para Telecomunicaciones
**Fecha del ensayo:** 24 de octubre de 2026, en UTC (comenzó el 23 de octubre en hora de Perú)
**Estado de la evidencia:** campaña experimental completada; análisis contrastado con registros serie y contadores de MariaDB.
**Propósito:** preservar los resultados para incorporarlos posteriormente al informe, sin depender del historial o la memoria de una conversación.

## 1. Configuración evaluada

Tres ESP32 físicos se conectaron simultáneamente a una laptop mediante una extensión USB, empleada para alimentación y registros serie. La telemetría se transmitió **por Wi-Fi**, no por USB. La laptop alojaba el backend PHP/MariaDB y los servicios de red.

| Nodo | Puerto serie | Transporte de telemetría | Valor PIR | Otros datos |
|---|---|---|---|---|
| `esp32-01` | COM5, 115200 baudios | HTTPS, puerto 8443 | Medición de PIR físico | RSSI real; sistema de relé/LED existente (temporización no cuantificada en este ensayo) |
| `esp32-02` | COM8, 115200 baudios | HTTPS, puerto 8443 | Simulación declarada por firmware | RSSI real |
| `esp32-03` | COM9, 115200 baudios | MQTT con TLS, puerto 8883 | Simulación declarada por firmware | RSSI real; MQTT QoS 1 |

Los tres puertos se registraron con `scripts/capture-serial.py`. Se consultó MariaDB al principio y al final con `php scripts/demo-data.php brief`. No se ejecutaron suites generadoras de fixtures como parte de esta campaña. Los totales **históricos** de MariaDB sí incluyen fixtures antiguos; por ello, se usan **diferencias de contadores** en lugar de interpretar los totales históricos como lecturas físicas.

## 2. Ventana y método

- Inicio anotado por PowerShell: **2026-10-24 04:57:02.2565928 UTC**.
- Final anotado por PowerShell: **2026-10-24 05:17:02.4552156 UTC**.
- Duración del cronómetro monotónico: **1200,3180408 s** (aproximadamente 20 min).
- El script realiza la primera consulta de conteos y, a continuación, una espera de `1200` segundos, seguida de la consulta final. El cronómetro engloba también los tiempos de esas consultas: por tanto, es un **denominador aproximado** para tasas calculadas a partir de los dos snapshots, no una delimitación atómica del intervalo exacto entre dos commits.
- Se filtran los logs serie por las dos fechas UTC anteriores; para cada nodo se extrae la secuencia de confirmación (`HTTP=201 ack=1` en los nodos 1–2; `PUBACK broker` en el nodo 3). Se descartan repeticiones textuales de líneas fuera de la ventana, no se interpretan como transacciones.
- El conteo SQL corresponde a registros de telemetría almacenados por dispositivo. La verificación **fila por fila** de las 336 claves `(node_id, seq)` requeriría un export SQL completo de la campaña: los archivos `brief` exponen conteos y únicamente las últimas muestras.

## 3. Resultados de persistencia y throughput

| Nodo | Registros iniciales | Registros finales | Registros nuevos | Confirmaciones únicas en ventana | Secuencias confirmadas | Saltos visibles | Throughput observado |
|---|---:|---:|---:|---:|---|---:|---:|
| ESP32 #1, HTTPS | 330 | 438 | **108** | 108 | 304–411 | 0 | 0,090 mensajes/s |
| ESP32 #2, HTTPS | 409 | 517 | **108** | 108 | 491–598 | 0 | 0,090 mensajes/s |
| ESP32 #3, MQTT TLS | 433 | 553 | **120** | 120 | 433–552 | 0 | 0,100 mensajes/s |
| **Total** | **1172** | **1508** | **336** | **336** | — | **0** | **0,280 mensajes/s** |

**Cálculo:** `throughput = (conteo_SQL_final − conteo_SQL_inicial) / 1200,3180408 s`.

- Equivalente por minuto: **5,399**, **5,399** y **5,998 mensajes/min** respectivamente; conjunto: **16,796 mensajes/min**.
- Las 336 confirmaciones de secuencias distintas observadas durante la ventana son coherentes con el incremento de 336 filas de MariaDB y con los últimos números de secuencia mostrados en los snapshots SQL.
- Se observaron secuencias consecutivas **dentro de esta ventana**. Esto no demuestra ausencia de pérdidas en cualquier otro intervalo ni verifica individualmente todas las filas persistidas.
- La tasa refleja la **carga ofrecida y almacenada** por estos tres dispositivos durante el ensayo. **No es una medición de la capacidad máxima del backend**.

## 4. Periodicidad de mensajes serie

Intervalos entre eventos consecutivos observados en la laptop; se utiliza **la misma clase de evento para cada nodo**. Fuente: ESP32 #1 `Muestra esp32-01`, ESP32 #2 `HTTP=201`, ESP32 #3 `Muestreo RSSI=`.

| Nodo | Intervalos | Media | p50 | p95 | Máximo |
|---|---:|---:|---:|---:|---:|
| ESP32 #1 | 107 | **11,124 s** | 10,998 s | 11,872 s | 13,759 s |
| ESP32 #2 | 107 | **11,165 s** | 10,974 s | 11,965 s | 13,947 s |
| ESP32 #3 | 119 | **10,003 s** | 10,001 s | 10,015 s | 10,025 s |

**Corrección respecto a un resumen previo:** para el ESP32 #3 la periodicidad debe medirse entre líneas `Muestreo RSSI`, no entre líneas `PUBACK`. Los valores anteriores de p50/p95/máximo para esa periodicidad no deben copiarse al informe.

Estos intervalos describen la periodicidad observable en el **registro serie de la laptop**; no son latencias de extremo a extremo ni tiempos internos del servidor.

## 5. Diferencias temporales entre eventos serie (NO latencia del servidor)

Se calcularon las diferencias entre la hora UTC de **recepción por USB** de un mensaje de origen y la recepción por USB de su confirmación, vinculados por `seq`. Los percentiles se calcularon por interpolación lineal entre posiciones ordenadas, siguiendo el criterio inclusivo habitual de cuantiles.

| Nodo | Par de eventos serie | Pares | p50 | p95 | Máximo |
|---|---|---:|---:|---:|---:|
| ESP32 #1 | `Muestra` → `HTTP=201 ack=1` | 108 | **0,966 s** | **1,830 s** | **3,665 s** |
| ESP32 #2 | No disponible: el registro solo contiene confirmaciones HTTP | — | — | — | — |
| ESP32 #3 | `MQTT encolado` → `PUBACK broker` | 120 | **0,674 s** | **1,192 s** | **1,442 s** |

**Restricción crucial:** estas diferencias incluyen la emisión de los mensajes serie, el búfer USB, la recepción por el sistema operativo y el procesamiento de firmware. **No equivalen a RTT de red instrumentado, latencia servidor HTTP, duración del commit SQL ni latencia MQTT→MariaDB**. En MQTT, el `PUBACK` confirma recepción por el **broker** (QoS 1), no persistencia en MariaDB; la línea `MariaDB=NO_CONFIRMADA` del firmware expresa esa ausencia de confirmación de aplicación.

## 6. Observaciones de estabilidad y anomalía de captura

- Dentro de la ventana analizada se contaron **108, 108 y 120** confirmaciones únicas respectivamente y **cero saltos** en sus rangos de secuencia.
- No aparecen mensajes **explícitos** de error de transporte, reintento o desconexión en la ventana filtrada. La ausencia de mensajes no garantiza que no existieran errores no instrumentados.
- `esp32-01.log` contiene **63 650 repeticiones** de `HTTP=201 seq=292` entre **04:54:53.293 y 04:55:02.868 UTC**, y al menos una línea de muestreo concatenada irregularmente. Esta concentración no es físicamente compatible con el caudal de un puerto de 115200 baudios. Es **una anomalía del contenido registrado**, cuyo origen exacto (script, búfer, terminal, etc.) no fue diagnosticado; **no** acredita miles de solicitudes HTTP ni miles de inserts.
- La anomalía de `seq=292` ocurrió **antes** del inicio de la campaña medible (**04:57:02 UTC**). Los 216 mensajes del ESP32 #1 dentro de la ventana consisten en 108 muestreos y 108 confirmaciones con secuencias consecutivas. No es necesario invalidar la medición SQL de los 20 minutos por el incidente previo, pero **sí** corregir/verificar `capture-serial.py` antes de una campaña nueva.
- Los registros SQL inicial y final muestran respectivamente como última secuencia: `303→411` (#1), `490→598` (#2), `432→552` (#3), consistentes con los incrementos de conteos. Esto proporciona una corroboración **agregada**, no una exportación completa de claves.

## 7. Alcance, pendientes y uso en el informe

**Acreditado por esta campaña:** funcionamiento concurrente de tres nodos físicos con dos transportes, 336 registros nuevos contabilizados por MariaDB, continuidad visible de secuencias, tasa observada de persistencia y diferencias temporales en los logs serie. El ESP32 #1 usa un PIR físico; los valores PIR de #2/#3 están declarados como simulados y el RSSI sí se mide.

**No acreditado por esta campaña:** la capacidad máxima de carga del servidor; latencias p50/p95 internas de PHP, suscriptor y transacciones MariaDB; latencia de extremo a extremo; persistencia MQTT individual fila a fila; medición instrumental del retardo GPIO27/GPIO26/relé o de los ~10 segundos de retención; resultados T05–T08 sin contrastar sus definiciones originales; export SQL exhaustivo. Las pruebas D2 de seguridad y recuperación P14 son evidencias **separadas** y no deben presentarse como si provinieran de esta campaña.

**Tratamiento recomendado en el informe:** incluir la tabla de conteos y tasas, un gráfico de registros nuevos por nodo (108/108/120) y una tabla de periodicidad. Las diferencias entre eventos serie pueden presentarse como **tiempos observados desde la captura serie**, nunca como latencias del backend. Describir explícitamente la anomalía de COM5 y su delimitación temporal. Conservar una copia verificable de las fuentes y capturas del montaje físico si están disponibles.

## 8. Archivos y procedimiento de reproducción

Dentro del paquete de evidencias:

- `fuentes/esp32-01.log`, `fuentes/esp32-02.log`, `fuentes/esp32-03.log`: registros serie originales, **sin modificar**.
- `fuentes/sql-inicio.log`, `fuentes/sql-final.log`: dos salidas SQL originales, **sin modificar** (codificación UTF-16 de Windows PowerShell).
- `fuentes/duracion.log`: marcas UTC y cronómetro original.
- `P15_metricas.csv`: métricas resumen por nodo, sin necesidad de copiar números de esta conversación.
- `P15_secuencias.csv`: detalle de las **336 secuencias confirmadas** con su marca de recepción UTC y la diferencia entre eventos cuando el firmware permite emparejarlos.
- `P15_analizar.py`: script autocontenido para recomputar los CSV desde los seis originales. Ejecutar `python P15_analizar.py` dentro de la carpeta extraída, sin dependencias externas.
- `SHA256SUMS.txt`: huellas criptográficas para verificar integridad, especialmente de las fuentes originales.

**Preservación sugerida:** conservar este archivo como `lab2/docs/P15_RESULTADOS.md` en el repositorio y el paquete ZIP en una carpeta `lab2/docs/evidencias/` (si el tamaño y la política del repositorio lo permiten). Mantener además un respaldo externo. Los resultados no dependen de las memorias de ChatGPT. Al redactar el informe, reutilizar este documento como antecedente y los archivos originales como sustento, sin sustituirlos por afirmaciones no comprobadas.

## 9. Campañas adicionales: latencia interna de `TelemetryService::receive()`

Las secciones 1–8 se conservan intactas y describen el ensayo físico de 20 minutos y sus fuentes. Esta sección incorpora **dos campañas posteriores y separadas**. No sustituye las métricas anteriores de periodicidad o diferencias entre eventos serie, ni combina sus poblaciones o percentiles con las nuevas mediciones.

### 9.1. Fuentes y metodología

Se consultaron exclusivamente `metadata.txt`, `summary.txt` y `measurements.csv` de cada uno de estos directorios:

- `logs/latency/20261024T055720Z_7a21942e/` — primera campaña.
- `logs/latency/20261024T060714Z_48900320/` — segunda campaña.

No se volvieron a analizar registros serie ni paquetes ZIP. Los conteos, secuencias y valores destacados se contrastaron con los CSV; las tablas reproducen los resúmenes de cada campaña.

**Magnitud medida:** duración de `TelemetryService::receive()` hasta la salida del método: **validación del contrato, transacción, operaciones SQL y commit**. No es únicamente el tiempo del commit. Según los metadatos, quedan fuera Wi-Fi, TLS de Apache, autenticación previa, cola MQTT y auditoría posterior. **No es RTT, duración completa de una petición HTTPS ni latencia extremo a extremo ESP32→MariaDB.** La etiqueta `http`/`mqtt` identifica el adaptador de entrada; no significa que se esté midiendo todo ese transporte.

Cada fila del CSV identifica una operación mediante `time_utc`, `transport`, `node_id`, `seq`, `result` y `duration_ms`. La duración se toma de `duration_ms`, no de restar marcas UTC de filas sucesivas. La duración total de cada ventana es la monotónica informada en `metadata.txt`.

Las estadísticas se calculan por campaña, transporte, nodo y resultado. Para reproducir los cuantiles inclusivos: ordenar las N duraciones, usar la posición `h = (N − 1) × q` con índices desde cero e interpolar linealmente entre los valores vecinos, con `q=0,50` o `0,95`. Se presentan tres decimales en milisegundos; el redondeo al par en los empates reproduce los resúmenes también cuando el valor calculado termina en medio milésimo. Esos decimales no acreditan por sí solos exactitud instrumental de un microsegundo.

### 9.2. Primera campaña: aproximadamente 300 segundos, solo HTTP registrado

- **Inicio UTC:** `2026-10-24T05:57:20.4980639Z`.
- **Final UTC:** `2026-10-24T06:02:20.4890866Z`.
- **Duración monotónica:** **300,013689 s**.
- **Operaciones registradas:** **52**, todas `http`, con resultado `created`.

| Transporte | Nodo / agrupación | N | Media (ms) | p50 (ms) | p95 (ms) | Máximo (ms) |
|---|---|---:|---:|---:|---:|---:|
| HTTP | `esp32-01` | 26 | 8,380 | 6,923 | 9,727 | 48,163 |
| HTTP | `esp32-02` | 26 | 8,740 | 9,106 | 9,927 | 48,345 |
| HTTP | **Total HTTP de esta campaña** | **52** | **8,560** | **8,708** | **9,869** | **48,345** |
| MQTT | `esp32-03`: sin mediciones | 0 | — | — | — | — |

**La primera campaña no produjo mediciones MQTT.** Los campos vacíos no representan latencia cero. Estos archivos no permiten establecer por qué no se registraron operaciones MQTT ni afirmar que no hubiera tráfico MQTT durante esa ventana.

### 9.3. Segunda campaña: aproximadamente 120 segundos, HTTP y MQTT

- **Inicio UTC:** `2026-10-24T06:07:14.2194272Z`.
- **Final UTC:** `2026-10-24T06:09:14.2254064Z`.
- **Duración monotónica:** **120,0073278 s**.
- **Operaciones registradas:** **34**: **22 HTTP** y **12 MQTT**, todas con resultado `created`.

| Transporte | Nodo / agrupación | N | Media (ms) | p50 (ms) | p95 (ms) | Máximo (ms) |
|---|---|---:|---:|---:|---:|---:|
| HTTP | `esp32-01` | 11 | 6,525 | 5,470 | 10,166 | 10,774 |
| HTTP | `esp32-02` | 11 | 11,247 | 9,106 | 29,190 | 48,535 |
| HTTP | **Total HTTP de esta campaña** | **22** | **8,886** | **7,409** | **10,728** | **48,535** |
| MQTT | `esp32-03` | 12 | 10,569 | 6,380 | 27,407 | 48,811 |

La agrupación MQTT total coincide con `esp32-03`, único nodo MQTT registrado en esta campaña. No se calcula un percentil conjunto de las dos campañas, ni se promedian percentiles de nodos para obtener el del transporte.

### 9.4. Continuidad de secuencias en la segunda campaña

Comprobación sobre las claves y filas de `measurements.csv`, independiente de los registros serie:

| Nodo | Transporte | N / secuencias únicas | Primera seq | Última seq | Secuencias ausentes dentro del rango | Filas repetidas para la misma clave |
|---|---|---:|---:|---:|---:|---:|
| `esp32-01` | HTTP | 11 / 11 | 678 | 688 | 0 | 0 |
| `esp32-02` | HTTP | 11 / 11 | 863 | 873 | 0 | 0 |
| `esp32-03` | MQTT | 12 / 12 | 853 | 864 | 0 | 0 |

En cada grupo se cumple `última seq − primera seq + 1 = N`, sin duplicados y con orden creciente de secuencia en las filas correspondientes. Por tanto, hay **continuidad en las operaciones registradas de esta segunda ventana**. No demuestra ausencia global de pérdidas o reintentos, continuidad durante el intervalo entre campañas ni una verificación SQL independiente de cada clave.

### 9.5. Valores atípicos conservados

Como descripción exploratoria, por nodo y campaña se aplica el criterio superior `Q3 + 1,5 × (Q3 − Q1)`, con cuantiles interpolados. Destacan los siguientes cuatro valores; **ninguno fue eliminado** de N, media, percentiles o máximo:

| Campaña | Nodo | Transporte | Marca UTC del CSV (2026-10-24) | Seq | Duración (ms) |
|---|---|---|---|---:|---:|
| Primera | `esp32-02` | HTTP | 05:58:36.631Z | 817 | 48,345 |
| Primera | `esp32-01` | HTTP | 06:01:27.613Z | 647 | 48,163 |
| Segunda | `esp32-02` | HTTP | 06:08:15.737Z | 868 | 48,535 |
| Segunda | `esp32-03` | MQTT | 06:08:20.610Z | 859 | 48,811 |

Su causa no está identificada por las fuentes consultadas. No se atribuyen a la red, TLS, USB ni a la anomalía serie de la sección 6. Tampoco se interpretan como operaciones fallidas: las cuatro filas indican `created`.

Con N=11 en HTTP `esp32-02`, el p95 de la segunda campaña interpola entre los dos valores mayores, 9,846 y 48,535 ms. Con N=12 en MQTT, interpola entre 9,894 y 48,811 ms. Por ello, un único valor elevado influye fuertemente en ambos p95; en los grupos de N=26 de la primera campaña, el máximo aislado no entra en la interpolación del p95. Esto explica por qué deben conservarse y presentarse también los máximos.

### 9.6. Limitaciones y alcance actualizado

- **Muestras pequeñas y ventanas cortas:** N=26 por nodo en la primera campaña y N=11/11/12 en la segunda. Son estadísticas descriptivas de las observaciones, sin intervalos de confianza ni garantía del p95 poblacional. No se demuestra independencia estadística ni estabilidad bajo otras cargas.
- Los 52 y 34 registros son todos `created`; no hay filas de error o duplicado en estos CSV. Esto no acredita cero fallos de red, cero intentos omitidos o captura exhaustiva de todos los mensajes: solo caracteriza las operaciones presentes.
- No se mezclan campañas ni se usan estas duraciones para reemplazar los tiempos serie de las secciones 4–5. Las diferencias entre nodos o adaptadores no permiten concluir que un transporte completo sea más rápido que otro.
- `time_utc` sirve para correlacionar registros, no como instante de generación en el ESP32 ni como segundo extremo de una medida de RTT. Tampoco `received_at` equivale al instante del commit.
- En MQTT, estas filas aportan evidencia de ejecución del método de dominio con resultado `created`, incluyendo commit según el alcance declarado. Son evidencia de servidor distinta del PUBACK del broker, pero no constituyen un ACK de persistencia recibido por el ESP32 ni una medición desde su envío hasta MariaDB.
- No son un ensayo de saturación ni una medición de capacidad máxima. Se mantienen separados el throughput de la campaña física de 20 minutos y las duraciones internas de estas ventanas posteriores.

**Avance respecto al alcance de la sección 7:** estas dos campañas añaden estadísticas reales de duración interna de `TelemetryService::receive()`, con HTTP en ambas y MQTT únicamente en la segunda. Siguen sin acreditar RTT, latencia extremo a extremo ni las demás mediciones allí pendientes. Se preservan todos los resultados y límites anteriores de P15.
