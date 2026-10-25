"""Regresiones del cierre P14 tras observacion sin PASS provisional.

No abre USB, no accede a red, no detiene procesos ni modifica servicios.
"""
import importlib.util
import json
from pathlib import Path
import shutil
import tempfile
import unittest
from unittest import mock
from types import SimpleNamespace

SOURCE = Path(__file__).resolve().parents[1] / 'scripts' / 'p14-auto.py'
STUB = '''
import json
from pathlib import Path
class P14Error(Exception): pass
def utc(): return "2026-10-25T00:00:00Z"
def load_json(path): return json.loads(Path(path).read_text(encoding="utf-8"))
def write_json(path, data, **kwargs): Path(path).write_text(json.dumps(data), encoding="utf-8")
def mark(*args, **kwargs): pass
def finish(run, abort=False): return 2 if abort else 0
'''


class RecoveryObservationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        folder = Path(cls.temp.name)
        shutil.copyfile(SOURCE, folder / 'p14-auto.py')
        (folder / 'p14-evidence.py').write_text(STUB, encoding='utf-8')
        spec = importlib.util.spec_from_file_location('p14_auto_observation_under_test', folder / 'p14-auto.py')
        cls.mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.mod)

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def make_trial(self, seconds=6):
        return self.mod.Trial(SimpleNamespace(recovery_timeout=seconds), {'scenario': 'C'}, Path(self.temp.name))

    def test_provisional_pass_stops_early(self):
        trial = self.make_trial()
        trial.recovery = mock.Mock(side_effect=[False, True])
        now = [0]
        def clock():
            now[0] += 0.1
            return now[0]
        sleep = mock.Mock()
        self.assertTrue(trial.observe_recovery(clock=clock, sleep=sleep))
        self.assertEqual(trial.recovery.call_count, 2)
        self.assertIsNone(trial.deadline)

    def test_provisional_incomplete_finishes_at_limit_without_throwing(self):
        trial = self.make_trial(seconds=3)
        trial.recovery = mock.Mock(return_value=False)
        t = [0.0]
        def clock(): return t[0]
        def sleep(seconds): t[0] += seconds
        self.assertFalse(trial.observe_recovery(clock=clock, sleep=sleep))
        self.assertGreaterEqual(trial.recovery.call_count, 1)
        self.assertEqual(t[0], 3.0)
        self.assertIsNone(trial.deadline)

    def test_real_recovery_error_is_not_hidden(self):
        trial = self.make_trial()
        trial.recovery = mock.Mock(side_effect=self.mod.AutoError('SQL no disponible'))
        with self.assertRaisesRegex(self.mod.AutoError, 'SQL no disponible'):
            trial.observe_recovery(clock=lambda: 0, sleep=lambda _: None)
        self.assertIsNone(trial.deadline)

    def test_execute_without_provisional_pass_calls_final_analysis_normally(self):
        with tempfile.TemporaryDirectory() as d:
            run = Path(d)
            (run / 'resources.json').write_text('{"restored": true, "armed": false}', encoding='utf-8')
            (run / 'metadata.json').write_text('{"marks":[]}', encoding='utf-8')
            trial = self.mod.Trial(SimpleNamespace(baseline_timeout=1, down_timeout=1), {'scenario': 'C', 'name': 'test'}, run)
            trial.ready = mock.Mock(return_value=True)
            trial.state = mock.Mock(return_value={'status': 'OK'})
            trial.down = mock.Mock(return_value={'status': 'OK'})
            trial.restore = mock.Mock(return_value={'status': 'OK'})
            trial.observe_recovery = mock.Mock(return_value=False)
            with mock.patch.object(self.mod, 'action', return_value={'status': 'OK'}), \
                 mock.patch.object(self.mod.time, 'monotonic', side_effect=[0, 0, 0, 61]), \
                 mock.patch.object(self.mod.p14, 'finish', return_value=0) as final:
                self.assertEqual(trial.execute(), 0)
            final.assert_called_once_with(run, abort=False)
            trial.observe_recovery.assert_called_once_with()


if __name__ == '__main__':
    unittest.main()
