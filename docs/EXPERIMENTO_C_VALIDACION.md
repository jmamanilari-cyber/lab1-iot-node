> **Actualización de estado (posterior a la preparación de esta guía).** En `evidence/C_nueva_05/` ahora aparecen registros de una ejecución de 30 ciclos. Hay 30 eventos WIFI_DOWN/WIFI_UP y 30 PUBLISH_OK, pero todos terminan con ACK_TIMEOUT. `verification_report.txt` registra 0/30 ciclos con recepción correlacionada; la recuperación de aplicación no está confirmada. Las instrucciones y la afirmación de “sin ejecución física ni mediciones nuevas” que siguen describen la **etapa anterior** de preparación, no la evidencia incorporada después.

# Experimento C: medición y verificación de una NUEVA serie de 30 ciclos

**Estado:** instrumental preparado; **sin ejecución física ni mediciones nuevas incorporadas**. Este trabajo no demuestra que los 30 valores históricos de `Experimento_C_Final(2).csv` sean reales, ni reescribe ese archivo. El README antiguo del proyecto no se utilizó para decidir la instrumentación.

## 1. Qué mide exactamente

La versión anterior `firmware/experiment_c_wifi_recovery/experiment_c_wifi_recovery.ino` solo obtenía el tiempo entre **detección de Wi-Fi caído** y **detección de Wi-Fi reconectado**, en 5 pruebas. El nuevo firmware `firmware/experiment_c_verified/experiment_c_verified.ino` agrega telemetría MQTT y correlación de ACK del receptor:

- `WIFI_DOWN` (`t0`, ESP32 `millis()`): detección de pérdida de asociación Wi-Fi.
- `WIFI_UP` (`t1`): detección de asociación Wi-Fi recuperada.
- `MQTT_UP`: conexión al broker y suscripción a ACK.
- `PUBLISH_OK`: la biblioteca MQTT aceptó publicar el primer mensaje del ciclo (no asegura entrega por sí mismo).
- `ACK_OK` (`t2`): el ESP32 recibió el ACK de aplicación con el `run_id`, `cycle` y `seq` esperados.

Se calculan: `wifi_ms = t1-t0`, `post_wifi_ack_ms = t2-t1`, `total_ms = t2-t0`.

**Limitación esencial:** `wifi_ms` incluye el tiempo durante el cual el AP sigue apagado o inaccesible y el retardo de detección; **NO** mide exclusivamente el tiempo desde la restauración del AP. El nombre más preciso es *intervalo observado desde la caída Wi-Fi hasta la reconexión*. `post_wifi_ack_ms` mide hasta la **recepción del ACK en el ESP32**, no solamente hasta el momento en que el receptor recibió telemetría. Las marcas de tiempo del receptor (reloj de PC) demuestran recepción pero no se restan de los `millis()` del ESP32.

Cada evento lleva `run_id` nuevo por arranque, ciclo y secuencia. No aceptamos un ACK histórico/de otro ensayo como recuperación del ciclo actual. Solo son válidos los ciclos con una caída, reconexión, publicación, recepción de receptor y ACK congruentes.

## 2. Requisitos

- ESP32 con cable USB de datos, PIR, relé y LED conectados igual que en el proyecto base; fuente adecuada para el módulo relé.
- Arduino IDE con paquete de placas **ESP32 de Espressif** y biblioteca **PubSubClient** instalada.
- Broker Mosquitto en tu PC, en una red local controlada. No usar el listener anónimo del laboratorio en Internet.
- Python instalado en Windows, misma PC o una que alcance al broker.
- Una red Wi-Fi cuya conexión del ESP32 pueda interrumpirse y restaurarse en condiciones controladas. Si solo cortas la conexión WAN a Internet y la asociación Wi-Fi sigue activa, **no** habrá `WIFI_DOWN`.

## 3. Preparar el proyecto (Windows / PowerShell)

Desde PowerShell, `cd` a la carpeta raíz `Lab1_repositorio` (evitar trabajar en `C:\Windows\System32`):

```powershell
py -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r services\requirements_c.txt
```

La carpeta nueva `firmware/experiment_c_verified/` incluye `secrets.example.h`. Haz una copia local llamada `secrets.h`, **dentro de la misma carpeta**, y completa tus datos:

```powershell
Copy-Item firmware\experiment_c_verified\secrets.example.h firmware\experiment_c_verified\secrets.h
notepad firmware\experiment_c_verified\secrets.h
```

- `WIFI_SSID`: nombre de tu red Wi-Fi.
- `WIFI_PASSWORD`: contraseña de tu red.
- `MQTT_BROKER_IP`: dirección IPv4 de la PC que ejecuta Mosquitto, no la IP del ESP32; averíguala con `ipconfig`.
- `MQTT_BROKER_PORT`: 1883 a menos que lo hayas cambiado.

