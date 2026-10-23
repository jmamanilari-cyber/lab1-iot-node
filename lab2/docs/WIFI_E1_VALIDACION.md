# E1 ESP32 #2: validacion de conexion Wi-Fi

Correccion del 23/10/2026, core Arduino-ESP32 3.3.8. Alcance: Wi-Fi y momento
de inicio SNTP. No cambia HTTPS, CA/SAN, Bearer, contrato, RSSI real, PIR simulado,
intervalo de 10 s, secuencias, NVS ni conservacion/reintentos de pendientes.

## Hallazgo y estrategia

El firmware ejecutaba `WiFi.begin()` cada 15 s mientras `setAutoReconnect(true)`
delegaba tambien reconexiones al core. Esto permite reconfigurar el STA y DHCP
mientras la conexion o su recuperacion siguen en curso. La superposicion existe
en el codigo; **no demuestra por si sola la causa del AUTH_FAIL 202** ni de los
10 minutos observados. Las credenciales coinciden y un sketch independiente
conecta: no se presupone que el password sea incorrecto.

Ahora se llama `begin()` una sola vez por arranque y se deja toda reconexion al
core. No se llama `reconnect()`, `disconnect()`, `begin()` periodico, ni se fuerza
canal/BSSID, seguridad, ahorro de energia o reinicio del driver. La configuracion
Wi-Fi se aplica en RAM con `persistent(false)`; no se borra/restaura NVS.

Detalle importante de `libraries/WiFi/src/STA.cpp` 3.3.8: el core hace un primer
reintento interno para fallos no voluntarios. Luego su lista de motivos
reconectables **no incluye AUTH_FAIL (202)**. Si se repite 202, puede quedar sin
reintentar hasta RESET/EN; este firmware lo indica, en lugar de disimularlo con
llamadas repetidas a `begin()`. Fallos transitorios como AP ausente o timeout
siguen la politica automatica del core. No se implementa una segunda politica.

Cada 30 s sin IP hay un aviso visible. No es un timeout que cancele el intento
ni prueba de recuperacion: la tarea sigue atendiendo eventos sin espera activa.
Una espera prolongada sigue siendo **fallo de validacion**, aunque luego conecte.

## Eventos y concurrencia

El callback del core copia solo evento, tiempo, motivo, canal, modo de seguridad
o IPv4 a una cola FreeRTOS de 16 entradas, sin espera. No imprime, conecta ni
accede a NVS. `communication()` drena la cola y es la unica propietaria del estado
de la aplicacion. Un contador atomico avisa si se pierden registros por cola llena.
En tal caso, repetir la prueba: la evidencia de eventos quedo incompleta.

Los tiempos se capturan al entregar el evento Arduino, antes de cualquier espera
de HTTP; `tiempo_hasta_IP_ms` mide desde el arranque (`millis()`), no desde abrir
el monitor. Antes de transmitir se comprueba tambien `WL_CONNECTED` e IPv4 no
nula. Los timeouts HTTPS y la cesion FreeRTOS de 20 ms se conservan.
SNTP se inicia una sola vez, despues de tener IP; continua funcionando durante
reconexiones. Ningun callback invoca SNTP. La verificacion temporal TLS sigue activa.

## Tres pruebas consecutivas desde RESET/EN

1. Abrir `lab2/firmware/esp32_02_http/esp32_02_http.ino` en Arduino IDE. Conservar
   el `secrets.h` local existente sin editar. En Boards Manager comprobar
   **esp32 by Espressif Systems 3.3.8**. Elegir **ESP32 Dev Module** y el puerto
   USB correspondiente a la placa #2.
2. Conservar particiones y ajustes habituales. **Erase All Flash Before Sketch
   Upload: Disabled**. No usar herramientas para borrar flash/NVS. Seleccionar
   **Core Debug Level: None**: los logs Verbose del propio core pueden incluir
   SSID/BSSID. Este firmware ya proporciona los diagnosticos necesarios.
3. Pulsar Verify y Upload una sola vez. Mantener disponibles el AP, SNTP y el
   backend HTTPS previamente funcional. Abrir Serial Monitor a **115200 baud**.
4. Con el monitor ya abierto, pulsar y soltar **EN/RESET**. Guardar la salida
   desde el mensaje E1, sin editar tiempos ni omitir desconexiones. Observar:

   ```text
   Wi-Fi inicio intento | arranque_ms=... | begin unico; reconexion automatica del core
   Wi-Fi begin devuelto | estado=... (no es confirmacion de IP)
   Wi-Fi evento | arranque_ms=... | STA_START: radio preparada
   Wi-Fi evento | arranque_ms=... | AP conectado | canal=11 auth=...; esperando IP
   Wi-Fi evento | arranque_ms=... | GOT_IP | IP=... | tiempo_hasta_IP_ms=...
   SNTP iniciado tras obtener IP; TLS sigue exigiendo hora valida.
   HTTP=201 seq=N ack=1 PIR=SIMULADO
   HTTP=201 seq=N+1 ack=1 PIR=SIMULADO
   HTTP=201 seq=N+2 ack=1 PIR=SIMULADO
   ```

   `STA_START` puede imprimirse despues de `begin devuelto` por el drenaje de la
   cola; sus `arranque_ms` muestran el orden temporal. No fijar canal 11 en el
   firmware: registrar el canal que el AP realmente utilice.
