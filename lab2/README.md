# Laboratorio 2 — Preparación del entorno

Estado: C1 implementada: recepción HTTP de telemetría, autenticación, validación y persistencia. Sin otros endpoints, MQTT ni firmware implementados.
El Laboratorio 1 permanece en sus directorios originales.

## Entorno comprobado (15 de octubre de 2026)

- Windows con XAMPP instalado en `C:\xampp`.
- PHP CLI 8.2.12; extensiones `PDO` y `pdo_mysql` habilitadas.
- Apache 2.4.58 en ejecución; puertos 80 y 443 en escucha.
- MariaDB 10.4.32 en ejecución; puerto 3306 en escucha. No se probó autenticación ni acceso a una base de datos.
- Mosquitto no detectado en PATH ni en las ubicaciones habituales; sin proceso activo ni escucha en 1883/8883. Su disponibilidad queda pendiente para MQTT.

No se instalaron componentes ni se iniciaron o modificaron servicios o configuraciones.

## Estructura

```text
lab2/
  public/     Entrada web futura
  src/        Lógica PHP futura
  config/     Configuración local privada
  sql/        Esquema y scripts futuros
  firmware/   Firmware futuro
  docs/       Documentación del laboratorio
  README.md
  .gitignore
```

Estructura provisional: el acuerdo de la auditoría anterior no está disponible en el repositorio. Los directorios vacíos existen localmente; Git no los conserva. Se omiten archivos de relleno en esta etapa.

## Inicio básico

1. Comprobar PHP con `C:\xampp\php\php.exe -v` y las extensiones con `C:\xampp\php\php.exe -m`.
2. Consultar el panel `C:\xampp\xampp-control.exe`. Si Apache o MySQL (MariaDB en XAMPP) están detenidos, solicitar autorización antes de iniciarlos.
3. Para C1, utilizar el servidor y la ruta POST indicados al final de este documento. No existe una página web de inicio.
4. Antes de implementar MQTT, confirmar la ruta o disponibilidad de Mosquitto. No instalarlo ni iniciarlo sin autorización.

## Configuración privada y Git

Guardar la configuración real únicamente en `config/` o en archivos `.env` locales, excluidos de Git. No escribir contraseñas, tokens ni credenciales en código, SQL, firmware o documentación. La configuración local de Mosquitto también está excluida. Cualquier futuro ejemplo versionado debe contener solo marcadores ficticios y revisarse antes de añadirlo.

El `.gitignore` raíz ya excluye `.env`, `.env.*`, `*.local` y `secrets.h`; `lab2/.gitignore` agrega protección local sin modificar el Laboratorio 1. No se añadieron credenciales ni se crearon commits.

## Inicializar SQL y comprobar PDO

Desde la raíz del repositorio, en **CMD**, con una cuenta autorizada para crear la base:

```bat
C:\xampp\mysql\bin\mysql.exe --default-character-set=utf8mb4 -u USUARIO_ADMIN -p < lab2\sql\schema.sql
C:\xampp\mysql\bin\mysql.exe --default-character-set=utf8mb4 -u USUARIO_ADMIN -p < lab2\sql\seed.sql
```

Ejecutar el segundo comando solo si el primero termina correctamente. El esquema crea una base nueva `lab2` y falla si ya existe: no usar `--force`, borrar tablas ni repetir sobre una base existente sin revisarla. El seed inserta tres nodos sin modificar registros previos; repetirlo falla por identidad duplicada. Los tokens quedan en NULL (autenticación de los nodos deshabilitada hasta su aprovisionamiento).

Preparar una cuenta dedicada con permisos SELECT, INSERT y UPDATE sobre `lab2.*`; no utilizar la cuenta administrativa en la aplicación. Copiar `config/database.example.php` a `config/database.local.php` y completar las credenciales privadas. El ejemplo es versionable; el archivo local queda ignorado. No se crea ni se cambia ninguna cuenta automáticamente.

Comprobar conexión, consulta preparada nativa y sesión UTC, sin escrituras:

```bat
C:\xampp\php\php.exe lab2\sql\check-connection.php
```

Salida correcta: `OK`; ante configuración ausente o error, devuelve código 1 sin mostrar contraseñas ni errores del controlador. No comprueba todavía el esquema ni el seed.

