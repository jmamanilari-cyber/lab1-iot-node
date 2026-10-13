# Laboratorio 1: nodo IoT con ESP32

Estado documental de los archivos disponibles, sin reproducir un historial de desarrollo anterior.

- `firmware/`: sketches A, B, C (original e instrumentado) y D.
- `services/`: receptores y herramientas de captura/verificación.
- `data/`: resultados CSV de experimentos A, B y D.
- `evidence/`: capturas y serie instrumental C (`C_nueva_05`).
- `docs/diagrams/`: diagramas de arquitectura.
- `docs/AUDITORIA_COHERENCIA.md`: cotejo entre resultados y datos.

**Experimento C:** 30 ciclos con retorno de Wi-Fi (media 15719 ms); 30 intentos de publicación MQTT, todos sin ACK confirmado (`ACK_TIMEOUT`). El retorno del enlace no demuestra la recepción por el servidor.

**Credenciales:** la copia para Git no incluye `secrets.h`, `.venv/` ni claves incrustadas en sketches. Configure las redes en su entorno privado antes de compilar; no vuelva a publicar secretos.

**Historial:** esta copia representa una instantánea del estado aportado. No se afirma un historial de commits anterior. Consulte `PUBLICAR_EN_GIT.md`.