5. Tras tres muestras confirmadas, repetir el paso 4 otras dos veces, sin cambiar
   configuracion ni cargar de nuevo el firmware. No exigir secuencia inicial 0:
   NVS se conserva. Si existia un pendiente confirmado en SQL sin ACK, el primer
   envio puede ser **200** con ese mismo seq; despues deben aparecer nuevas
   muestras **201** con secuencias consecutivas, aproximadamente cada 10 s mas
   el tiempo de transmision. No borrar NVS para obtener un resultado distinto.

| Prueba | tiempo_hasta_IP_ms real | Motivos observados | Aviso sin IP >=30 s | Tres HTTP 201 / seq | Resultado |
|---|---|---|---|---|---|
| RESET 1 | pendiente | pendiente | pendiente | pendiente | pendiente |
| RESET 2 | pendiente | pendiente | pendiente | pendiente | pendiente |
| RESET 3 | pendiente | pendiente | pendiente | pendiente | pendiente |

Criterio propuesto para este AP cercano: tres arranques con IP en **menos de
30 s**, sin esperas prolongadas ni eventos perdidos, y tres nuevas muestras 201
con secuencias consecutivas en cada prueba. Registrar siempre el tiempo real;
30 s es un umbral de aceptacion del ensayo, no una garantia del firmware. Si un
arranque supera el umbral, registrar fallo y el diagnostico, incluso si despues
consigue IP. No esperar diez minutos para considerar que la correccion paso.

## Si persiste 202 o no llega IP

- `Motivo: 202 (AUTH_FAIL)` es un fallo de autenticacion del enlace Wi-Fi,
  anterior a HTTPS. No demuestra por si solo que las credenciales sean distintas.
  Guardar motivo, tiempos y logs del router con hora equivalente. El core solo
  puede aportar su reintento inicial para este motivo; no hay reintentos manuales
  silenciosos. Usar RESET para la siguiente prueba, no borrar NVS.
- Comparar con el sketch funcional los mismos ajustes de placa/core, modo STA,
  seguridad minima, PMF, escaneo, canal/BSSID fijado y ahorro de energia. Los logs
  `Wi-Fi config` y `Wi-Fi controlador` muestran esos campos sin credenciales.
  Valores de auth del core: 3=WPA2-PSK, 4=WPA/WPA2-PSK, 6=WPA3-PSK,
  7=WPA2/WPA3-PSK. `auth_min` es el umbral del cliente, no prueba del modo del AP.
- En el router consultar modo anunciado (WPA2/AES o transicion WPA2/WPA3), PMF,
  rechazo de autenticacion/asociacion, limites de clientes y filtrado MAC.
  `AUTHMODE_CHANGE` registra cambios de modo; `AP conectado` registra el modo
  informado y canal. No desactivar seguridad ni TLS para probar esta correccion.
- Si no hay `AP conectado`, investigar autenticacion/asociacion/driver; si hay
  `AP conectado` pero no `GOT_IP`, investigar DHCP antes que Bearer/certificados.
  Si hay IP y espera SNTP, investigar DNS/NTP; si aparece HTTP, el enlace Wi-Fi
  ya avanzo y deben analizarse por separado errores TLS/API.
- Si ambos sketches con ajustes equivalentes difieren aun, alternarlos en la
  misma placa/AP y condiciones, conservar salidas y comparar el registro del
  router. Considerar compatibilidad del controlador/core, no aumentar reintentos
  ni cambiar varias opciones a la vez. No compartir `secrets.h` ni logs Verbose.

## Verificacion de escritorio

Compilacion con ESP32 Dev Module y core 3.3.8, usando marcadores de configuracion
en `build/`, sin leer secretos reales, flashear ni modificar servicios. No valida
el tiempo de conexion ni resuelve experimentalmente el motivo 202: esas
conclusiones dependen de completar la tabla en la placa fisica.

Resultado: compilacion final correcta, sin advertencias; 1051900 bytes de
programa (80%) y 48776 bytes globales (14%). Comparacion contra copia previa:
TLS, Bearer, carga/guardado NVS, ACK, transmision, `TelemetryState.h` y bloque de
muestreo/reintento HTTP identicos. No se editaron `secrets.h` ni servicios.