`Database::connect()` proporciona PDO reutilizable con excepciones, UTF-8 y preparaciones nativas. `timestamp` del contrato se guarda como `telemetry.ts`: Unix UTC en segundos (0..253402300799, por el esquema) o NULL; `received_at` lo genera independientemente el servidor en UTC. La secuencia se limita al entero seguro JSON; su persistencia tras reinicios queda pendiente del firmware. TelemetryService valida `value` numérico 0/1 para PIR; no admite booleanos ni cadenas. `rssi` acepta enteros -127..0 o NULL.

La extensión opcional documentada es `extensions`: objeto JSON de metadatos, sin cambiar identidad ni campos base; no debe contener credenciales. Se admiten claves alfanuméricas con guion/subrayado (1..64 caracteres), escalares JSON, objetos y listas anidados hasta profundidad 8, máximo 4096 bytes serializados. Sus valores no se interpretan ni ejecutan. Se rechazan campos superiores desconocidos. Omitir `extensions` equivale a `{}`; `null` y listas en su raíz son inválidos. La comparación de contenido ignora el orden de claves de objetos y conserva el orden de listas.

## C1: POST /api/v1/telemetry

Desde la raíz del repositorio, con PDO ya configurado:

```bat
C:\xampp\php\php.exe lab2\scripts\provision-tokens.php
C:\xampp\php\php.exe -d display_errors=0 -S 127.0.0.1:18082 -t lab2\public lab2\public\index.php
```

El primer comando aprovisiona los tres nodos de prueba si no tienen token; conserva tokens existentes que coincidan con la copia local. MariaDB guarda solo `sha256:` más el hash de un token aleatorio de 256 bits. Los tokens reales se guardan únicamente en `config/test-tokens.local.json`, ignorado por Git y fuera del directorio público. El script nunca los imprime. Ejecutar un solo aprovisionamiento a la vez. Para renovar explícitamente un nodo: `C:\xampp\php\php.exe lab2\scripts\provision-tokens.php esp32-01 --rotate`. Esto invalida su token anterior; no altera telemetría. Si falta la copia privada de un token existente, renovarlo explícitamente.

Con el servidor activo, en otra terminal:

```bat
C:\xampp\php\php.exe lab2\tests\c1-http.php http://127.0.0.1:18082
C:\xampp\php\php.exe lab2\tests\c1-concurrency.php
```

El cliente de pruebas lee el token privado sin mostrarlo ni incluirlo en argumentos; envía Authorization: Bearer al endpoint local. El contrato exige `node_id`, `seq` entero 0..9007199254740991, `timestamp` (nullable), `value` y `rssi` (nullable). `node_id` debe coincidir con la identidad autenticada. Cuerpo máximo: 16384 bytes. El servidor de desarrollo solo debe escuchar en loopback; para transportar tokens por una red se requiere HTTPS.

Respuestas JSON: `ok`, `data` o `error`, `meta.api_version`. Creación: 201; duplicado idéntico: 200 con el mismo registro y `received_at`; contenido diferente para la misma clave: 409. Token ausente/inválido: 401; identidad diferente: 403; JSON ilegible: 400; contrato inválido: 422; fallo interno: 500 sin detalles sensibles. Autenticación precede a la lectura del JSON. `token_hash=NULL` deshabilita la autenticación del nodo.

El Controller adapta HTTP; TelemetryService no lee cabeceras ni emite respuestas HTTP. Recibe identidad confiable desde el adaptador y aplica validación y transacción común. No pasar una identidad del payload como identidad confiable al integrar otros transportes. Los repositorios utilizan consultas preparadas. La transacción bloquea el dispositivo, intenta insertar, consulta la fila existente ante UNIQUE y compara contenido; actualización de `last_seen` e inserción se confirman juntas. Los conflictos revierten la transacción.

Las pruebas T01–T04 verifican respuestas y persistencia real; también comprueban 400, 403, tipos/límites y ausencia de inserciones rechazadas. La prueba concurrente usa cuatro procesos independientes del servicio contra MariaDB: el servidor PHP de Windows atiende HTTP en serie. Cada ejecución conserva una fila con secuencia nueva; no elimina datos ni ejecuta schema/seed. Detener el servidor con Ctrl+C al terminar.

C2 y los demás endpoints requieren autorización antes de implementarse.
