# Auditoría documental del laboratorio 1

Base revisada: `Lab1_repositorio(1).zip` y `Informe_Laboratorio_1_ESP32_requisitos_ajustados.docx`.

## Concordancia entre resultados e información registrada

| Elemento | Resultado del cotejo | Estado |
|---|---|---|
| A, HTTP | 30/30; mediana 73,50 ms; media 123,37 ms; 3142 bytes | Concordante con `data/HTTP_30_pruebas.csv` |
| A, MQTT | 30/30; mediana 12,00 ms; media 15,60 ms; 3142 bytes | Concordante con `data/MQTT_30_pruebas.csv` |
| B, CERCA | 30/30; RSSI medio -3,50 dBm; latencia mediana 42 ms | Concordante con `data/RSSI_CERCA.csv` |
| B, LEJOS | 30/30; RSSI medio -66,83 dBm; latencia mediana 107 ms | Concordante con `data/RSSI_LEJOS.csv` |
| C, Wi-Fi | 30 ciclos; media 15719 ms; mediana 15654 ms; rango 9127–23825 ms | Concordante con `evidence/C_nueva_05/device_results.csv` y eventos WIFI_DOWN/WIFI_UP |
| C, aplicación | 30 PUBLISH_OK, 30 ACK_TIMEOUT, 0 recepciones correlacionadas | No acredita entrega confirmada ni recuperación completa del servicio; el informe lo indica |
| D | CSV y capturas contienen valores diferentes de bytes | Diferencia ya identificada en sección 8.4; no deben combinarse como una sola serie |
| Diagramas | `docs/diagrams/context.png`, `components.png`, `sequence.png` | Presentes |
| Git | No se incluyó la carpeta `.git` en el ZIP | No se puede verificar historial previo de commits |

## Ajustes documentales

- En el Anexo C, la oración que atribuía todo el experimento C solo a una captura anterior quedó desactualizada después de incorporar la serie de 30 ciclos. Se corrigió en la revisión del Word.
- `docs/EXPERIMENTO_C_VALIDACION.md` fue escrito en una etapa de instrumentación anterior: su encabezado señalaba que aún no existía una ejecución nueva. Hay archivos posteriores en `evidence/C_nueva_05/`; se añadió una nota de actualización para separar ambos momentos, sin reescribir las pruebas.
- El antiguo `README.md` y `GIT_INSTRUCTIONS.md` del ZIP no se usan como prueba de ejecución; en esta copia para Git se reemplazan por instrucciones actuales de publicación.

## Alcance y asuntos no verificables sin montaje físico

- El cotejo es **documental**: constata concordancia entre valores presentes en los archivos, no certifica cómo fueron obtenidos ni garantiza correspondencia exacta del firmware cargado en el equipo.
- El intervalo `wifi_ms` se obtiene entre los eventos `WIFI_DOWN` y `WIFI_UP`. No es necesariamente el tiempo desde la restauración del punto de acceso.
- No se encontró ACK válido en la serie nueva C. `receiver_events.csv` no tiene filas y `receiver_raw.jsonl` está vacío; la recuperación completa del servicio no está demostrada.
- Las mediciones de A, B y D se contrastaron con los CSV y descripciones disponibles. Las capturas no sustituyen la trazabilidad exacta de cada fila de los CSV.
- La modularidad entre sketches es limitada por duplicación de código.
- El ZIP no incluye metadatos `.git`. No se debe reconstruir retrospectivamente un historial ficticio de desarrollo.

## Revisión previa a publicar

- Se quitaron `secrets.h` y `.venv/` de la copia preparada.
- Los sketches con valores embebidos de Wi-Fi/MQTT fueron sustituidos por marcadores de configuración. La persona que compile esos sketches debe ingresar sus valores *solo en su copia local* y no publicarlos.
- Esta auditoría no implica que exista un repositorio remoto actualizado. La publicación se debe completar con Git usando la cuenta del equipo y un único commit actual que describa el estado incorporado.