`secrets.h` está excluido por `.gitignore`. **El ZIP original tiene credenciales Wi-Fi incrustadas en otros sketches**; no publiques el proyecto sin sustituirlas o cambiarlas.

## 4. Instalar y comprobar Mosquitto

Usa la instalación de Mosquitto ya utilizada en el laboratorio. Arráncalo con el archivo local `broker/mosquitto.conf` (según cómo instalaste Mosquitto):

```powershell
mosquitto -c broker\mosquitto.conf -v
```

Si `mosquitto` no es reconocido, inicia el ejecutable instalado o el servicio de Mosquitto que tengas configurado. Comprueba que el puerto 1883 esté disponible y que el firewall permita al ESP32 alcanzar el broker **solo dentro de tu LAN**. Mantén esta ventana abierta.

## 5. Iniciar la captura antes de la ejecución

Para una prueba nueva, crea una carpeta exclusiva, por ejemplo `evidence\C_nueva_01`. Nunca reutilices una carpeta existente con resultados. Abre DOS nuevas ventanas PowerShell dentro de la raíz del proyecto:

**Ventana 1: receptor MQTT**

```powershell
.\.venv\Scripts\python.exe services\c_mqtt_receiver.py --broker 192.168.1.40 --output-dir evidence\C_nueva_01
```

Sustituye `192.168.1.40` por la IP real del broker. Verifica `C_RECEPTOR_READY`. Detén cualquier otro receptor `mqtt_ack.py` durante esta prueba para evitar múltiples respuestas.

**Cargar el firmware:** abre `firmware/experiment_c_verified/experiment_c_verified.ino` en Arduino IDE, selecciona la placa ESP32 correcta y el puerto, y pulsa **Subir**. Si compilar falla porque falta `PubSubClient.h`, instala esa biblioteca en el administrador de bibliotecas. **Cierra el monitor serie de Arduino IDE** después de subirlo.

**Ventana 2: captura serial** (ajusta `COM5` al puerto real que ves en Arduino IDE o Administrador de dispositivos):

```powershell
.\.venv\Scripts\python.exe services\c_serial_capture.py --port COM5 --output-dir evidence\C_nueva_01
```

Abre el puerto solo desde esta aplicación; normalmente se reinicia el ESP32 al abrirlo. Si no aparece el arranque, pulsa **EN** una vez sin desconectar el USB. Espera la línea `C_SESSION` y luego `C_READY`. Deben verse `C_RECEPTOR_READY` en la primera ventana y `C_READY` en la segunda **antes del primer corte**.

## 6. Ejecutar 30 ciclos y documentar el procedimiento

1. Deja el ESP32 encendido por USB y la captura serial funcionando durante todo el experimento. Anota la configuración de red, ubicación del ESP32, modelo/AP, intensidad aproximada y mecanismo de interrupción.
2. Con el sistema en `C_READY`, desactiva temporalmente la **señal Wi-Fi del punto de acceso** mediante la opción de administración del router/AP o su control normal de alimentación (no manipules conductores eléctricos expuestos). **No reinicies el ESP32 ni desconectes su USB.**
3. Comprueba en consola la línea `C_EVENT,...,WIFI_DOWN,...`; si no aparece, no se detectó un corte Wi-Fi válido.
4. Restablece la señal Wi-Fi, sin cambiar la configuración, y espera a `WIFI_UP`, `MQTT_UP`, `PUBLISH_OK`, `ACK_OK` y `C_RESULT`. En el receptor debe aparecer `C_RECEIVED` con el mismo `run_id`, `cycle` y `seq`.
5. Espera el próximo `C_READY` antes de otro corte. Repite hasta `C_SUMMARY,<run_id>,30,<exitos>,<fallidos>`; **no reinicies el dispositivo entre ciclos**.
6. Mantén intervalos de interrupción y de restauración lo más uniformes posible; anota cualquier incidencia (reinicio, caída del broker, corte repetido, fallo del relé, etc.). Dado que las mediciones incluyen la duración de la caída, variar cuánto tiempo permanece apagado el AP modifica `wifi_ms`.
7. Cuando aparezca `C_SUMMARY`, termina el capturador con Ctrl+C y después el receptor con Ctrl+C. Conserva las trazas originales.

Para una prueba piloto puedes cambiar `NUM_CICLOS` de 30 a 2, repetir con una carpeta diferente y usar `--cycles 2` en el verificador. Para el informe final devuelve `NUM_CICLOS = 30` y ejecuta de nuevo con una carpeta vacía. **No sumes datos de ejecuciones piloto para aparentar una serie continua de 30.**

## 7. Verificar automáticamente la evidencia

```powershell
.\.venv\Scripts\python.exe services\c_verify.py --output-dir evidence\C_nueva_01
```

