### Prueba P14-C: interrupción y recuperación del servicio MQTT

Se realizó una interrupción controlada de 60 segundos del broker MQTT D2, seguida de su restauración. Durante el ensayo se comprobó la continuidad de las comunicaciones HTTPS de los ESP32 #1 y #2, así como la recuperación de la comunicación MQTT/TLS del ESP32 #3.

Tras la restauración se verificaron 15 registros de recuperación del ESP32 #3 correlacionados entre la captura serie y MariaDB, además de la recuperación de un mensaje pendiente.

El procedimiento permitió observar la recuperación funcional del sistema. La evaluación automatizada quedó clasificada como INCOMPLETO debido a limitaciones en la integridad de las evidencias y la verificación de continuidad de las secuencias, por lo que no se acredita ausencia total de pérdidas.

### P14-B: Interrupción y recuperación del servicio HTTPS

Se realizó una interrupción controlada de la conectividad HTTPS mediante reglas de firewall, manteniendo activo el servidor Apache y los demás servicios.

Durante la prueba se confirmó la indisponibilidad temporal de HTTPS y su posterior restauración. Los ESP32 #1 y #2 recuperaron sus mensajes pendientes, verificándose respectivamente 11 y 7 registros de recuperación correlacionados entre la captura serie y MariaDB.

El ESP32 #3 mantuvo su comunicación MQTT durante la interrupción, con seis registros que acreditaron su continuidad y 20 registros de recuperación.

No se identificaron huecos SQL ni acuses sin persistencia en la base de datos. Sin embargo, la evaluación automatizada quedó clasificada como INCOMPLETO debido a líneas serie ilegibles y a dos registros SQL del ESP32 #2 sin eventos serie legibles correspondientes.

Los resultados acreditan la recuperación funcional del servicio HTTPS y la continuidad de MQTT, aunque las limitaciones de captura impiden confirmar la integridad completa de las evidencias.

# P14-A — Análisis de interrupción y recuperación Wi-Fi

**Fecha de la captura:** 25 de octubre de 2026 (marcas UTC de recepción en la laptop).
**Campaña:** `20261025T041825_514528Z_ua00nb1y`
**Fuente:** `P14A_Evidencias.zip` (tres registros serie y tres diagnósticos locales).
**Alcance:** se analizan las observaciones serie; no se aportó exportación SQL de MariaDB ni registro independiente de las horas exactas de apagado y encendido del hotspot.

## Resumen ejecutivo

Los tres nodos recuperaron la comunicación después de la interrupción observada alrededor de las 04:21:42 UTC. Los ESP32 #1 y #2 volvieron a obtener dirección IP, localizar el servidor y recibir `HTTP=201`. El ESP32 #3 restableció la sesión MQTT/TLS, retransmitió `seq=3814` y obtuvo su confirmación PUBACK; posteriormente continuó publicando. Estos hechos confirman la **recuperación funcional** de los tres nodos en la ventana observada, no la ausencia de toda pérdida de extremo a extremo.

| Nodo | Interfaz | Resultado tras interrupción | Evidencia cuantitativa |
|---|---|---|---|
| ESP32 #1 | COM5, HTTPS | `GOT_IP` 04:22:55.302; `HTTP=201` 04:23:22.912 | 15 secuencias `3129–3143`, todas con `ack=1` en la captura; 4 errores HTTP iniciales y posteriores con reintento |
| ESP32 #2 | COM13, HTTPS | `GOT_IP` 04:22:55.286; `HTTP=201` 04:23:22.192 | 16 acuses HTTP completos y legibles `3269–3286`, sin registros legibles de `3275` ni `3277` |
| ESP32 #3 | COM6, MQTT/TLS | Sesión MQTT reconectada 04:23:34.794; `PUBACK seq=3814` 04:23:35.818 | 23 secuencias `3797–3819`, todas con PUBACK registrado; `seq=3814` retransmitida sin cambio de identificador |

## Cronología de la interrupción principal

| UTC | Evento |
|---|---|
| 04:21:42.499 | ESP32 #3 registra error de lectura TLS asociado a la caída de comunicación. |
| 04:21:42.676 | ESP32 #2 registra la primera desconexión Wi-Fi del intervalo. |
| 04:21:42.885 | ESP32 #1 registra la primera desconexión Wi-Fi del intervalo. |
| 04:22:54.191 | ESP32 #1 vuelve a asociarse al punto de acceso. |
| 04:22:54.210 | ESP32 #2 vuelve a asociarse al punto de acceso. |
| 04:22:55.286 | ESP32 #2 obtiene IP `10.229.155.191`. |
| 04:22:55.302 | ESP32 #1 obtiene IP `10.229.155.158`. |
| 04:23:14.369 | ESP32 #3 redescubre el servidor MQTT; hay un intento de conexión fallido después. |
| 04:23:20.772 | ESP32 #2 redescubre el servidor HTTPS. |
| 04:23:21.493 | ESP32 #1 redescubre el servidor HTTPS. |
| 04:23:22.192 | ESP32 #2 confirma `HTTP=201 seq=3282`. |
| 04:23:22.912 | ESP32 #1 confirma `HTTP=201 seq=3140`. |
| 04:23:34.794 | ESP32 #3 confirma nueva conexión MQTT/TLS, QoS 1 y retransmite `seq=3814`. |
| 04:23:35.818 | ESP32 #3 recibe `PUBACK seq=3814`. |
| 04:24:12–04:24:15 | Siguen apareciendo nuevos acuses HTTP y MQTT al final de la campaña. |

