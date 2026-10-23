# E1. ESP32 #2: PIR simulado por HTTPS

Primera unidad: `lab2/firmware/esp32_02_http/esp32_02_http.ino`, identidad fija
`esp32-02`. No modifica Lab1, backend, MQTT, SQL ni la prueba `pir_rele_10s.ino`.
No se conecta PIR, relé ni LED a esta placa. El valor alterna 0/1 según la paridad
de la secuencia; **solo el PIR es simulado**. El RSSI procede de `WiFi.RSSI()` al
crear la muestra, con conexión real. No se vuelve a medir durante reintentos.

## Contrato y recuperación

Se conserva `POST /api/v1/telemetry`, Bearer del dispositivo y JSON existente:

```json
{"node_id":"esp32-02","seq":1234,"timestamp":null,"value":0,"rssi":-61,"extensions":{"pir_simulated":true,"pir_source":"sequence_parity"}}
```

`timestamp` es deliberadamente `null`; no se inventa una hora de medición.
El servidor asigna `received_at`. SNTP solo habilita la comprobación de vigencia
del certificado TLS. Si no obtiene hora desde 2026, no envía el token.

- Un solo mensaje pendiente, sin cola ilimitada ni muestreo durante la caída.
  Tras cada ACK confirmado se esperan 10 s para crear la siguiente muestra.
- Namespace NVS exclusivo `e1-esp32-02`, clave `state`. Un blob contiene la próxima
  secuencia y el JSON pendiente. `Preferences.putBytes()` confirma el blob antes
  de cualquier envío. Se confía en la atomicidad y CRC de NVS de ESP-IDF.
- Reinicio antes de persistir: no hubo envío. Reinicio después: se recupera el
  mismo JSON. Si SQL confirmó pero se perdió el ACK, el reenvío debe devolver 200.
  Solo 200/201 con JSON `ok=true`, versión v1, identidad y secuencia coincidentes
  permiten borrar el pendiente mediante otro commit NVS.
- Fallo de NVS, blob incompatible o secuencia agotada: parada, sin reinicializar
  contadores automáticamente. No garantiza supervivencia a borrado de flash,
  avería de almacenamiento ni restauración de una imagen NVS antigua.
- Errores de transporte/TLS, ACK inválido, 408, 429 y 5xx: mismo contenido y
  secuencia, backoff 1, 2, 4, 8, 16, 32, 60 s más 0..1000 ms aleatorios.
  `Retry-After` numérico en 429/503 amplía la espera hasta una hora; su forma de
  fecha HTTP no se interpreta. Los demás estados, incluidos 3xx, 401, 403, 409 y
  422, detienen los envíos y conservan el pendiente para diagnóstico.
  Reiniciar permite otro intento con ese mismo pendiente; no omitir un 409.
- Wi-Fi se reconecta sin esperar en bucles. HTTPClient/TLS hacen E/S sincrónica
  en una tarea FreeRTOS separada, con timeout de conexión 3 s, handshake 5 s y
  lectura 5 s. Son límites por operación, no un plazo total absoluto frente a un
  servidor que entregue bytes lentamente. Respuesta acotada a 2048 bytes.
  El `loop()` queda libre para futuras tareas. No hay llamadas a `delay()`.
- NVS escribe dos veces por muestra; adecuado para esta demostración breve.
  No se presenta como estrategia de almacenamiento continuo para producción.

## Transporte: decisión y preparación

HTTP plano expondría el Bearer a cualquiera que observe la LAN. Se elige **HTTPS
con autenticación del servidor mediante CA privada y SAN DNS**, y autenticación
del nodo mediante el Bearer existente. mTLS exigiría gestionar certificados de
cliente sin aportar cambios necesarios al contrato actual. Una huella de hoja
fija complicaría la renovación. No se implementan `setInsecure()`, fallback HTTP
ni redirecciones; no existe una opción para desactivar la validación.