El script exige exactamente un conjunto coherente de 30 ciclos en el mismo `run_id`, un resumen final sin fallos, un `C_EVENT` por transición requerida, valores numéricos consistentes, un evento correspondiente en el receptor y coincidencia de los CSV con los registros crudos. Devuelve **COMPLETO** únicamente si todas las verificaciones documentales terminan bien. Si hay fallos, imprime **INCOMPLETO** y explica cuáles; no añade ni corrige valores automáticamente.

En `evidence/C_nueva_01/` quedarán:

| Archivo | Función |
|---|---|
| `device_raw.log` | Monitor serie íntegro con fecha/hora de recepción del PC. |
| `device_events.csv` | Eventos del ESP32, con `millis()` y secuencias. |
| `device_results.csv` | Resultado declarado por el dispositivo por ciclo. |
| `receiver_raw.jsonl` | Mensajes MQTT recibidos por el suscriptor y los ACK intentados. |
| `receiver_events.csv` | Índice de mensajes del receptor para correlacionar ciclos. |
| `verified_c_results.csv` | Tiempos calculados/rechazados al cruzar ambos registros. |
| `verification_report.txt` | Cobertura, anomalías, estadísticas (si procede) y SHA-256 de las trazas. |

No hay datos artificiales incluidos en estos archivos de salida: **se crean solo al ejecutar las pruebas**. El script comprueba consistencia documental, pero **no puede verificar por sí mismo la autenticidad física** de un archivo que alguien haya manipulado antes de ejecutarlo.

## 8. Capturas que debes incluir en el informe

- **Captura 1:** `C_SESSION` y `C_READY`, con `run_id` visible.
- **Captura 2:** un ciclo completo donde se vean `WIFI_DOWN`, `WIFI_UP`, `PUBLISH_OK`, `ACK_OK` y `C_RESULT`, mismo número de ciclo y secuencia.
- **Captura 3:** receptor `C_RECEIVED` para el mismo `run_id`, `cycle` y `seq`.
- **Captura 4:** `C_SUMMARY` de la ejecución y `verification_report.txt` con `COMPLETO` cuando corresponda.
- Adjunta todos los archivos de salida (no solo capturas). El registro crudo es más útil que una imagen recortada.

**Modelo de estructura de línea (placeholders, NO una captura real):**

```text
C_EVENT,<run_id>,<ciclo>,<millis>,WIFI_DOWN,<seq>
C_EVENT,<run_id>,<ciclo>,<millis>,WIFI_UP,<seq>
C_EVENT,<run_id>,<ciclo>,<millis>,PUBLISH_OK,<seq>
C_EVENT,<run_id>,<ciclo>,<millis>,ACK_OK,<seq>
C_RESULT,<run_id>,<ciclo>,<wifi_ms>,<post_wifi_ack_ms>,<total_ms>,<seq>,ACK_OK
```

## 9. Qué puede afirmarse en el informe

Si el archivo `verification_report.txt` indica **COMPLETO** y las capturas/documentación del ensayo corresponden a esa ejecución, el informe podrá afirmar para **esa nueva ejecución** que se realizaron 30 interrupciones observadas, que el dispositivo recuperó la asociación Wi-Fi y que se recibió un ACK correspondiente por ciclo, y presentar las estadísticas reales que el verificador calcule. Puede citarse la coincidencia entre logs del dispositivo y del receptor.

No describas `post_wifi_ack_ms` como tiempo de recepción **del receptor** (incluye vuelta del ACK). No describas `wifi_ms` como demora exclusiva de reconexión **después de restablecer el AP** (incluye el tiempo del corte). No reutilices el promedio histórico de 7 992,90 ms si el nuevo ensayo arroja otros valores.

Conserva la evidencia antigua de 5 cortes como experimento **anterior**, separada de la nueva serie. La nueva instrumentación **no confirma ni refuta automáticamente** la procedencia de los 30 tiempos antiguos de `Experimento_C_Final(2).csv`.

## 10. Limitaciones de validación del software

- El sketch nuevo y los scripts fueron elaborados mediante revisión del código y pruebas de lógica del verificador, pero no se han compilado con `arduino-cli` ni ejecutado en hardware en este entorno.
- Se requiere realizar una prueba piloto de 2 ciclos para comprobar biblioteca, placa, red, puerto y logs antes de la serie final.
- `PubSubClient` usa publicaciones QoS 0; el ACK de aplicación confirma la recepción por el cliente suscriptor cuando llega al ESP32, no asegura persistencia en almacenamiento externo.
- El monitor serial se sondea aproximadamente cada 20 ms, más el tiempo de llamadas de red. No afirmar precisión submilisegundo.
- La validez experimental también depende del montaje, control del AP y documentación del operador; el verificador automatizado solo comprueba coherencia de evidencias digitales.
