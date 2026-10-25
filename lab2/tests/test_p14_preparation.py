"""Estabilizacion previa a ready: sin USB, PDO ni acciones de control reales."""
import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / 'scripts/p14-evidence.py'
spec = importlib.util.spec_from_file_location('p14_preparation', SCRIPT)
p14 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p14)


def stamp(seconds):
    return f'2026-01-01T00:{seconds//60:02d}:{seconds%60:02d}.000000Z'


def metadata():
    return {'scenario':'C','start_utc':stamp(0),'end_utc':None,'marks':[], 'php':'unused',
            'automation':{'serial_preparation':{'minimum_progress_seconds':10,'maximum_silence_seconds':30}}}


def acknowledgements(first=10, last=22):
    return {node:[{'kind':'puback' if index==3 else 'http','seq':index*100+j,
                   'code':201,'ack':1,'utc':stamp(second),'line':j+2}
                  for j,second in enumerate((first,last))] for index,node in enumerate(p14.NODES,1)}


def snapshot(events):
    return {'status':'OK','nodes':{node:{'identity':{'id':index},'rows':[
        {'seq':e['seq'],'node_id':node,'device_id':index,'fixture_marker':None} for e in own]}
        for index,(node,own) in enumerate(events.items(),1)}}


class PreparationTests(unittest.TestCase):
    def test_startup_corruption_is_kept_and_ready_requires_clean_sql_progress(self):
        for scenario in ('B','C'):
            with self.subTest(scenario=scenario), tempfile.TemporaryDirectory() as directory:
                run=Path(directory); meta=metadata();meta['scenario']=scenario
                p14.write_json(run/'metadata.json',meta)
                original=run/'original.log'
                original.write_text('inicio corrupto \ufffd\nrepetido\nrepetido\n',encoding='utf-8')
                digest=hashlib.sha256(original.read_bytes()).hexdigest()
                events=acknowledgements();sql=snapshot(events)
                events['esp32-01'].insert(0,{'kind':'serial_corruption','utc':stamp(5),'line':1})
                with patch.object(p14,'read_events',return_value=(events,[])), patch.object(p14,'sql',return_value=sql) as query, \
                        patch.object(p14,'utc',return_value=stamp(25)):
                    p14.mark(run,'ready',True,{'automatic_verified':True})
                saved=p14.load_json(run/'metadata.json')
                self.assertEqual(saved['start_utc'],stamp(0))
                self.assertEqual(saved['baseline_start_utc'],'2026-01-01T00:00:05.000001Z')
                self.assertEqual(query.call_args.args[2],saved['baseline_start_utc'])
                self.assertEqual(saved['preparation']['initial_unreadable_counts']['esp32-01'],1)
                self.assertEqual(saved['preparation']['phase'],'READY')
                self.assertEqual(hashlib.sha256(original.read_bytes()).hexdigest(),digest)
                with self.assertRaises(p14.EvidenceIntegrityError):
                    p14.prepare_baseline(run,saved,events,stamp(30))

    def test_continuous_noise_is_pending_instead_of_aborting_or_readiness(self):
        with tempfile.TemporaryDirectory() as directory:
            run=Path(directory);meta=metadata(); events=acknowledgements()
            for second in (23,30,40):
                events['esp32-01'].append({'kind':'serial_malformed','utc':stamp(second),'line':second})
                with self.assertRaises(p14.P14Error): p14.prepare_baseline(run,meta,events,stamp(second+1))
            self.assertEqual(len(meta['preparation']['candidates']),3)
            self.assertNotIn('baseline_start_utc',meta)
            self.assertFalse(meta['marks'])

    def test_new_noise_resets_all_nodes_and_only_new_clean_progress_counts(self):
        with tempfile.TemporaryDirectory() as directory:
            run=Path(directory);meta=metadata();events=acknowledgements()
            p14.prepare_baseline(run,meta,events,stamp(25))
            events['esp32-02'].append({'kind':'serial_partial','utc':stamp(26),'line':9})
            with self.assertRaises(p14.P14Error): p14.prepare_baseline(run,meta,events,stamp(27))
            later=acknowledgements(30,42)
            for node in p14.NODES: events[node].extend(later[node])
            clean,start=p14.prepare_baseline(run,meta,events,stamp(45))
            self.assertEqual(start,'2026-01-01T00:00:26.000001Z')
            self.assertTrue(all(len(own)==2 for own in clean.values()))
            self.assertTrue(any(e['kind']=='serial_partial' for e in events['esp32-02']))

    def test_fixed_wait_duplicate_replay_and_cached_batch_cannot_establish_stability(self):
        for mode in ('silence','replay','cached_batch'):
            with self.subTest(mode=mode), tempfile.TemporaryDirectory() as directory:
                events=acknowledgements()
                now=stamp(90) if mode=='silence' else stamp(30)
                for own in events.values():
                    if mode=='replay': own[1]['seq']=own[0]['seq']
                    if mode=='cached_batch':
                        own[1]['utc']=own[0]['utc']
                        own.append(dict(own[0],utc=stamp(29)))
                with self.assertRaises(p14.P14Error): p14.prepare_baseline(Path(directory),metadata(),events,now)

    def test_sql_mismatch_does_not_freeze_baseline(self):
        with tempfile.TemporaryDirectory() as directory:
            run=Path(directory);p14.write_json(run/'metadata.json',metadata())
            events=acknowledgements(); sql=snapshot(events)
            sql['nodes']['esp32-03']['rows'][0]['device_id']=99
            with patch.object(p14,'read_events',return_value=(events,[])), patch.object(p14,'sql',return_value=sql), \
                    patch.object(p14,'utc',return_value=stamp(25)):
                with self.assertRaises(p14.P14Error): p14.mark(run,'ready',True,{'automatic_verified':True})
            saved=p14.load_json(run/'metadata.json')
            self.assertNotIn('baseline_start_utc',saved)
            self.assertFalse(saved['marks'])
            self.assertFalse((run/'sql-ready.json').exists())

    def test_valid_messages_in_same_rx_batch_as_corruption_do_not_seed_baseline(self):
        with tempfile.TemporaryDirectory() as directory:
            events=acknowledgements();meta=metadata()
            events['esp32-01'].append({'kind':'serial_corruption','utc':stamp(22),'line':9})
            with self.assertRaises(p14.P14Error): p14.prepare_baseline(Path(directory),meta,events,stamp(25))
            self.assertTrue(all(not node['distinct_sequences'] for node in meta['preparation']['progress'].values()))

    def test_corruption_during_experiment_cannot_be_rebased_away(self):
        source=Path(__file__).with_name('test_p14_evidence.py')
        spec=importlib.util.spec_from_file_location('p14_preparation_fixture',source)
        fixtures=importlib.util.module_from_spec(spec);spec.loader.exec_module(fixtures)
        meta,events,before,after,status=fixtures.fixture('C')
        meta['automation']=metadata()['automation']
        meta['baseline_start_utc']=stamp(5)
        events['esp32-01'].insert(0,{'kind':'serial_corruption','utc':stamp(2),'line':1})
        original_start=meta['start_utc']
        self.assertEqual(p14.assess(meta,events,before,after,status)['status'],'PASS')
        events['esp32-01'].append({'kind':'serial_corruption','utc':stamp(130),'line':50})
        result=p14.assess(meta,events,before,after,status)
        self.assertEqual(result['status'],'INCOMPLETO')
        self.assertEqual(result['nodes']['esp32-01']['serial_unreadable_lines'][0]['line'],50)
        self.assertEqual(meta['start_utc'],original_start)
        self.assertEqual(meta['baseline_start_utc'],stamp(5))


if __name__ == '__main__': unittest.main()