Topología: ESP32 → TLS Apache/XAMPP en 8443 → PHP **127.0.0.1:18082** → servicio
existente → MariaDB. HTTP interno solo atraviesa loopback del mismo equipo.
La plantilla [https-e1.example.conf](../config/https-e1.example.conf) limita el
proxy al endpoint y la subred/IP permitida. No cambia el listener 443 existente,
los servicios de demo ni el broker. No se ha instalado ni activado esta plantilla.

Preparar antes de cargar un token real:

El adaptador `DirectServerTlsClient` intercepta las dos sobrecargas virtuales
`connect(host, port[, timeout])` que usa HTTPClient. Solo admite
`telemetry.lab.test:8443` y llama a
`NetworkClientSecure::connect(IPAddress(192,168,0,3), 8443, nombreTLS, CA, nullptr, nullptr)`.
Esta llamada evita `hostByName`; el core configura SNI/nombre esperado con
`mbedtls_ssl_set_hostname` y exige verificar la cadena CA y el certificado.
La URL sigue siendo `https://telemetry.lab.test:8443/api/v1/telemetry`:
HTTPClient 3.3.8 genera `Host: telemetry.lab.test:8443` y conserva la ruta POST.
No se utiliza una URL con IP ni una cabecera Host manual.

Limites: IP reservada necesaria; al cambiarla se debe actualizar el sketch.
El adaptador usa miembros protegidos del cliente para conservar CA y timeout:
revisar esta integracion al actualizar el core desde 3.3.8. SNTP conserva sus
servidores publicos y su dependencia DNS; solo se elimina el DNS de telemetria.
Las antiguas constantes `TELEMETRY_HOST`/`TELEMETRY_PORT` en un `secrets.h`
existente ya no se usan; no hace falta cambiar credenciales, CA ni estado NVS.

1. Reservar **192.168.0.3** para el PC y permitir acceso desde la Wi-Fi al puerto
   **8443**. El sketch conecta directamente a esa IP: no necesita resolver
   `telemetry.lab.test` ni cambiar el DNS del router o el archivo `hosts`.
   **telemetry.lab.test** sigue siendo el nombre TLS/SNI y HTTP del servidor.
2. Fuera del repositorio y del DocumentRoot, crear una CA y una hoja para ese
   nombre. Proteger la clave CA con contraseña; restringir por ACL de Windows
   las claves privadas. Ejemplo con el OpenSSL de XAMPP, en ese directorio privado:

   ```powershell
   $opensslE1 = 'C:/xampp/apache/bin/openssl.exe'
   & $opensslE1 req -x509 -newkey rsa:3072 -sha256 -days 30 -keyout ca.key -out ca.crt -subj '/CN=Lab2 E1 CA' -addext 'basicConstraints=critical,CA:TRUE' -addext 'keyUsage=critical,keyCertSign,cRLSign'
   & $opensslE1 req -new -newkey rsa:2048 -nodes -keyout server.key -out server.csr -subj '/CN=telemetry.lab.test'
   ```

   Crear allí `server.ext` con el nombre definitivo:

   ```ini
   basicConstraints=critical,CA:FALSE
   keyUsage=critical,digitalSignature,keyEncipherment
   extendedKeyUsage=serverAuth
   subjectAltName=DNS:telemetry.lab.test
   ```

   Firmar y verificar, sin usar tokens:

   ```powershell
   & $opensslE1 x509 -req -in server.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out server.crt -days 14 -sha256 -extfile server.ext
   & $opensslE1 verify -purpose sslserver -verify_hostname telemetry.lab.test -CAfile ca.crt server.crt
   ```

3. Copiar la plantilla a una configuración privada de Apache. Completar
   `ServerName` a `telemetry.lab.test`, rutas absolutas de hoja/clave y la IP reservada del ESP32 o subred
   del laboratorio. Comprobar módulos y configuración con `httpd.exe -M` y
   `httpd.exe -t` antes de incluirla/reiniciar Apache. Mantener PHP en loopback con
   el comando documentado en README; no usar `-S 0.0.0.0`. Permitir 8443 entrante
   solo en la interfaz/perfil privado y origen necesarios. No abrir 18082 a LAN.
