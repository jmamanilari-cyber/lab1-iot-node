"""Captura USB opcional. --list enumera sin abrir puertos; --save guarda asociaciones."""

import argparse
from collections import Counter, deque
from contextlib import ExitStack
from datetime import datetime, timezone
import json
from pathlib import Path
import re
import os
import sys
import tempfile
import threading
import time

import serial
from serial.tools import list_ports


NODES = ("esp32-01", "esp32-02", "esp32-03")
BAUDRATE = 115200
MAX_PARTIAL = 16384
MAX_READ = 4096
QUEUE_MEMORY_BYTES = 256 * 1024
NODE_ID = re.compile(r"(?<![\w-])esp32-0[123](?![\w-])")
SENSITIVE = re.compile(
    r"(?i)\b(?:password|passwd|passphrase|contrase[ñn]a|ssid|bssid|bearer|"
    r"authorization|token|secrets?|credentials?|credenciales|api[_ -]?key)\b"
)
KEY_BEGIN = re.compile(r"-----BEGIN (?:[A-Z0-9]+ )*PRIVATE KEY-----")
KEY_END = re.compile(r"-----END (?:[A-Z0-9]+ )*PRIVATE KEY-----")
DEFAULT_CONFIG = Path(__file__).resolve().parents[1] / "config" / "serial-capture.local.json"


def usb_ports():
    """No abrir Bluetooth ni puertos serie internos sin VID/PID USB."""
    return sorted((p for p in list_ports.comports() if p.vid is not None and p.pid is not None),
                  key=lambda p: p.device)


def usb_key(port):
    if not port.serial_number:
        return None
    # No incluir COM ni asumir que CP210x/CH340 implica un nodo determinado.
    return json.dumps([port.vid, port.pid, port.serial_number], ensure_ascii=True)


def location_key(port):
    if not port.location:
        return None
    return json.dumps([port.vid, port.pid, port.location], ensure_ascii=True)


def signature(port):
    return port.vid, port.pid, port.serial_number, port.location, port.hwid


def describe(port):
    def clean(value):
        return "".join(c if c.isprintable() else "?" for c in str(value or "sin dato"))
    return (f"{clean(port.device)} | {clean(port.description)} | "
            f"VID:PID={port.vid:04X}:{port.pid:04X} | "
            f"serie={clean(port.serial_number)} | USB={clean(port.location)}")


def load_config(path):
    if not path.exists():
        return {"version": 1, "serials": {}, "locations": {}, "ambiguous_serials": []}
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict) or data.get("version") != 1:
        raise ValueError("Configuracion local incompatible.")
    for field in ("serials", "locations"):
        if not isinstance(data.get(field), dict) or any(
                not isinstance(k, str) or v not in NODES for k, v in data[field].items()):
            raise ValueError("Asociaciones locales invalidas.")
    ambiguous = data.setdefault("ambiguous_serials", [])
    if not isinstance(ambiguous, list) or any(not isinstance(k, str) for k in ambiguous):
        raise ValueError("Identidades USB ambiguas invalidas.")
    return data


def save_config(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", dir=path.parent,
                                         prefix="serial-capture-", suffix=".local", delete=False) as file:
            temporary = Path(file.name)
            json.dump(data, file, indent=2, ensure_ascii=True)
            file.write("\n")
        temporary.replace(path)
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


class LineBuffer:
    """Consume cada byte una vez; un timeout no vuelve a emitir el ultimo fragmento."""
    def __init__(self):
        self.pending = bytearray()
        self.dropping = False

    def feed(self, chunk):
        self.pending.extend(chunk)
        lines = []
        while True:
            end = self.pending.find(b"\n")
            if end < 0:
                if len(self.pending) > MAX_PARTIAL:
                    self.pending.clear()
                    self.dropping = True
                break
            raw = bytes(self.pending[:end])
            del self.pending[:end + 1]  # Retirar ANTES de entregar al consumidor.
            if self.dropping or len(raw) > MAX_PARTIAL:
                raw = b"[LINEA DESCARTADA: excede limite]"
            self.dropping = False
            lines.append((raw, False))
        return lines

    def finish(self):
        raw = bytes(self.pending)
        self.pending.clear()
        if self.dropping:
            self.dropping = False
            return [(b"[LINEA DESCARTADA: excede limite]", True)]
        return [(raw, True)] if raw else []


