"""Regresiones de apertura/baseline. Solo temporales y mocks; no USB ni servicios."""
import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('p14_stabilization', Path(__file__).resolve().parents[1] / 'scripts/p14-evidence.py')
p14 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p14)


class StabilizationTests(unittest.TestCase):
    def prepare(self, run):
        folder = run / 'serial/capture'
        folder.mkdir(parents=True)
        p14.write_json(run/'metadata.json', {'scenario':'C','start_utc':'2026-01-01T00:00:00.000000Z',
            'end_utc':None,'marks':[],'php':'unused','automation':{'description':'unit'}})
        snapshot = {'status':'OK','nodes':{}}
        for index,node in enumerate(p14.NODES,1):
            messages=[]
            for seq in (index*100,index*100+1):
                messages.append(f'PUBACK broker seq={seq}; MariaDB=NO_CONFIRMADA.' if index==3 else
                                f'HTTP=201 seq={seq} ack=1 PIR=SIMULADO')
            path=folder/(node+'.log')
            path.write_text(''.join(self.line(message,second) for second,message in zip((10,20),messages)),encoding='utf-8')
            snapshot['nodes'][node]={'identity':{'id':index},'rows':[
                {'seq':seq,'device_id':index,'node_id':node,'fixture_marker':None} for seq in (index*100,index*100+1)]}
        return folder,snapshot

    @staticmethod
    def line(message,second=10):
        return f'2026-01-01T00:00:{second:02d}.000Z [NODE] [COM5] {message}\n'

    def parse(self,run):
        # Etiquetas por nodo, sin inferir asociaciones por COM.
        for node in p14.NODES:
            path=run/'serial/capture'/(node+'.log')
            path.write_text(path.read_text(encoding='utf-8').replace('[NODE]',f'[{node}]'),encoding='utf-8')
        return p14.read_events(run,'2026-01-01T00:00:00Z','2026-01-01T00:00:59Z')

    def test_concatenated_and_prefixed_confirmations_never_count_as_valid(self):
        for message in ('HTTP=201 seq=100 ack=1HTTP=201 seq=101 ack=1',
                        'basura HTTP=201 seq=100 ack=1', 'TP=201 seq=100 ack=1',
                        'PUBACK broker seq=300; MariaDB=NO_CONFIRMADA.PUBACK broker seq=301'):
            with self.subTest(message=message), tempfile.TemporaryDirectory() as directory:
                run=Path(directory);folder,snapshot=self.prepare(run)
                (folder/'esp32-01.log').write_text(self.line(message),encoding='utf-8')
                events,problems=self.parse(run)
                self.assertFalse(problems)
                self.assertEqual(events['esp32-01'][0]['kind'],'serial_malformed')
                self.assertFalse(p14.confirmed(events['esp32-01'][0],'esp32-01'))
                hashes={p:hashlib.sha256(p.read_bytes()).hexdigest() for p in folder.glob('*.log')}
                with patch.object(p14,'sql') as sql, patch.object(p14,'utc',return_value='2026-01-01T00:00:59.000000Z'):
                    with self.assertRaises(p14.EvidenceIntegrityError): p14.mark(run,'ready',True,{'automatic_verified':True})
                    sql.assert_not_called()
                self.assertEqual(hashes,{p:hashlib.sha256(p.read_bytes()).hexdigest() for p in hashes})

    def test_unterminated_fragment_is_not_a_confirmation(self):
        with tempfile.TemporaryDirectory() as directory:
            run=Path(directory);folder,snapshot=self.prepare(run)
            path=folder/'esp32-01.log'
            path.write_text(self.line('HTTP=201 seq=100 ack=1').rstrip('\n'),encoding='utf-8')
            events,_=self.parse(run)
            self.assertEqual(events['esp32-01'],[])

    def test_repeated_messages_are_preserved_and_do_not_supply_second_sequence(self):
        with tempfile.TemporaryDirectory() as directory:
            run=Path(directory);folder,snapshot=self.prepare(run)
            path=folder/'esp32-01.log'
            path.write_text(self.line('HTTP=201 seq=100 ack=1')*3,encoding='utf-8')
            events,_=self.parse(run)
            self.assertEqual(sum(e['repeated_message'] for e in events['esp32-01']),2)
            original=path.read_bytes()
            with patch.object(p14,'sql',return_value=snapshot), patch.object(p14,'utc',return_value='2026-01-01T00:00:59.000000Z'):
                with self.assertRaises(p14.P14Error): p14.mark(run,'ready',True,{'automatic_verified':True})
            self.assertEqual(path.read_bytes(),original)
            self.assertFalse(p14.load_json(run/'metadata.json')['marks'])

    def test_ready_records_duplicates_and_requires_sql_identity_for_every_node(self):
        with tempfile.TemporaryDirectory() as directory:
            run=Path(directory);folder,snapshot=self.prepare(run)
            path=folder/'esp32-01.log'
            with path.open('a',encoding='utf-8') as file: file.write(self.line('HTTP=201 seq=101 ack=1',30))
            self.parse(run)
            with patch.object(p14,'sql',return_value=snapshot), patch.object(p14,'utc',return_value='2026-01-01T00:00:59.000000Z'):
                snapshot['nodes']['esp32-03']['rows'][0]['device_id']=99
                with self.assertRaises(p14.P14Error): p14.mark(run,'ready',True,{'automatic_verified':True})
                self.assertFalse(p14.load_json(run/'metadata.json')['marks'])
                snapshot['nodes']['esp32-03']['rows'][0]['device_id']=3
                p14.mark(run,'ready',True,{'automatic_verified':True})
            diagnostics=p14.load_json(run/'metadata.json')['marks'][0]['serial_stabilization']
            self.assertEqual(diagnostics['esp32-01']['repeated_confirmations'],1)
            self.assertEqual(diagnostics['esp32-01']['sql_correlated_sequences'],[100,101])
            self.assertEqual(set(diagnostics),set(p14.NODES))


if __name__ == '__main__': unittest.main()