4. Desde la LAN, verificar la cadena y el nombre **antes del token**:

   ```powershell
   & $opensslE1 s_client -connect 192.168.0.3:8443 -servername telemetry.lab.test -CAfile ca.crt -verify_hostname telemetry.lab.test -verify_return_error
   ```

   Para probar el POST sin token, conectar por IP manteniendo nombre y CA:

   ```powershell
   curl.exe --noproxy '*' --cacert ca.crt --resolve telemetry.lab.test:8443:192.168.0.3 -i -X POST https://telemetry.lab.test:8443/api/v1/telemetry
   ```

   Debe devolver 401 JSON del backend. `--resolve` solo fija el destino TCP;
   conserva SNI, la comprobacion del nombre y `Host: telemetry.lab.test:8443`.
   Prueba negativa de nombre, sin token y conservando el SNI del virtual host:

   ```powershell
   & $opensslE1 s_client -connect 192.168.0.3:8443 -servername telemetry.lab.test -CAfile ca.crt -verify_hostname nombre-incorrecto.lab.test -verify_return_error
   ```

   Debe fallar. Repetir la prueba positiva con una CA ajena tambien debe fallar.
   El certificado existente con SAN **DNS:telemetry.lab.test** se conserva;
   no se requiere agregar SAN IP ni sustituir el certificado.

   Debe verificar OK. Probar sin Authorization un POST HTTPS: debe devolver 401
   JSON del backend. Una CA ajena y un nombre ajeno deben fallar la validación TLS.
   Nunca usar `curl -k`. Si aparece 404 HTML, redirección o TLS inválido, corregir
   el proxy/certificado antes de continuar. No habilitar trazas de cabeceras.
5. Copiar `secrets.example.h` a `secrets.h` junto al sketch (ignorado). Completar
   Wi-Fi, token hexadecimal de 64 caracteres de **esp32-02** y
   el PEM **público** `ca.crt`. No copiar la clave CA ni `server.key` al firmware.
   No imprimir tokens, pasarlos por argumentos ni pegarlos en evidencias.

La autenticidad depende de provisionar la CA por un canal confiable y proteger
su clave. SNTP no está autenticado: bloquearlo/manipularlo puede afectar la
disponibilidad y las comprobaciones temporales. No se usa para omitir la
verificación criptográfica del servidor. Revocación/rotación automatizada de CA
y aprovisionamiento seguro de fábrica quedan fuera de esta unidad de laboratorio.

## Secuencia inicial y compilación

Antes del primer arranque, consultar sin modificar datos:

```sql
SELECT MAX(t.seq) AS max_seq
FROM telemetry t JOIN devices d ON d.id = t.device_id
WHERE d.node_id = 'esp32-02';
```

Configurar `INITIAL_SEQUENCE` a un entero **mayor** que ese resultado, dentro de
0..9007199254740991; si no hay filas puede ser 0. Las pruebas de escritorio han
podido usar secuencias aleatorias altas. No ejecutar clientes de prueba con la
misma identidad durante el ensayo físico. `INITIAL_SEQUENCE` solo se consulta
cuando no existe el estado NVS: cambiarlo no modifica un pendiente ni su contador.
Tras borrar flash/cambiar particiones/reemplazar placa, volver a consultar MAX
y configurar un inicio nuevo; **no borrar NVS para resolver un error HTTP**.

