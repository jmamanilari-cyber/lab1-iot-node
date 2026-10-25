"""Regresion de control PowerShell: sin stdout PIPE ni espera por descendientes.

No accede a ESP32, servicios, firewall ni credenciales.
"""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time
import unittest
from unittest import mock


SOURCE = Path(__file__).resolve().parents[1] / "scripts" / "p14-auto.py"


def load_isolated(tempdir):
    folder = Path(tempdir)
    shutil.copyfile(SOURCE, folder / "p14-auto.py")
    (folder / "p14-evidence.py").write_text(
        "class P14Error(ValueError):\n    pass\n", encoding="utf-8"
    )
    spec = importlib.util.spec_from_file_location("p14_auto_regression", folder / "p14-auto.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class ControlTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.module = load_isolated(self.tmp.name)

    def test_stdout_uses_temporary_file_not_pipe(self):
        seen = {}
        def run(cmd, **kwargs):
            seen["kwargs"] = kwargs
            kwargs["stdout"].write(b'{"status":"OK","services":{"d2":true}}')
            return subprocess.CompletedProcess(cmd, 0)
        with mock.patch.object(self.module.subprocess, "run", side_effect=run):
            result = self.module.control("State", scenario="C")
        self.assertTrue(result["services"]["d2"])
        self.assertNotEqual(seen["kwargs"]["stdout"], subprocess.PIPE)
        self.assertEqual(seen["kwargs"]["timeout"], 45)
        self.assertEqual(json.loads(seen["kwargs"]["input"])["scenario"], "C")

    def test_timeout_fails_closed(self):
        with mock.patch.object(self.module.subprocess, "run", side_effect=subprocess.TimeoutExpired("ps", 1)):
            with self.assertRaisesRegex(self.module.AutoError, "sin confirmacion"):
                self.module.control("Restore", timeout=1)

    def test_invalid_json_fails_closed(self):
        def run(cmd, **kwargs):
            kwargs["stdout"].write(b"non-json")
            return subprocess.CompletedProcess(cmd, 0)
        with mock.patch.object(self.module.subprocess, "run", side_effect=run):
            with self.assertRaisesRegex(self.module.AutoError, "sin confirmacion"):
                self.module.control("Restore")

    def test_powershell_rejection_propagates_without_false_ok(self):
        def run(cmd, **kwargs):
            kwargs["stdout"].write(b'{"status":"INCOMPLETO","reason":"sin permisos"}')
            return subprocess.CompletedProcess(cmd, 2)
        with mock.patch.object(self.module.subprocess, "run", side_effect=run):
            with self.assertRaisesRegex(self.module.AutoError, "sin permisos"):
                self.module.control("Restore")

    def test_oversize_response_fails_closed(self):
        def run(cmd, **kwargs):
            kwargs["stdout"].write(b"x" * (1024 * 1024 + 3))
            return subprocess.CompletedProcess(cmd, 0)
        with mock.patch.object(self.module.subprocess, "run", side_effect=run):
            with self.assertRaisesRegex(self.module.AutoError, "excesiva"):
                self.module.control("State")

    @unittest.skipIf(os.name == "nt", "Prueba con ejecutable simulado en POSIX; mocks cubren Windows")
    def test_descendant_holding_stdout_open_does_not_block(self):
        # El shell simulado termina inmediatamente, pero su hijo conserva stdout
        # durante varios segundos: con PIPE el cliente esperaria ese EOF.
        shell = Path(self.tmp.name) / "System32" / "WindowsPowerShell" / "v1.0" / "powershell.exe"
        shell.parent.mkdir(parents=True)
        shell.write_text(
            "#!" + sys.executable + "\n"
            "import subprocess, sys\n"
            "subprocess.Popen([sys.executable,'-c','import time; time.sleep(3)'], "
            "stdout=sys.stdout, stdin=subprocess.DEVNULL, stderr=subprocess.DEVNULL, close_fds=False)\n"
            "print('{\"status\":\"OK\",\"d2\":\"started\"}', flush=True)\n",
            encoding="utf-8",
        )
        shell.chmod(0o755)
        with mock.patch.dict(os.environ, {"SystemRoot": self.tmp.name}):
            start = time.monotonic()
            result = self.module.control("Restore", timeout=1.5)
            duration = time.monotonic() - start
        self.assertEqual(result["d2"], "started")
        self.assertLess(duration, 1.5, "Esperó al hijo persistente en lugar de al controlador")


if __name__ == "__main__":
    unittest.main()
