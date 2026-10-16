# Laboratorio 2 — Preparación del entorno

Estado: preparación del entorno y base SQL/PDO; sin REST, MQTT ni firmware implementados.
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
3. Para el futuro desarrollo web, desde `lab2/` ejecutar `C:\xampp\php\php.exe -S 127.0.0.1:8000 -t public` y abrir `http://127.0.0.1:8000`. Este comando es una alternativa de desarrollo independiente de Apache y no se ejecutó durante la preparación. La carpeta pública está vacía: todavía no hay página funcional.
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

`Database::connect()` proporciona PDO reutilizable con excepciones, UTF-8 y preparaciones nativas. `timestamp` del contrato se guarda como `telemetry.ts`: Unix UTC en segundos o NULL; `received_at` lo genera independientemente el servidor en UTC. La secuencia se limita al entero seguro JSON; su persistencia tras reinicios queda pendiente del firmware. `value` es numérico; el futuro servicio validará 0/1 para PIR y valores finitos. `rssi` acepta -127..0 o NULL.

La extensión opcional documentada es `extensions`, objeto JSON almacenado sin cambiar identidad ni campos base; sus claves no se interpretan en esta etapa y nunca deben contener credenciales. El futuro servicio validará cada extensión admitida. La UNIQUE(device_id, seq) evita inserciones duplicadas; devolver el registro existente o detectar contenido diferente corresponde al futuro TelemetryService.

Próxima implementación: repositorios y TelemetryService compartido por REST y MQTT, previa autorización.
