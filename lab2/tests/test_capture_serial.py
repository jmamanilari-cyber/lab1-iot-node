"""Pruebas simuladas: no abren USB real ni escriben evidencias del laboratorio."""

from collections import deque
from contextlib import redirect_stdout
from datetime import datetime, timezone
import importlib.util
import io
from pathlib import Path
import tempfile
import threading
from types import SimpleNamespace
import unittest
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "capture-serial.py"
spec = importlib.util.spec_from_file_location("capture_serial", SCRIPT)
capture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(capture)


def port(com="COM5", number="unique", location="1-1", vid=0x10C4, pid=0xEA60):
    return SimpleNamespace(device=com, serial_number=number, location=location,
                           vid=vid, pid=pid, hwid="USB TEST", description="ADAPTADOR DE PRUEBA")


def config():
    return {"version": 1, "serials": {}, "locations": {}, "ambiguous_serials": []}


class FakeSerial:
    def __init__(self, streams, instances, disconnect=True):
        self.streams = streams
        self.disconnect = disconnect
        self.closed = False
        self.writes = []
        instances.append(self)

    def open(self):
        assert self.dtr is False and self.rts is False
        assert self.timeout == 0.2
        self.chunks = deque(self.streams[self.port])

    @property
    def in_waiting(self):
        return len(self.chunks[0]) if self.chunks else 0

    def read(self, size):
        assert 1 <= size <= capture.MAX_READ
        if not self.chunks:
            if self.disconnect:
                raise capture.serial.SerialException("desconexion simulada")
            return b""
        raw = self.chunks.popleft()
        if len(raw) > size:
            self.chunks.appendleft(raw[size:])
        return raw[:size]

    def close(self):
        self.closed = True

    def write(self, raw):
        self.writes.append(raw)
        raise AssertionError("La captura no debe transmitir datos")