La indisponibilidad Wi-Fi observable desde la primera desconexión hasta `GOT_IP` fue aproximadamente **72.4 s** para el ESP32 #1 y **72.6 s** para el ESP32 #2. La recuperación MQTT/TLS del ESP32 #3 ocurrió **112.3 s** después de su primer error TLS registrado. Estas duraciones son diferencias entre eventos observados; no equivalen necesariamente a la duración exacta del hotspot apagado.

## ESP32 #1 — HTTPS y reinicio

* Se identificaron 15 muestras consecutivas `3129–3143`, sin salto de `seq` dentro de ese rango. Todas tienen una confirmación HTTP legible `ack=1` en el registro (14 respuestas `201` y una `200`).
* Cuatro intentos HTTP devolvieron un código negativo: tres `HTTP=-1` y uno `HTTP=-11`. En la captura cada secuencia afectada (`3129`, `3131`, `3136`, `3142`) recibió posteriormente un `ack=1` sin cambiar el identificador.
* A las **04:20:38.193 UTC**, antes de la interrupción principal, apareció explícitamente `rst:0x1 (POWERON_RESET)`. El nodo había generado `seq=3137` a las 04:20:37.742 y luego confirmó **esa misma secuencia** a las 04:21:07.951 tras reiniciarse. Esto respalda que el identificador pendiente sobrevivió al reinicio, aunque no se dispone de una comparación completa del contenido JSON original.
* Tras la desconexión principal, recuperó IP a las 04:22:55.302 y volvió a obtener `HTTP=201` desde las 04:23:22.912. También se registró un error HTTP intermitente para `seq=3142`, posteriormente confirmado.
* El diagnóstico de COM5 detectó **1 línea UTF-8 inválida** (línea 147). No afecta a la identificación de los acuses citados, pero limita la integridad absoluta de la fuente serie.

## ESP32 #2 — HTTPS y anomalía masiva de la captura

* La comunicación se recuperó: `GOT_IP` a las 04:22:55.286, descubrimiento del servidor a las 04:23:20.772 y `HTTP=201 seq=3282` a las 04:23:22.192, seguido de `3283–3286`.
* Hay **16 respuestas HTTP exitosas completas y legibles** en el intervalo de `seq=3269–3286`, pero las secuencias **3275 y 3277 no disponen de una línea legible de acuse**. En esos lugares aparecen registros dañados; por tanto, esto no demuestra pérdida de mensajes, pero tampoco permite certificar los acuses ausentes.
* El registro contiene **339 867 líneas**, de las cuales **339 756** son repeticiones del fragmento `LADO` entre las 04:18:25.596 y las 04:18:42.035. También existe una línea extensa concatenada y malformada que contiene repeticiones parciales de `seq=3268`. Esos fragmentos **no deben tratarse como 339 756 transmisiones ni como acuses auténticos**.
* El diagnóstico de COM13 reportó **4 líneas con UTF-8 inválido**, **4 bytes NUL** y ningún error de lectura del puerto. La procedencia exacta de la anomalía no puede identificarse únicamente con el ZIP.

## ESP32 #3 — MQTT/TLS y recuperación del mensaje pendiente

* Se identificaron **23 muestras consecutivas** `3797–3819` y **23 PUBACK**, uno para cada secuencia. Las publicaciones encoladas suman 25 porque `3800` y `3814` aparecen dos veces como envíos/reintentos; no implican duplicados persistidos en MariaDB.
* El caso crucial es `seq=3814`: se generó y encoló a las **04:21:41.838**; a las **04:21:42.499** apareció un error de lectura TLS y después el mensaje `pendiente sin alterar`. Tras la reconexión MQTT/TLS a las **04:23:34.794**, se volvió a encolar la **misma `seq=3814`** y recibió PUBACK a las **04:23:35.818**.
* Después de recuperar `3814`, el nodo continuó con nuevas secuencias `3815–3819`, todas con PUBACK antes de finalizar la captura.
* COM6 no reportó líneas UTF-8 inválidas ni errores de lectura.
* Cada PUBACK indica recepción del broker, **no confirma persistencia en MariaDB**: los propios mensajes señalan `MariaDB=NO_CONFIRMADA`.

## Integridad y límites de la evidencia

| Puerto | Líneas serie | UTF-8 inválido | Observación |
|---|---:|---:|---|
| COM5 | 164 | 1 | Reinicio `POWERON_RESET`, retransmisiones HTTPS exitosas |
| COM13 | 339 867 | 4 | 339 756 repeticiones `LADO` y acuses no legibles de dos secuencias |
| COM6 | 125 | 0 | Reconexión MQTT/TLS y PUBACK de pendiente documentados |

No se adjuntaron consultas SQL de MariaDB. Por tanto, la conclusión demostrable es la **recuperación funcional y continuidad de identificadores a partir de los registros serie**, con las limitaciones señaladas. No corresponde afirmar entrega perfecta extremo a extremo ni ausencia absoluta de pérdidas. Los archivos originales deben conservarse íntegros.

**Resultado del análisis manual:** recuperación funcional observada para los tres nodos; validación integral de persistencia SQL pendiente/no incluida en esta evidencia.
