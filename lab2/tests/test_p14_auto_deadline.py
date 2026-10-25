"""Regresiones: observacion cerca del deadline, sin USB/red/servicios."""
import importlib.util
from pathlib import Path
import shutil
import tempfile
import unittest
from types import SimpleNamespace
from unittest.mock import Mock

SOURCE = Path(__file__).resolve().parents[1] / 'scripts' / 'p14-auto.py'
STUB = 'class P14Error(Exception):\n    pass\n'


class DeadlineTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        folder = Path(cls.temp.name)
        shutil.copyfile(SOURCE, folder / 'p14-auto.py')
        (folder / 'p14-evidence.py').write_text(STUB, encoding='utf-8')
        spec = importlib.util.spec_from_file_location('p14_auto_deadline', folder / 'p14-auto.py')
        cls.mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.mod)

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def make_trial(self):
        return self.mod.Trial(SimpleNamespace(recovery_timeout=180), {'scenario': 'C'}, Path(self.temp.name))

    def test_no_new_state_query_in_final_thirty_seconds(self):
        t = [0.0]
        def clock(): return t[0]
        def sleep(seconds): t[0] += seconds
        trial = self.make_trial()
        def recovery():
            t[0] = 153.0
            return False
        trial.recovery = Mock(side_effect=recovery)
        self.assertFalse(trial.observe_recovery(clock=clock, sleep=sleep))
        self.assertEqual(trial.recovery.call_count, 1)
        self.assertEqual(t[0], 180.0)
        self.assertIsNone(trial.deadline)

    def test_state_timeout_ending_window_is_not_false_abort(self):
        t = [0.0]
        def clock(): return t[0]
        def sleep(seconds): t[0] += seconds
        trial = self.make_trial()
        def recovery():
            t[0] = 156.0
            raise self.mod.ControlTimeout('Control State sin confirmacion; timeout del controlador.')
        trial.recovery = Mock(side_effect=recovery)
        self.assertFalse(trial.observe_recovery(clock=clock, sleep=sleep))
        self.assertEqual(t[0], 180.0)
        self.assertIsNone(trial.deadline)

    def test_state_timeout_early_is_still_fatal(self):
        t = [0.0]
        trial = self.make_trial()
        def recovery():
            t[0] = 40.0
            raise self.mod.ControlTimeout('Control State sin confirmacion; timeout del controlador.')
        trial.recovery = Mock(side_effect=recovery)
        with self.assertRaisesRegex(self.mod.AutoError, 'Control State'):
            trial.observe_recovery(clock=lambda: t[0], sleep=lambda _: None)
        self.assertIsNone(trial.deadline)

    def test_identity_failure_near_deadline_is_still_fatal(self):
        t = [0.0]
        trial = self.make_trial()
        def recovery():
            t[0] = 179.0
            raise self.mod.AutoError('Cambio de identidad o caida de componente no afectado')
        trial.recovery = Mock(side_effect=recovery)
        with self.assertRaisesRegex(self.mod.AutoError, 'Cambio de identidad'):
            trial.observe_recovery(clock=lambda: t[0], sleep=lambda _: None)
        self.assertIsNone(trial.deadline)

    def test_invalid_state_response_near_deadline_is_not_treated_as_timeout(self):
        t = [0.0]
        trial = self.make_trial()
        def recovery():
            t[0] = 179.0
            raise self.mod.AutoError('Control State sin confirmacion; JSON invalido.')
        trial.recovery = Mock(side_effect=recovery)
        with self.assertRaisesRegex(self.mod.AutoError, 'JSON invalido'):
            trial.observe_recovery(clock=lambda: t[0], sleep=lambda _: None)

if __name__ == '__main__':
    unittest.main()