class SerialCaptureTests(unittest.TestCase):
    def setUp(self):
        self.console = io.StringIO()
        self.redirect = redirect_stdout(self.console)
        self.redirect.__enter__()
        self.addCleanup(self.redirect.__exit__, None, None, None)

    def test_fragmentation_timeouts_and_real_repetitions(self):
        buffer = capture.LineBuffer()
        self.assertEqual(buffer.feed(b"uno\r"), [])
        self.assertEqual(buffer.feed(b"\ndos\nres"), [(b"uno\r", False), (b"dos", False)])
        for _ in range(50000):
            self.assertEqual(buffer.feed(b""), [])
        self.assertEqual(buffer.feed(b"to\nigual\nigual\n"),
                         [(b"resto", False), (b"igual", False), (b"igual", False)])
        self.assertEqual(buffer.finish(), [])

    def test_oversize_and_partial_flushed_only_once(self):
        buffer = capture.LineBuffer()
        self.assertEqual(buffer.feed(b"x" * (capture.MAX_PARTIAL + 1)), [])
        self.assertIn(b"DESCARTADA", buffer.feed(b"fin\ncorrecta\n")[0][0])
        buffer.feed(b"incompleta")
        self.assertEqual(buffer.finish(), [(b"incompleta", True)])
        self.assertEqual(buffer.finish(), [])

    def test_usb_enumeration_does_not_open_serial(self):
        usb = port("COM12")
        internal = port("COM1", vid=None, pid=None)
        with patch.object(capture.list_ports, "comports", return_value=[internal, usb]), \
                patch.object(capture.serial, "Serial") as serial:
            self.assertEqual(capture.usb_ports(), [usb])
            serial.assert_not_called()

    def test_serial_identity_survives_com_renumbering_and_save(self):
        old, new = port("COM5"), port("COM12")
        data = config()
        data["serials"][capture.usb_key(old)] = "esp32-02"
        with tempfile.TemporaryDirectory(prefix="serial-unit-") as directory:
            path = Path(directory) / "capture.local.json"
            capture.save_config(path, data)
            loaded = capture.load_config(path)
            self.assertNotIn("COM5", path.read_text())
            self.assertEqual(capture.serial_candidate(new, [new], loaded, set()), "esp32-02")

    def test_duplicate_serial_is_not_identity_even_after_unplug_or_restart(self):
        first, second = port("COM5", "same"), port("COM12", "same", "1-2")
        data = config()
        data["serials"][capture.usb_key(first)] = "esp32-01"
        duplicates = set()
        self.assertIsNone(capture.serial_candidate(first, [first, second], data, duplicates))
        self.assertIsNone(capture.serial_candidate(first, [first], data, duplicates))
        self.assertIsNone(capture.serial_candidate(first, [first], data, set()))
        self.assertFalse(data["serials"])

    def test_location_hint_requires_manual_confirmation(self):
        info = port("COM11", None, "2-1", 0x1A86, 0x7523)
        data = config()
        data["locations"][capture.location_key(info)] = "esp32-03"
        self.assertIsNone(capture.serial_candidate(info, [info], data, set()))
        reader = capture.PortReader(capture.Capture(), info)
        self.addCleanup(reader.frames.close)
        prompt = capture.AssociationPrompt()
        prompt.begin(reader, {info.device: reader}, data, [info])
        with patch.object(capture, "console_line", return_value="02") as input_line:
            self.assertEqual(prompt.poll(), (reader, "esp32-02"))
        input_line.assert_called_once()

    def test_firmware_identity_and_redaction_before_flush(self):
        controller = capture.Capture()
        reader = capture.PortReader(controller, port())
        self.addCleanup(reader.frames.close)
        stamp = datetime(2026, 1, 1, tzinfo=timezone.utc)
        reader.raw.observe(b"E1 esp32-02: prueba unitaria\ntoken=VALOR_DE_PRUEBA\n", stamp)
        reader.accept(b"E1 esp32-02: prueba unitaria", False, stamp)
        reader.accept(b"token=VALOR_DE_PRUEBA", False, stamp)
        output = io.StringIO()
        data = config()
        capture.bind(reader, "esp32-02", {"COM5": reader}, {"esp32-02": output},
                     data, [reader.port], set(), "firmware")
        reader.flush_frames()
        reader.flush_frames()
        lines = output.getvalue().splitlines()
        self.assertEqual(len(lines), 2)
        self.assertTrue(lines[0].startswith("2026-01-01T00:00:00.000Z [esp32-02] [COM5]"))
        self.assertNotIn("VALOR_DE_PRUEBA", output.getvalue())
        self.assertIn("REDACTADO", output.getvalue())

    def test_conflicting_or_duplicate_node_cannot_be_bound(self):
        controller = capture.Capture()
        first = capture.PortReader(controller, port())
        self.addCleanup(first.frames.close)
        first.node = "esp32-01"
        second = capture.PortReader(controller, port("COM12", "other", "1-2"))
        self.addCleanup(second.frames.close)
        with self.assertRaises(ValueError):
            capture.bind(second, "esp32-01", {"COM5": first, "COM12": second}, {},
                         config(), [first.port, second.port], set())
        second.evidence = {"esp32-02"}
        with self.assertRaises(ValueError):
            capture.bind(second, "esp32-03", {"COM12": second}, {}, config(), [second.port], set())

    def test_firmware_contradiction_stops_before_wrong_line_is_written(self):
        controller = capture.Capture()
        reader = capture.PortReader(controller, port())
        self.addCleanup(reader.frames.close)
        reader.node, reader.file = "esp32-01", io.StringIO()
        reader.raw.observe(b"E1 esp32-02: prueba\n", datetime.now(timezone.utc))
        reader.accept(b"E1 esp32-02: prueba", False, datetime.now(timezone.utc))
        reader.flush_frames()
        self.assertTrue(controller.stop.is_set())
        self.assertTrue(controller.failed)
        self.assertEqual(reader.file.getvalue(), "")

    def test_simultaneous_readers_do_not_replay_and_close_handles(self):
        controller = capture.Capture()
        instances = []
        streams = {"COM5": [b"repetida\n", b"", b"", b"repetida\n", b"par", b"cial"],
                   "COM11": [b"otra\n"], "COM12": [b"tercera\r", b"\n"]}
        readers = [capture.PortReader(controller, port("COM5")),
                   capture.PortReader(controller, port("COM11", "other")),
                   capture.PortReader(controller, port("COM12", None))]
        for reader, node in zip(readers, ("esp32-02", "esp32-03", "esp32-01")):
            reader.node, reader.file = node, io.StringIO()
        with patch.object(capture.serial, "Serial", side_effect=lambda: FakeSerial(streams, instances)):
            for reader in readers:
                reader.start()
            for reader in readers:
                reader.join(timeout=2)
                self.assertFalse(reader.is_alive())
        self.assertEqual(len(readers[0].file.getvalue().splitlines()), 3)
        self.assertEqual(readers[0].file.getvalue().count("repetida"), 2)
        self.assertIn("[LINEA PARCIAL] parcial", readers[0].file.getvalue())
        self.assertEqual(len(readers[1].file.getvalue().splitlines()), 1)
        self.assertEqual(len(readers[2].file.getvalue().splitlines()), 1)
        self.assertTrue(all(reader.consistent() for reader in readers))
        for reader in readers:
            self.assertEqual(reader.bytes_received, sum(map(len, streams[reader.port.device])))
        self.assertTrue(all(instance.closed and not instance.writes for instance in instances))

    def test_empty_reads_are_not_lines_and_real_empty_lines_are_preserved(self):
        controller = capture.Capture()
        reader = capture.PortReader(controller, port())
        reader.node, reader.file = "esp32-01", io.StringIO()
        chunks = [b"", b"\n", b"", b"\r", b"\n\nA", b"\n", b"", b"tail"]
        instances = []
        with patch.object(capture.serial, "Serial", side_effect=lambda: FakeSerial(
                {"COM5": chunks}, instances)):
            reader.run()
        messages = [row.split("] ", 2)[2] for row in reader.file.getvalue().splitlines()]
        self.assertEqual(messages, ["", "", "", "A", "[LINEA PARCIAL] tail"])
        self.assertEqual(reader.bytes_received, len(b"".join(chunks)))
        self.assertEqual(reader.raw.timeouts, 3)
        self.assertEqual(reader.complete_lines, 4)
        self.assertEqual(reader.partial_lines, 1)
        self.assertEqual(reader.empty_lines, 3)
        self.assertEqual(reader.lines_received, reader.lines_written)
        self.assertTrue(reader.consistent())

    def test_10000_timeouts_create_no_records(self):
        controller = capture.Capture()
        reader = capture.PortReader(controller, port())
        reader.node, reader.file = "esp32-01", io.StringIO()
        instances = []
        with patch.object(controller.stop, "wait", return_value=False), \
                patch.object(capture.serial, "Serial", side_effect=lambda: FakeSerial(
                    {"COM5": [b""] * 10000}, instances)):
            reader.run()
        self.assertEqual(reader.raw.timeouts, 10000)
        self.assertEqual(reader.bytes_received, 0)
        self.assertEqual(reader.lines_received, 0)
        self.assertEqual(reader.lines_written, 0)
        self.assertEqual(reader.file.getvalue(), "")
        self.assertTrue(reader.consistent())

    def test_fictitious_empty_record_without_rx_lf_is_blocked_before_writing(self):
        controller = capture.Capture()
        reader = capture.PortReader(controller, port())
        reader.node, reader.file = "esp32-01", io.StringIO()
        instances = []
        # Inyectar un fallo de delimitacion: no existe un LF en los bytes de entrada.
        with patch.object(capture.LineBuffer, "feed", return_value=[(b"", False)]), \
                patch.object(capture.serial, "Serial", side_effect=lambda: FakeSerial(
                    {"COM5": [b"x"]}, instances)):
            reader.run()
        self.assertTrue(controller.failed)
        self.assertEqual(reader.bytes_received, 1)
        self.assertEqual(reader.raw.histogram[10], 0)
        self.assertEqual(reader.lines_received, 0)
        self.assertEqual(reader.lines_written, 0)
        self.assertEqual(reader.file.getvalue(), "")

    def test_win32_open_error_codes_are_preserved_without_error_text(self):
        reader = capture.PortReader(capture.Capture(), port("COM12"))
        self.addCleanup(reader.frames.close)
        # Formato usado por serialwin32.Serial.open(); el detalle no debe publicarse.
        error = capture.serial.SerialException(
            "could not open port 'COM12': PermissionError(13, 'DETALLE PRIVADO', None, 5)")
        reader.record_error(error, "open")
        self.assertEqual(reader.errors, [{"stage": "open", "type": "SerialException",
                                          "errno": 13, "winerror": 5}])
        self.assertNotIn("DETALLE PRIVADO", reader.summary())

    @unittest.skipUnless(capture.os.name == "nt", "Entrada de consola Windows")
    def test_windows_console_poll_does_not_wait_for_input(self):
        import msvcrt
        buffer = []
        with patch.object(capture.sys.stdin, "isatty", return_value=True), \
                patch.object(msvcrt, "kbhit", return_value=False):
            self.assertIsNone(capture.console_line(buffer))
        with patch.object(capture.sys.stdin, "isatty", return_value=True), \
                patch.object(msvcrt, "kbhit", return_value=True), \
                patch.object(msvcrt, "getwch", side_effect=["0", "1", "\b", "2", "\r"]):
            self.assertEqual(capture.console_line(buffer), "02")
        with patch.object(capture.sys.stdin, "isatty", return_value=True), \
                patch.object(msvcrt, "kbhit", return_value=True), \
                patch.object(msvcrt, "getwch", return_value="\x03"):
            with self.assertRaises(KeyboardInterrupt):
                capture.console_line([])

    def test_real_lf_flood_is_accounted_without_suppression(self):
        controller = capture.Capture()
        controller.echo = False
        reader = capture.PortReader(controller, port("COM12"), diagnostic_bytes=64)
        reader.node, reader.file = "esp32-01", io.StringIO()
        # Datos de prueba: se entregan LF reales, no se fabrican a partir de timeouts.
        source = b"\n" * 40411 + b"HTTP=201 seq=1043\r\n" * 10
        instances = []
        with patch.object(capture.serial, "Serial", side_effect=lambda: FakeSerial(
                {"COM12": [source]}, instances)):
            reader.run()
        self.assertEqual(reader.bytes_received, len(source))
        self.assertEqual(reader.complete_lines, 40421)
        self.assertEqual(reader.lines_written, 40421)
        self.assertEqual(reader.empty_lines, 40411)
        self.assertEqual(reader.raw.histogram[10], 40421)
        self.assertEqual(reader.raw.histogram[13], 10)
        self.assertTrue(reader.consistent())
        self.assertNotIn("HTTP=201", self.console.getvalue())
        report = reader.raw.report()
        self.assertLessEqual(len(bytes.fromhex(report["prefix_hex"])) +
                             len(bytes.fromhex(report["lf_burst_hex"])), 64)
        self.assertEqual(set(bytes.fromhex(report["lf_burst_hex"])), {10})
        # Una lectura puede producir muchas lineas con el MISMO timestamp RX.
        self.assertEqual(report["max_lf_in_one_read"], capture.MAX_READ)
        with tempfile.TemporaryDirectory(prefix="serial-unit-") as directory:
            reader.save_diagnostic(Path(directory))
            diagnostic = next(Path(directory).glob("*.diagnostic.log"))
            loaded = capture.json.loads(diagnostic.read_text(encoding="utf-8"))
            self.assertEqual(loaded["bytes"], len(source))
            self.assertTrue(loaded["consistent"])
            self.assertNotIn("0a 0a", self.console.getvalue())

    def test_location_binding_without_serial_survives_json_and_com_change(self):
        original = port("COM9", None, "2-1", 0x1A86, 0x7523)
        renamed = port("COM11", None, "2-1", 0x1A86, 0x7523)
        data = config()
        data["locations"][capture.location_key(original)] = "esp32-03"
        with tempfile.TemporaryDirectory(prefix="serial-unit-") as directory:
            path = Path(directory) / "map.local.json"
            capture.save_config(path, data)
            loaded = capture.load_config(path)
            self.assertNotIn("COM9", path.read_text())
        self.assertEqual(capture.location_candidate(renamed, [renamed], loaded), "esp32-03")
        duplicate_location = port("COM14", None, "2-1", 0x1A86, 0x7523)
        self.assertIsNone(capture.location_candidate(renamed, [renamed, duplicate_location], loaded))
        moved = port("COM11", None, "2-2", 0x1A86, 0x7523)
        self.assertIsNone(capture.location_candidate(moved, [moved], loaded))

    def test_manual_prompt_is_nonblocking_and_enter_confirms_saved_location(self):
        info = port("COM11", None)
        reader = capture.PortReader(capture.Capture(), info)
        self.addCleanup(reader.frames.close)
        data = config()
        data["locations"][capture.location_key(info)] = "esp32-03"
        prompt = capture.AssociationPrompt()
        prompt.begin(reader, {info.device: reader}, data, [info])
        with patch.object(capture, "console_line", side_effect=[None, None, ""]):
            self.assertIsNone(prompt.poll())
            self.assertIsNone(prompt.poll())
            self.assertEqual(prompt.poll(), (reader, "esp32-03"))

    def test_unanswered_prompt_does_not_block_other_ports_association(self):
        infos = [port("COM11", None), port("COM5", "known", "1-2")]
        data = config()
        data["serials"][capture.usb_key(infos[1])] = "esp32-02"
        instances, readers = [], []
        real_reader = capture.PortReader

        def make_reader(*args):
            reader = real_reader(*args)
            readers.append(reader)
            return reader

        with tempfile.TemporaryDirectory(prefix="serial-unit-") as directory:
            args = SimpleNamespace(config=Path(directory) / "map.local.json", save=False,
                                   probe_seconds=0, duration=0.3, no_console_lines=True)
            fake_script = str(Path(directory) / "lab/scripts/capture.py")
            with patch.object(capture, "__file__", fake_script), \
                    patch.object(capture, "usb_ports", return_value=infos), \
                    patch.object(capture, "console_line", return_value=None) as poll, \
                    patch.object(capture, "PortReader", side_effect=make_reader), \
                    patch.object(capture.serial, "Serial", side_effect=lambda: FakeSerial(
                        {"COM11": [b"sin identidad\n"], "COM5": [b"HTTP=201 seq=1\n"]},
                        instances, disconnect=False)):
                self.assertEqual(capture.capture(args, data), 2)
                self.assertTrue(poll.called)
            known = next(reader for reader in readers if reader.port.device == "COM5")
            unknown = next(reader for reader in readers if reader.port.device == "COM11")
            self.assertEqual(known.node, "esp32-02")
            self.assertEqual(known.lines_written, 1)
            self.assertEqual(unknown.lines_written, 0)
            self.assertEqual(len(unknown.frames), 1)
            self.assertTrue(all(reader.consistent() for reader in readers))
            self.assertTrue(all(instance.closed for instance in instances))

    def test_three_configured_ports_capture_with_confirmed_locations(self):
        infos = [port("COM12", None, "1-1"), port("COM5", "known", "1-2"),
                 port("COM11", None, "1-3", 0x1A86, 0x7523)]
        data = config()
        expected = {"COM12": "esp32-01", "COM5": "esp32-02", "COM11": "esp32-03"}
        for info in infos:
            data["locations"][capture.location_key(info)] = expected[info.device]
        data["serials"][capture.usb_key(infos[1])] = "esp32-02"
        instances, readers = [], []
        real_reader = capture.PortReader

        def make_reader(*args):
            reader = real_reader(*args)
            readers.append(reader)
            return reader

        with tempfile.TemporaryDirectory(prefix="serial-unit-") as directory:
            path = Path(directory) / "map.local.json"
            capture.save_config(path, data)
            args = SimpleNamespace(config=path, save=True, probe_seconds=5, duration=0.3,
                                   confirm_locations=True, no_console_lines=True, diagnostic_bytes=16)
            fake_script = str(Path(directory) / "lab/scripts/capture.py")
            with patch.object(capture, "__file__", fake_script), \
                    patch.object(capture, "usb_ports", return_value=infos), \
                    patch.object(capture, "console_line", side_effect=AssertionError("No debe bloquear")), \
                    patch.object(capture, "PortReader", side_effect=make_reader), \
                    patch.object(capture.serial, "Serial", side_effect=lambda: FakeSerial(
                        {info.device: [b"dato real de prueba\n"] for info in infos},
                        instances, disconnect=False)):
                self.assertEqual(capture.capture(args, capture.load_config(path)), 0)
            self.assertEqual({r.port.device: r.node for r in readers}, expected)
            self.assertTrue(all(r.lines_written == 1 and r.consistent() for r in readers))
            self.assertTrue(all(instance.closed for instance in instances))
            self.assertEqual(len(list(Path(directory).glob("lab/logs/serial/*/*.diagnostic.log"))), 3)

    def test_burst_of_real_repetitions_is_not_artificially_deduplicated(self):
        controller = capture.Capture()
        reader = capture.PortReader(controller, port())
        reader.node, reader.file = "esp32-01", io.StringIO()
        instances = []
        streams = {"COM5": [b"x\n" * 1000]}
        with patch.object(capture.serial, "Serial", side_effect=lambda: FakeSerial(streams, instances)):
            reader.run()
        self.assertEqual(len(reader.file.getvalue().splitlines()), 1000)
        self.assertEqual(reader.lines_received, 1000)
        self.assertEqual(reader.lines_written, 1000)
        self.assertEqual(reader.bytes_received, 2000)

    def test_association_handoff_keeps_18472_lines_without_replay_or_loss(self):
        """Regresion: 18472 - 256 = 18216 descartes con la cola anterior."""
        controller = capture.Capture()
        reader = capture.PortReader(controller, port("COM12", None))
        self.addCleanup(reader.frames.close)
        output = io.StringIO()
        before = b"HTTP=201 seq=963\n" * 18472 + b"fragmento"
        after = [b"", b" final\nHTTP=201 seq=963\n", b"resto"]
        reached = threading.Event()
        resume = threading.Event()
        instances = []

        class PausedSerial(FakeSerial):
            def __init__(self):
                super().__init__({"COM12": [before] + after}, instances)
                self.total = 0
                self.paused = False

            def read(self, size):
                if self.total == len(before) and not self.paused:
                    self.paused = True
                    reached.set()
                    if not resume.wait(5):
                        raise AssertionError("La asociacion de prueba no termino")
                raw = super().read(size)
                self.total += len(raw)
                return raw

        with patch.object(capture.serial, "Serial", side_effect=PausedSerial):
            reader.start()
            try:
                self.assertTrue(reached.wait(5))
                self.assertEqual(len(reader.frames), 18472)
                self.assertEqual(output.getvalue(), "")
                capture.bind(reader, "esp32-01", {"COM12": reader}, {"esp32-01": output},
                             config(), [reader.port], set())
                # Incluye varios flush mientras el lector conserva un fragmento sin LF.
                reader.flush_frames()
                reader.flush_frames()
                resume.set()
                reader.join(timeout=5)
                self.assertFalse(reader.is_alive())
                reader.flush_frames()
            finally:
                resume.set()
                controller.stop.set()
                reader.join(timeout=5)
        messages = [row.split("] ", 2)[2] for row in output.getvalue().splitlines()]
        self.assertEqual(messages, ["HTTP=201 seq=963"] * 18472 +
                         ["fragmento final", "HTTP=201 seq=963", "[LINEA PARCIAL] resto"])
        self.assertEqual(reader.bytes_received, len(before) + sum(map(len, after)))
        self.assertEqual(reader.lines_received, 18475)
        self.assertEqual(reader.lines_written, reader.lines_received)
        self.assertFalse(reader.frames)
        self.assertFalse(controller.failed)
        self.assertTrue(instances[0].closed)

    def test_pending_fifo_does_not_replay_old_storage_after_drain(self):
        """Reutilizar almacenamiento para mensajes mas cortos no deja una cola antigua."""
        with patch.object(capture, "QUEUE_MEMORY_BYTES", 128):
            queue = capture.PendingFrames()
        self.addCleanup(queue.close)
        first = datetime(2026, 1, 1, tzinfo=timezone.utc)
        second = datetime(2026, 1, 2, tzinfo=timezone.utc)
        queue.append("larga" * 100, first)
        queue.append("igual", first)
        self.assertEqual(queue.popleft(), ("larga" * 100, first))
        # Intercalar escritura y lectura: no pisar entradas aun pendientes.
        queue.append("igual", second)
        self.assertEqual(queue.popleft(), ("igual", first))
        self.assertEqual(queue.popleft(), ("igual", second))
        self.assertFalse(queue)
        queue.append("corta", second)
        self.assertEqual(queue.popleft(), ("corta", second))
        self.assertFalse(queue)
        with self.assertRaises(IndexError):
            queue.popleft()

    def test_pending_storage_failure_stops_instead_of_retrying_usb(self):
        controller = capture.Capture()
        reader = capture.PortReader(controller, port())
        instances = []
        with patch.object(reader.frames.file, "write", side_effect=OSError("disco lleno simulado")), \
                patch.object(capture.serial, "Serial", side_effect=lambda: FakeSerial(
                    {"COM5": [b"prueba\n"]}, instances)):
            reader.run()
        self.assertTrue(controller.failed)
        self.assertTrue(controller.stop.is_set())
        self.assertEqual(reader.lines_written, 0)
        self.assertEqual(len(instances), 1)
        self.assertTrue(instances[0].closed)

    def test_ctrl_c_joins_readers_without_experimental_logs(self):
        instances = []
        readers = []
        real_reader = capture.PortReader

        def make_reader(*args):
            reader = real_reader(*args)
            readers.append(reader)
            return reader

        with tempfile.TemporaryDirectory(prefix="serial-unit-") as directory:
            args = SimpleNamespace(config=Path(directory) / "map.local.json", save=False, probe_seconds=0)
            # Toda la campaña simulada queda en el temporal, nunca en lab2/logs.
            fake_script = str(Path(directory) / "lab" / "scripts" / "capture.py")
            with patch.object(capture, "__file__", fake_script), \
                    patch.object(capture, "usb_ports", return_value=[port()]), \
                    patch.object(capture, "PortReader", side_effect=make_reader), \
                    patch.object(capture, "console_line", side_effect=KeyboardInterrupt), \
                    patch.object(capture.serial, "Serial", side_effect=lambda: FakeSerial(
                        {"COM5": []}, instances, disconnect=False)):
                self.assertEqual(capture.capture(args, config()), 2)
            self.assertTrue(readers)
            self.assertTrue(all(not reader.is_alive() for reader in readers))
            self.assertTrue(all(instance.closed for instance in instances))

    def test_manager_reconnects_same_usb_identity_on_different_com(self):
        instances = []
        observed = []
        controllers = []
        scans = []
        real_emit = capture.Capture.emit
        real_controller = capture.Capture

        def controller_factory():
            controller = real_controller()
            controllers.append(controller)
            return controller

        def enumerate_ports():
            scans.append(True)
            return [port("COM5" if len(scans) == 1 else "COM12")]

        def emit(controller, file, device, com, message, received=None):
            real_emit(controller, file, device, com, message, received)
            observed.append((device, com, message))
            if com == "COM12" and message == "segunda":
                controller.stop.set()

        data = config()
        data["serials"][capture.usb_key(port())] = "esp32-02"
        with tempfile.TemporaryDirectory(prefix="serial-unit-") as directory:
            args = SimpleNamespace(config=Path(directory) / "map.local.json", save=False, probe_seconds=0)
            fake_script = str(Path(directory) / "lab" / "scripts" / "capture.py")
            with patch.object(capture, "__file__", fake_script), \
                    patch.object(capture, "usb_ports", side_effect=enumerate_ports), \
                    patch.object(capture.Capture, "emit", new=emit), \
                    patch.object(capture, "Capture", side_effect=controller_factory), \
                    patch.object(capture, "console_line", side_effect=AssertionError("No debe pedir COM fijo")), \
                    patch.object(capture.serial, "Serial", side_effect=lambda: FakeSerial(
                        {"COM5": [b"primera\n"], "COM12": [b"segunda\n"]}, instances, disconnect=False)):
                watchdog = threading.Timer(5, lambda: controllers and controllers[0].stop.set())
                watchdog.start()
                try:
                    self.assertEqual(capture.capture(args, data), 2)
                finally:
                    watchdog.cancel()
                    watchdog.join()
            self.assertEqual(observed, [("esp32-02", "COM5", "primera"),
                                        ("esp32-02", "COM12", "segunda")])
            self.assertTrue(all(instance.closed for instance in instances))


if __name__ == "__main__":
    unittest.main()