class LogError(Exception):
    pass


class RawDiagnostics:
    """Medir exclusivamente resultados de read(), antes de delimitarlos o redactarlos."""
    def __init__(self, sample_limit=0):
        self.calls = self.timeouts = self.total_bytes = 0
        self.histogram = Counter()
        self.recent_reads = deque(maxlen=8)
        self.prefix = bytearray()
        self.lf_sample = b""
        self.sample_limit = sample_limit
        self.max_lf_batch = 0

    def observe(self, chunk, received):
        self.calls += 1
        if not chunk:
            self.timeouts += 1
            return
        offset = self.total_bytes
        self.total_bytes += len(chunk)
        self.histogram.update(chunk)
        lf = chunk.count(b"\n")
        self.max_lf_batch = max(self.max_lf_batch, lf)
        self.recent_reads.append({"offset": offset, "bytes": len(chunk), "lf": lf,
                                  "utc": received.isoformat()})
        # Como maximo sample_limit bytes en total, nunca un volcado ilimitado.
        prefix_limit = self.sample_limit // 2
        remaining = prefix_limit - len(self.prefix)
        if remaining > 0:
            self.prefix.extend(chunk[:remaining])
        if not self.lf_sample and lf >= 16 and lf * 2 >= len(chunk):
            self.lf_sample = bytes(chunk[:self.sample_limit - prefix_limit])

    def report(self):
        return {"read_calls": self.calls, "empty_reads": self.timeouts,
                "bytes": self.total_bytes, "lf": self.histogram[10],
                "cr": self.histogram[13], "nul": self.histogram[0],
                "max_lf_in_one_read": self.max_lf_batch,
                "byte_histogram": dict(sorted(self.histogram.items())),
                "recent_reads": list(self.recent_reads),
                "prefix_hex": bytes(self.prefix).hex(" "),
                "lf_burst_hex": self.lf_sample.hex(" ")}


class PendingFrames:
    """FIFO de lineas ya procesadas: un cursor, sin copias ni descarte por asociacion.

    La memoria esta limitada; si se demora la respuesta manual se usa un temporal
    fuera de las evidencias del laboratorio. Solo almacena mensajes ya redactados.
    El hilo lector es el unico consumidor, bajo PortReader.lock.
    """
    def __init__(self):
        self.file = tempfile.SpooledTemporaryFile(max_size=QUEUE_MEMORY_BYTES, mode="w+b")
        self.read_offset = 0
        self.write_offset = 0
        self.count = 0

    def __len__(self):
        return self.count

    def append(self, message, received):
        data = (json.dumps([message, received.isoformat()], ensure_ascii=True) + "\n").encode("ascii")
        try:
            self.file.seek(self.write_offset)
            if self.file.write(data) != len(data):
                raise OSError("Escritura temporal incompleta.")
            self.write_offset = self.file.tell()
        except OSError:
            # Un fallo de almacenamiento no es una desconexion USB recuperable.
            raise LogError from None
        self.count += 1

    def popleft(self):
        if not self.count:
            raise IndexError("Cola de captura vacia.")
        try:
            self.file.seek(self.read_offset)
            data = self.file.readline()
            # Retirar antes de entregar: un segundo flush nunca relee este registro.
            self.read_offset = self.file.tell()
            self.count -= 1
            if not self.count:
                self.file.seek(0)
                self.file.truncate()
                self.read_offset = self.write_offset = 0
        except OSError:
            raise LogError from None
        message, stamp = json.loads(data)
        return message, datetime.fromisoformat(stamp)

    def close(self):
        self.file.close()