Herramientas: Arduino IDE/CLI y core **esp32:esp32 3.3.8**, placa ESP32 Dev Module
(`esp32:esp32:esp32`, verificar que corresponde al ESP32 #2 físico). WiFi,
HTTPClient, Preferences y cJSON vienen con el core; no hay librerías externas.

```powershell
arduino-cli compile --fqbn esp32:esp32:esp32 --warnings all --build-path build/e1-esp32 lab2/firmware/esp32_02_http
```

Para comprobar compilación sin secretos, copiar el sketch y el header a una
carpeta de sketch dentro de `build/`, y allí copiar el ejemplo como `secrets.h`.
Los marcadores permiten compilar, pero el firmware se detiene sin transmitir.
No cargar automáticamente a una placa: identificar primero puerto y placa #2.

## Verificación reproducible

Prueba portable de estado con compilador C++ de escritorio (no necesita placa):

```powershell
g++ -std=c++11 -Wall -Wextra -pedantic lab2/tests/e1-state.cpp -o build/e1-state.exe
& ./build/e1-state.exe | & C:/xampp/php/php.exe lab2/tests/e1-contract.php
```

Comprobar el código de salida de **ambos** programas. C++ comprueba reserva,
recuperación del pendiente, clasificación de errores, límite de secuencia y
rollover; emite un JSON real del generador. PHP lo valida con el
`TelemetryService` existente, sin conectar PDO ni escribir filas. No simula
atomicidad eléctrica de NVS: esa parte exige placa real.

Aceptación física pendiente, registrar salidas sin secretos y consulta SQL:

| Ensayo | Resultado esperado |
|---|---|
| Primer POST | 201, `node_id=esp32-02`, PIR simulado en extensions, RSSI real |
| Reenvío idéntico/ACK perdido | 200, una sola fila y mismo received_at |
| Reiniciar con pendiente | mismo seq y JSON hasta ACK; luego secuencia mayor |
| Reiniciar después de ACK | no reutilizar secuencias ya consumidas |
| Apagar/restaurar Wi-Fi o backend | reconexión y backoff; pendiente sin cambios |
| CA/hostname inválidos | ningún token llega al HTTP del backend; no fallback |
| Token inválido / otra identidad | 401/403, parada y pendiente conservado |
| Mismo seq con contenido distinto | 409, parada; no saltar ni sobrescribir |
| SNTP inaccesible en arranque frío | aviso periódico; no transmitir |

Para inducir ACK perdido, cortar la respuesta en un proxy de prueba después del
commit SQL; apagar Wi-Fi al azar no demuestra necesariamente ese caso. No
resembrar ni borrar MariaDB/NVS para repetir ensayos. Consultar la fila mediante
las herramientas existentes y comprobar `extensions`, `seq`, RSSI y `received_at`.

## Evidencia de esta unidad

22/10/2026:

- Adaptacion sin DNS de telemetria compilada con core 3.3.8 y marcadores, sin
  advertencias: 1046964 bytes de programa y 48768 bytes globales. Revision del
  core confirma despacho virtual al adaptador, CA obligatoria, nombre TLS y
  generacion de `Host: telemetry.lab.test:8443` desde la URL. No es una captura
  de trafico: quedan pendientes el ensayo TLS y el POST desde la placa fisica.

- Compilación de firmware con marcadores y core 3.3.8, sin carga a hardware.
- `e1-state.cpp` compilado a objeto con GCC Xtensa (`-std=c++11 -Wall -Wextra
  -pedantic`), sin errores. **No ejecutado**: no se encontró compilador C++ nativo
  de escritorio. Queda pendiente ejecutar sus asserts con el comando anterior.
- El JSON de ejemplo de esta guía pasó `e1-contract.php` contra el validador de
  dominio, sin PDO. Esto valida el ejemplo, no la ejecución del generador C++.
- Sintaxis PHP y exclusión Git de `secrets.h` y `build/` comprobadas.

No se afirma todavía conexión TLS de una placa, pruebas eléctricas de NVS,
RSSI físico ni inserción desde ESP32. Las suites HTTP/MQTT/MariaDB existentes se
conservan; no se vuelven a ejecutar contra servicios sin preparar.
