# D1: entrada MQTT mediante PHP CLI

Sin paquetes nuevos: PHP supervisa `mosquitto_sub` 2.1.2, que carga credenciales
desde un archivo `-o` privado. `TelemetryService` permanece sin cambios; MQTT
usa sus mismas validaciones, transacción, comparación de contenido e idempotencia.
Solo se agregó al repositorio de dispositivos una búsqueda preparada por node_id.

## Broker aislado y seguridad

El broker existente de 127.0.0.1:1883 **no fue modificado ni declarado seguro**.
Para D1 se prepara otra instancia en **127.0.0.1:1884**, sin configuración global,
acceso anónimo ni exposición fuera de loopback. Todos sus archivos están en
`lab2/config/mqtt-private/`, excluido de Git. No ejecutar varias instancias con
el mismo puerto/directorio de persistencia.

Desde la raíz del repositorio, en PowerShell:

```powershell
& C:\xampp\php\php.exe lab2/scripts/setup-mqtt.php
& 'C:\Program Files\mosquitto\mosquitto.exe' -c "$PWD\lab2\config\mqtt-private\broker.conf"
```

La preparación no sobrescribe una configuración existente. Genera contraseñas
aleatorias en archivos de opciones privados, hashes con `mosquitto_passwd -U`,
ACL y configuración de broker local. No hay secretos en argumentos ni consola.
No compartir estos archivos; el directorio público sigue siendo `lab2/public/`.

Hay un usuario por nodo (`esp32-01`, `esp32-02`, `esp32-03`), que solo puede
publicar en `lab2/{su_node_id}/telemetry`. El usuario `lab2-subscriber` solo puede
leer `lab2/+/telemetry`. Se rechaza la publicación de un nodo en otro tópico.
No basta con revisar tópico o payload: MQTT no transmite al suscriptor el usuario
original. La identidad procede de autenticación más ACL por usuario del broker.
El payload debe además coincidir con el node_id autorizado y estar registrado en MariaDB.

En otra terminal:

```powershell
& C:\xampp\php\php.exe lab2/tests/d1-security.php
& C:\xampp\php\php.exe lab2/tests/d1-mqtt.php
```

La primera prueba exige un control positivo, rechazo anónimo y con contraseña
incorrecta, seis rechazos de publicaciones cruzadas entre nodos y rechazo de
escritura del subscriber. Inspecciona el motivo del PUBACK MQTT 5: el código de
salida 0 de mosquitto_pub por sí solo **no prueba aceptación**. No pretende que
un SUBACK aceptado pruebe permiso de lectura: Mosquitto puede filtrar por mensaje.
La recepción autorizada se comprueba en la prueba de integración.

El subscriber exige una evidencia local de esta prueba vinculada mediante hash
a los archivos de configuración/ACL/credenciales. Volver a verificar después
de cambios o sustitución del broker. No copiar esa evidencia a otro broker.

La integración publica datos nuevos, verifica persistencia real, duplicado,
conflicto y discordancia tópico/payload mediante auditoría privada. Luego detiene
el subscriber, publica otra secuencia y verifica recepción al reiniciarlo.
Cada ejecución conserva dos filas nuevas; no borra datos ni ejecuta schema/seed.
Detener un subscriber manual antes de ejecutar la prueba (bloqueo de instancia única).

## Ejecución y trazabilidad

```powershell
& C:\xampp\php\php.exe lab2/scripts/mqtt-subscriber.php --timeout=300
```

Sin timeout, funciona continuamente. Para parada ordenada, en otra terminal:

```powershell
Set-Content lab2/config/mqtt-private/runtime/stop stop
```

Antes de iniciar otra ejecución, retirar **solo esa señal de parada**:

```powershell
Remove-Item -LiteralPath lab2/config/mqtt-private/runtime/stop
```

El timeout devuelve 2; una parada ordenada o max-messages completado devuelve 0;
fallo de inicialización devuelve 1. La prueba utiliza señales propias y las retira.
Al finalizar, detener la instancia aislada del broker con Ctrl+C.

La sesión MQTT usa QoS 1, cliente estable `lab2-d1-backend` y expiración de 24 h;
el broker aislado tiene persistencia local. mosquitto_sub reconecta, y PHP lo
reinicia si sale. Su salida JSON escapada se agrega a `runtime/inbox.jsonl`.
Un cursor atómico avanza después del commit o rechazo trazable. Si falla PDO,
se conserva la posición y se reintenta con espera de 1 a 5 segundos. Reiniciar
PHP reanuda el cursor; commit previo sin checkpoint produce un duplicado seguro.

`audit.jsonl` registra UTC, posición, hash de mensaje, nodo/secuencia válidos,
resultado, id o código de rechazo; no contiene contraseñas, tokens ni payloads.
La cola privada conserva el mensaje original, incluidos los rechazados, para
diagnóstico. No publicar cola, credenciales ni logs privados. No truncar la cola,
editar el cursor ni ejecutar varios consumidores sobre estos mismos archivos.

**Límite explícito:** mosquitto_sub confirma al broker antes del commit PHP y
antes de garantizar escritura durable en disco. QoS 1 y esta cola no garantizan
entrega exactamente una vez ni pérdida cero ante caída brusca del proceso/SO.
PUBACK no es confirmación de persistencia en MariaDB. Una garantía más fuerte
necesitaría confirmación de aplicación o un cliente con ACK controlado; no se
instaló ni implementó esa ampliación. Tampoco se probaron caída de MariaDB,
reinicio brusco del broker o agotamiento de disco. No hay rotación automática
de cola/logs en D1.

## Si se exige usar 1883

No apuntar el subscriber al broker actual sin verificar su seguridad. Dentro
de lab2 se puede preparar una configuración equivalente con listener explícito
127.0.0.1:1883, allow_anonymous false, password_file y acl_file privados.
Si 1883 ya está ocupado, su responsable debe autorizar y realizar la parada o
el cambio externo antes de iniciar esa configuración. Después ajustar también
el puerto de los archivos de opciones y mqtt.local.php, repetir d1-security y
d1-mqtt. No se realizó ningún cambio externo a lab2. Para acceso desde otra
máquina se requeriría otro diseño de listener y TLS, fuera de D1.