class Capture:
    def __init__(self):
        self.stop = threading.Event()
        self.output_lock = threading.Lock()
        self.failed = False
        self.echo = True

    def emit(self, file, device, port, message, received=None):
        stamp = (received or datetime.now(timezone.utc)).isoformat(
            timespec="milliseconds").replace("+00:00", "Z")
        line = f"{stamp} [{device}] [{port}] {message}"
        with self.output_lock:
            try:
                file.write(line + "\n")
                file.flush()
                if self.echo:
                    print(line, flush=True)
            except OSError:
                self.failed = True
                self.stop.set()
                raise LogError from None


class PortReader(threading.Thread):
    """Una conexion y un buffer nuevos por apertura; solo este hilo lee el puerto."""
    def __init__(self, capture, port, diagnostic_bytes=0):
        super().__init__(name=port.device)
        self.capture = capture
        self.port = port
        self.lock = threading.Lock()
        self.close_requested = threading.Event()
        self.started_at = time.monotonic()
        self.node = None
        self.file = None
        self.evidence = set()
        self.frames = PendingFrames()
        self.raw = RawDiagnostics(diagnostic_bytes)
        self.lines_received = 0
        self.lines_written = 0
        self.complete_lines = 0
        self.partial_lines = 0
        self.empty_lines = 0
        self.rejected_lines = 0
        self.private_key = False
        self.error = False
        self.errors = []
        self.ignored = False
        self.source = None

    def accept(self, raw, partial, received):
        message = raw.decode("utf-8", errors="replace").rstrip("\r")
        if KEY_BEGIN.search(message):
            self.private_key = True
        hidden = self.private_key or bool(SENSITIVE.search(message))
        if self.private_key and KEY_END.search(message):
            self.private_key = False
        identities = set(NODE_ID.findall(message)) if not hidden and not partial else set()
        with self.lock:
            if not partial and self.complete_lines >= self.raw.histogram[10]:
                raise LogError("Registro sin un LF recibido que lo respalde.")
            if partial and (self.partial_lines or not self.bytes_received):
                raise LogError("Fragmento sin datos nuevos que lo respalden.")
            self.lines_received += 1
            self.partial_lines += int(partial)
            self.complete_lines += int(not partial)
            self.empty_lines += int(not message and not partial)
            if len(identities) == 1:
                self.evidence.update(identities)
            if len(self.evidence) > 1 or (self.node and self.evidence - {self.node}):
                self.capture.failed = True
                self.capture.stop.set()
                self.error = True
                self.rejected_lines += 1
                return
            if self.node and self.evidence == {self.node}:
                self.source = "firmware"
            message = ("[REDACTADO: posible dato sensible]" if hidden else
                       "".join(c if c.isprintable() or c == "\t" else "?" for c in message))
            if partial:
                message = "[LINEA PARCIAL] " + message
            if self.node is None:
                self.frames.append(message, received)
            else:
                # Drenar lo anterior antes de escribir lo nuevo. Cada linea toma
                # una sola via: FIFO pendiente O escritura directa, nunca ambas.
                self._flush_frames_locked()
                self._write_frame_locked(message, received)

    def _write_frame_locked(self, message, received):
        self.capture.emit(self.file, self.node, self.port.device, message, received)
        self.lines_written += 1

    def _flush_frames_locked(self):
        if self.node is None:
            return
        while self.frames:
            message, received = self.frames.popleft()
            self._write_frame_locked(message, received)

    def flush_frames(self):
        with self.lock:
            self._flush_frames_locked()

    def summary(self):
        return (f"[RX] {self.port.device}: bytes={self.bytes_received}, "
                f"lineas={self.lines_received}, escritas={self.lines_written}, "
                f"sin_asociar={len(self.frames)}, LF={self.raw.histogram[10]}, "
                f"CR={self.raw.histogram[13]}, NUL={self.raw.histogram[0]}, "
                f"vacias={self.empty_lines}, timeouts={self.raw.timeouts}, "
                f"coherente={'SI' if self.consistent() else 'NO'}, errores={self.errors}.")

    def record_error(self, error, stage):
        self.error = True
        # Solo tipos/codigos: nunca incluir mensajes arbitrarios ni datos recibidos.
        for _ in range(4):
            errno = getattr(error, "errno", None)
            winerror = getattr(error, "winerror", None)
            # pyserial 3.5 convierte ctypes.WinError() a repr dentro de SerialException,
            # sin conservar la excepcion original. Extraer SOLO codigos, nunca imprimir texto.
            if winerror is None and isinstance(error, serial.SerialException):
                codes = re.search(r"\b[A-Za-z]+Error\((\d+),.*?,\s*None,\s*(\d+)\)",
                                  str(error))
                if codes:
                    errno, winerror = map(int, codes.groups())
            self.errors.append({"stage": stage, "type": type(error).__name__,
                                "errno": errno, "winerror": winerror})
            error = error.__cause__ or error.__context__
            if error is None:
                break

    @property
    def bytes_received(self):
        return self.raw.total_bytes

    def consistent(self):
        return (self.raw.total_bytes == sum(self.raw.histogram.values()) and
                self.raw.histogram[10] == self.complete_lines and
                self.lines_received == self.complete_lines + self.partial_lines and
                self.lines_received == self.lines_written + len(self.frames) + self.rejected_lines)

    def save_diagnostic(self, campaign):
        report = self.raw.report()
        report.update({"port": self.port.device, "node": self.node,
                       "lines": self.lines_received, "complete_lines": self.complete_lines,
                       "partial_lines": self.partial_lines, "empty_lines": self.empty_lines,
                       "written": self.lines_written, "pending": len(self.frames),
                       "rejected": self.rejected_lines, "consistent": self.consistent(),
                       "errors": self.errors,
                       "pyserial": serial.VERSION})
        with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", dir=campaign,
                                         prefix=self.port.device + "-", suffix=".diagnostic.log",
                                         delete=False) as file:
            json.dump(report, file, indent=2, ensure_ascii=True)
            file.write("\n")
            print(f"[DIAGNOSTICO LOCAL] {file.name}")

    def run(self):
        connection = None
        buffer = LineBuffer()
        last_received = None
        stage = "open"
        try:
            connection = serial.Serial()
            connection.port = self.port.device
            connection.baudrate = BAUDRATE
            connection.timeout = 0.2
            connection.dtr = False
            connection.rts = False
            connection.open()
            stage = "read"
            while not self.capture.stop.is_set() and not self.close_requested.is_set():
                chunk = connection.read(min(connection.in_waiting or 1, MAX_READ))
                received = datetime.now(timezone.utc)
                self.raw.observe(chunk, received)
                if chunk:
                    last_received = received
                    for raw, partial in buffer.feed(chunk):
                        self.accept(raw, partial, received)
                else:
                    self.capture.stop.wait(0.01)
                self.flush_frames()
                if not self.consistent():
                    raise LogError("Bytes, delimitadores y registros no coinciden.")
        except (serial.SerialException, OSError) as error:
            self.record_error(error, stage)
        except Exception as error:
            self.record_error(error, stage)
            self.capture.failed = True
            self.capture.stop.set()
        finally:
            if connection is not None:
                try:
                    connection.close()
                except (serial.SerialException, OSError) as error:
                    self.record_error(error, "close")
            try:
                for raw, partial in buffer.finish():
                    self.accept(raw, partial, last_received or datetime.now(timezone.utc))
                self.flush_frames()
                if not self.consistent():
                    self.capture.failed = True
                    self.capture.stop.set()
            except (LogError, OSError) as error:
                self.record_error(error, "flush")
                self.capture.failed = True
                self.capture.stop.set()
            finally:
                try:
                    self.frames.close()
                except OSError as error:
                    self.record_error(error, "close_queue")
                    self.capture.failed = True
                    self.capture.stop.set()


