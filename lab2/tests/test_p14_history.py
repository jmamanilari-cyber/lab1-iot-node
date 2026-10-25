"""Reevaluacion en memoria de originales existentes. Sin PDO, USB ni escrituras historicas."""
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('p14_history', ROOT / 'scripts/p14-evidence.py')
p14 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p14)


class HistoricalP14Tests(unittest.TestCase):
    def load_run(self, name, analysis):
        run = ROOT / 'logs/evidence/P14' / name
        if not run.is_dir(): self.skipTest('Original local no disponible: ' + name)
        paths = [run/'metadata.json', run/'capture-status.json', run/'sql-ready.json',
                 run/'analyses'/analysis/'sql-after.json'] + list((run/'serial').glob('*/*.log'))
        hashes = {p: hashlib.sha256(p.read_bytes()).hexdigest() for p in paths}
        self.addCleanup(lambda: self.assertEqual(hashes, {p:hashlib.sha256(p.read_bytes()).hexdigest() for p in paths}))
        meta, status, before, after = [json.loads(p.read_text(encoding='utf-8')) for p in paths[:4]]
        events, problems = p14.read_events(run, meta['start_utc'], meta['end_utc'])
        self.assertFalse(problems)
        # SQL live queda prohibido: todos los resultados proceden de snapshots originales.
        with patch.object(p14, 'sql', side_effect=AssertionError('No consultar servicios')):
            result = p14.assess(meta, events, before, after, status)
        return meta, events, result

    def test_latest_recovery_is_real_but_serial_02_prevents_pass(self):
        meta, events, result = self.load_run('20261025T013642Z-C-8f2292ae45bf', '20261025T014131Z-5bab5626')
        self.assertEqual(result['status'], 'INCOMPLETO')
        self.assertFalse(result['failures'])
        self.assertFalse(meta['aborted'])
        node = result['nodes']['esp32-02']
        self.assertEqual(node['serial_range_gaps'], [2764,2768,2769,2770])
        self.assertEqual(node['sql_without_serial'], [2764,2768,2769,2770])
        self.assertEqual([e['line'] for e in node['serial_unreadable_lines']], [19,23,24,25])
        self.assertEqual([e['replacement_characters'] for e in node['serial_unreadable_lines']], [64]*4)
        self.assertFalse(node['sql_range_gaps'])
        self.assertEqual(result['nodes']['esp32-03']['status'], 'PASS')
        self.assertEqual(result['nodes']['esp32-03']['pending_recovered'], [3046])
        self.assertEqual(len(result['nodes']['esp32-03']['recovered_keys']), 7)
        up = next(e for e in events['esp32-03'] if e['kind']=='mqtt_connected')
        self.assertEqual(up['utc'], '2026-10-25T01:40:40.200000Z')
        marks={m['event']:p14.date(m['utc']) for m in meta['marks']}
        self.assertAlmostEqual((marks['restore-intent']-marks['down']).total_seconds(),63.482,places=3)
        self.assertAlmostEqual((p14.date(meta['end_utc'])-marks['restored']).total_seconds(),180.010681,places=6)
        run=ROOT/'logs/evidence/P14/20261025T013642Z-C-8f2292ae45bf'
        resources=p14.load_json(run/'resources.json')
        initial,restored=resources['plan']['before'],resources['restored_services']
        self.assertTrue(resources['restored'])
        self.assertFalse(restored['d2']['windows_service_owned'])
        self.assertNotEqual(initial['d2']['identity'],restored['d2']['identity'])
        protected=lambda s:sorted((r['port'],r['pid']) for r in s['sockets'] if r['port'] not in (1884,8883))
        self.assertEqual(protected(initial),protected(restored))
        actions=p14.load_json(run/'actions.json')
        self.assertEqual([a['action'] for a in actions],['Interrupt','Restore'])
        self.assertTrue(all(a['confirmed'] for a in actions))

    def test_previous_run_had_serial_corruption_in_addition_to_control_timeout(self):
        meta, events, result = self.load_run('20261025T012022Z-C-4262bf100ce4', '20261025T012616Z-e150e494')
        self.assertEqual(result['status'], 'INCOMPLETO')
        self.assertTrue(meta['aborted'])
        self.assertTrue(meta['automation_errors'])
        node=result['nodes']['esp32-02']
        self.assertEqual(node['serial_range_gaps'], [2665,2666,2667,2677,2679,2681,2682,2686])
        self.assertIn(2688,node['sql_without_serial'])  # ultima linea corrupta fuera del rango serie
        self.assertFalse(node['sql_range_gaps'])
        self.assertEqual(result['nodes']['esp32-03']['pending_recovered'], [2963])

    def test_corrupt_baseline_is_rejected_before_sql_or_interruption(self):
        with tempfile.TemporaryDirectory() as directory:
            run=Path(directory)
            p14.write_json(run/'metadata.json', {'marks':[],'scenario':'C','start_utc':'2026-10-25T00:00:00Z','end_utc':None,'php':'unused'})
            events={n:[] for n in p14.NODES}
            events['esp32-02']=[{'kind':'serial_corruption','line':1}]
            with patch.object(p14,'read_events',return_value=(events,[])), patch.object(p14,'sql') as sql:
                with self.assertRaisesRegex(p14.EvidenceIntegrityError,'No interrumpir'):
                    p14.mark(run,'ready',True,{'automatic_verified':True})
                sql.assert_not_called()
            self.assertFalse(p14.load_json(run/'metadata.json')['marks'])


if __name__ == '__main__': unittest.main()