def serial_candidate(port, ports, config, duplicated):
    counts = Counter(usb_key(p) for p in ports if usb_key(p))
    duplicated.update(config.get("ambiguous_serials", []))
    duplicated.update(key for key, count in counts.items() if count > 1)
    config["ambiguous_serials"] = sorted(duplicated)
    for key in duplicated:
        config["serials"].pop(key, None)
    key = usb_key(port)
    return config["serials"].get(key) if key and key not in duplicated else None


def location_candidate(port, ports, config):
    """Solo utilizable tras confirmacion explicita de la topologia fisica."""
    key = location_key(port)
    if not key or sum(location_key(p) == key for p in ports) != 1:
        return None
    return config["locations"].get(key)


def bind(reader, node, readers, files, config, ports, duplicated, source="manual"):
    if any(other is not reader and other.node == node for other in readers.values()):
        raise ValueError(f"Dos puertos reclaman {node}; no se mezclaran sus registros.")
    key = usb_key(reader.port)
    serial_candidate(reader.port, ports, config, duplicated)
    with reader.lock:
        if reader.evidence - {node}:
            raise ValueError("La asociacion contradice el identificador emitido por el firmware.")
        if key and key not in duplicated:
            previous = config["serials"].get(key)
            if previous and previous != node:
                raise ValueError("Identidad USB y firmware/asociacion en conflicto; revise la configuracion local.")
            config["serials"][key] = node
        location = location_key(reader.port)
        if location:
            # La toma USB es solo una pista manual: dos placas pueden intercambiarse.
            config["locations"][location] = node
        reader.file = files[node]
        reader.node = node
        reader.source = source
        # Solo publicar la asociacion. No copiar, volver a encuadrar ni reproducir
        # los datos de deteccion: el lector drenara la misma FIFO una unica vez.
    print(f"[ASOCIADO] {reader.port.device} -> {node}", flush=True)
    if not key and not location:
        print("[USB] Sin serie ni ubicacion estable: esta asociacion requiere confirmacion al reconectar.")


def console_line(buffer):
    """Consultar entrada sin bloquear deteccion, asociaciones ni reconexiones."""
    if not sys.stdin.isatty():
        raise ValueError("Se requiere consola interactiva o --confirm-locations con ubicaciones verificadas.")
    if os.name == "nt":
        import msvcrt
        while msvcrt.kbhit():
            char = msvcrt.getwch()
            if char == "\x03":
                raise KeyboardInterrupt
            if char in ("\r", "\n"):
                print()
                return "".join(buffer)
            if char == "\b":
                if buffer:
                    buffer.pop()
                    print("\b \b", end="", flush=True)
            elif char in ("\x00", "\xe0"):
                msvcrt.getwch()  # Codigo extendido de una tecla, no texto.
            elif char.isprintable():
                buffer.append(char)
                print(char, end="", flush=True)
        return None
    import select
    if not select.select([sys.stdin], [], [], 0)[0]:
        return None
    line = sys.stdin.readline()
    if not line:
        raise ValueError("Entrada manual cerrada.")
    return line.strip()


class AssociationPrompt:
    def __init__(self):
        self.reader = None
        self.buffer = []

    def begin(self, reader, readers, config, ports):
        self.reader = reader
        self.buffer = []
        self.hint = location_candidate(reader.port, ports, config)
        self.available = {node[-2:]: node for node in NODES
                          if all(r.node != node for r in readers.values())}
        print("\nSin identidad inequivoca: " + describe(reader.port), flush=True)
        if self.hint in self.available.values():
            print(f"Enter confirma la asociacion guardada en esta toma USB: {self.hint}.")
        print("Compruebe la etiqueta fisica; la ubicacion no identifica una placa intercambiada.")
        print(f"Nodo ({'/'.join(self.available)}), s=omitir, q=salir: ", end="", flush=True)

    def poll(self):
        answer = console_line(self.buffer)
        if answer is None:
            return None
        self.buffer = []
        answer = answer.strip().lower()
        if answer == "q":
            raise KeyboardInterrupt
        if answer == "s":
            return self.reader, None
        node = self.hint if not answer else self.available.get(answer)
        if node not in self.available.values():
            print("Asociacion no valida; indique 01/02/03, s o q: ", end="", flush=True)
            return None
        return self.reader, node

    def cancel(self):
        self.reader = None
        self.buffer = []


def finish_reader(reader, campaign, diagnostic_bytes):
    print(reader.summary())
    if diagnostic_bytes:
        reader.save_diagnostic(campaign)


def capture(args, config):
    root = Path(__file__).resolve().parents[1] / "logs" / "serial"
    root.mkdir(parents=True, exist_ok=True)
    prefix = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S_%fZ_")
    campaign = Path(tempfile.mkdtemp(prefix=prefix, dir=root))
    print(f"Campaña: {campaign}\nCtrl+C para detener. UTC de recepcion en laptop.", flush=True)
    controller = Capture()
    controller.echo = not getattr(args, "no_console_lines", False)
    diagnostic_bytes = getattr(args, "diagnostic_bytes", 0)
    confirm_locations = getattr(args, "confirm_locations", False)
    duration = getattr(args, "duration", None)
    deadline = time.monotonic() + duration if duration else None
    prompt = AssociationPrompt()
    readers = {}
    retry_after = {}
    duplicated = set()
    next_scan = 0
    ports = []
    written_nodes = set()
    saved_config = json.dumps(config, sort_keys=True)
    with ExitStack() as stack:
        files = {node: stack.enter_context((campaign / f"{node}.log").open(
            "x", encoding="utf-8", buffering=1, newline="\n")) for node in NODES}
        try:
            while not controller.stop.is_set():
                now = time.monotonic()
                if deadline is not None and now >= deadline:
                    break
                if now >= next_scan:
                    ports = usb_ports()
                    if ports:
                        serial_candidate(ports[0], ports, config, duplicated)
                    present = {p.device: p for p in ports}
                    for name, reader in list(readers.items()):
                        if name not in present or signature(present[name]) != signature(reader.port):
                            reader.close_requested.set()
                        if reader.source == "serial" and usb_key(reader.port) in duplicated:
                            with reader.lock:
                                reader.node = None
                                reader.file = None
                                reader.source = None
                            print(f"[USB] {name}: serie USB duplicada; requiere nueva asociacion.")
                        if not reader.is_alive():
                            reader.join()
                            detail = "error de apertura/lectura; cierre otros monitores serie" if reader.error else "desconectado"
                            print(f"[USB] {name}: {detail}; se volvera a detectar sin fijar COM.")
                            finish_reader(reader, campaign, diagnostic_bytes)
                            if reader.lines_written:
                                written_nodes.add(reader.node)
                            del readers[name]
                            retry_after[name] = now + 2
                    for port in ports:
                        if port.device not in readers and now >= retry_after.get(port.device, 0):
                            print("[USB] " + describe(port), flush=True)
                            reader = PortReader(controller, port, diagnostic_bytes)
                            readers[port.device] = reader
                            reader.start()
                    next_scan = now + 1
                    if args.save and json.dumps(config, sort_keys=True) != saved_config:
                        save_config(args.config, config)
                        saved_config = json.dumps(config, sort_keys=True)
                unresolved = []
                # Resolver TODOS los identificadores fiables antes de cualquier pregunta.
                for reader in list(readers.values()):
                    if reader.node or reader.ignored or not reader.is_alive() or reader.close_requested.is_set():
                        continue
                    with reader.lock:
                        evidence = set(reader.evidence)
                    known = serial_candidate(reader.port, ports, config, duplicated)
                    node = next(iter(evidence)) if len(evidence) == 1 else None
                    source = "firmware" if node else "serial"
                    if node is None:
                        if known:
                            node = known
                        elif confirm_locations:
                            node = location_candidate(reader.port, ports, config)
                            source = "location"
                    if node is None:
                        if now - reader.started_at >= args.probe_seconds:
                            unresolved.append(reader)
                        continue
                    if any(other is not reader and other.node == node and
                           (other.close_requested.is_set() or not other.is_alive())
                           for other in readers.values()):
                        continue  # Esperar al cierre del antiguo COM durante una reconexion.
                    bind(reader, node, readers, files, config, ports, duplicated, source)
                    if args.save:
                        save_config(args.config, config)
                        saved_config = json.dumps(config, sort_keys=True)
                if prompt.reader and (prompt.reader not in unresolved or prompt.reader.close_requested.is_set()):
                    prompt.cancel()
                if not prompt.reader and unresolved:
                    prompt.begin(unresolved[0], readers, config, ports)
                if prompt.reader:
                    prompt.available = {node[-2:]: node for node in NODES
                                        if all(r.node != node for r in readers.values())}
                    choice = prompt.poll()
                    if choice is not None:
                        reader, node = choice
                        prompt.cancel()
                        if node is None:
                            reader.ignored = True
                        else:
                            bind(reader, node, readers, files, config, ports, duplicated)
                            if args.save:
                                save_config(args.config, config)
                                saved_config = json.dumps(config, sort_keys=True)
                controller.stop.wait(0.1)
        except KeyboardInterrupt:
            pass
        finally:
            controller.stop.set()
            for reader in readers.values():
                reader.join()
                if reader.error:
                    print(f"[USB] {reader.port.device}: desconexion, error de lectura o identidad contradictoria.")
                finish_reader(reader, campaign, diagnostic_bytes)
                if reader.lines_written:
                    written_nodes.add(reader.node)
            print("Captura cerrada.", flush=True)
    missing = set(NODES) - written_nodes
    if missing:
        print("[INCOMPLETA] Sin registros escritos de: " + ", ".join(sorted(missing)))
    return 1 if controller.failed else (2 if missing else 0)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--list", action="store_true", help="Enumerar USB sin abrir puertos ni crear logs.")
    parser.add_argument("--save", action="store_true", help="Guardar asociaciones sin numeros COM fijos.")
    parser.add_argument("--config", type=Path, default=DEFAULT_CONFIG, help="Configuracion JSON local.")
    parser.add_argument("--confirm-locations", action="store_true",
                        help="Confirmar que las placas siguen en las tomas USB guardadas; permite reutilizarlas sin serie.")
    parser.add_argument("--diagnostic-bytes", type=int, default=0,
                        help="Guardar localmente hasta N bytes de muestra hexadecimal por conexion (0..256).")
    parser.add_argument("--duration", type=float, help="Cerrar limpiamente tras N segundos (0 < N <= 3600).")
    parser.add_argument("--no-console-lines", action="store_true",
                        help="Guardar todas las lineas en archivos, mostrando solo estado y contadores en consola.")
    parser.add_argument("--probe-seconds", type=float, default=5,
                        help="Espera del ID del firmware antes de preguntar (predeterminado: 5).")
    args = parser.parse_args()
    if not 0 <= args.probe_seconds <= 60:
        parser.error("--probe-seconds debe estar entre 0 y 60.")
    if not 0 <= args.diagnostic_bytes <= 256:
        parser.error("--diagnostic-bytes debe estar entre 0 y 256.")
    if args.duration is not None and not 0 < args.duration <= 3600:
        parser.error("--duration debe ser mayor que 0 y menor o igual que 3600.")
    if args.list:
        ports = usb_ports()
        for port in ports:
            print(describe(port))
        if not ports:
            print("No hay puertos USB serie disponibles.")
        return 0
    return capture(args, load_config(args.config))


if __name__ == "__main__":
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        sys.exit(0)
    except ValueError as error:
        print(f"ERROR: {error}", file=sys.stderr)
        sys.exit(1)
    except OSError:
        print("ERROR: no se pudo leer/guardar la configuracion local o la campaña.", file=sys.stderr)
        sys.exit(1)
