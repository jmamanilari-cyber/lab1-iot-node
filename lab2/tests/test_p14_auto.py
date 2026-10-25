"""Regresion aislada: sin USB, SQL, firewall ni procesos reales."""
import copy
import importlib.util
import io
from contextlib import redirect_stdout, redirect_stderr
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('p14_auto', Path(__file__).resolve().parents[1] / 'scripts/p14-auto.py')
auto = importlib.util.module_from_spec(spec)
spec.loader.exec_module(auto)
p14 = auto.p14


def services():
    return {'status': 'OK', 'apache': {'verified': True, 'pids': [10], 'ports': [80,443,8443]},
            'dependencies': {'18082': True, '3306': True},
            'sockets': [{'port': port, 'pid': pid} for port, pid in [(8443,10),(18082,11),(3306,12),(1883,13),(9883,13),(1884,14),(8883,14)]],
            'subscriber': {'verified': True, 'pids': [15]},
            'd2': {'verified': True, 'windows_service_owned': False, 'identity': [{'pid':14,'created_utc':'2026-10-24T00:00:00Z'}]}}


class Clock:
    def __init__(self): self.now = 0
    def monotonic(self): return self.now
    def sleep(self, seconds): self.now += seconds


class AutoTests(unittest.TestCase):
    def test_foreign_ports_are_preserved_and_d2_can_change_only_for_c(self):
        before = services()
        now = copy.deepcopy(before)
        now['d2']['identity'][0]['pid'] = 99
        self.assertTrue(auto.unchanged(before, now, 'C'))
        self.assertFalse(auto.unchanged(before, now, 'B'))
        now['sockets'][3]['pid'] = 99
        self.assertFalse(auto.unchanged(before, now, 'C'))

    def test_http_and_dependency_identity_changes_reject(self):
        for change in ('apache', 'dependencies', 'subscriber'):
            before, now = services(), services()
            if change == 'apache': now['apache']['pids'] = [99]
            if change == 'dependencies': now['dependencies']['3306'] = False
            if change == 'subscriber': now['subscriber']['pids'] = [99]
            self.assertFalse(auto.unchanged(before, now, 'C'), change)

    def test_wait_timeout_and_success_are_bounded(self):
        clock = Clock()
        with self.assertRaises(auto.AutoError):
            auto.wait_until(lambda: False, 5, 'timeout', clock.sleep, clock.monotonic)
        self.assertEqual(clock.now, 5)
        self.assertTrue(auto.wait_until(lambda: True, 0, 'timeout', clock.sleep, clock.monotonic))

    def test_success_arriving_after_deadline_is_not_accepted(self):
        clock = Clock()
        def slow_success():
            clock.sleep(6)
            return True
        with self.assertRaises(auto.AutoError): auto.wait_until(slow_success,5,'timeout',clock.sleep,clock.monotonic)

    def test_control_stdin_has_no_bom_and_output_accepts_bom(self):
        def reply(cmd, **kwargs):
            kwargs['stdout'].write(b'\xef\xbb\xbf{"status":"OK"}')
            return SimpleNamespace(returncode=0)
        with patch.object(auto.subprocess,'run',side_effect=reply) as run:
            self.assertEqual(auto.control('Plan',scenario='C')['status'],'OK')
            self.assertTrue(run.call_args.kwargs['input'].startswith(b'{'))
            self.assertIn('-ExecutionPolicy',run.call_args.args[0])

    def test_os_resource_lock_releases_after_exception_and_prevents_concurrent_recover(self):
        with tempfile.TemporaryDirectory() as directory:
            run=Path(directory)
            with self.assertRaises(RuntimeError):
                with auto.resource_lock(run):
                    with self.assertRaises(auto.AutoError):
                        with auto.resource_lock(run): pass
                    raise RuntimeError('unit')
            with auto.resource_lock(run): pass

    def test_d2_listener_disappearing_and_reappearing_are_polled(self):
        before=services()
        down=copy.deepcopy(before)
        down['sockets']=[s for s in down['sockets'] if s['port'] not in (1884,8883)]
        down['d2']['verified']=False
        down['subscriber']['verified']=False
        trial=auto.Trial(SimpleNamespace(scenario='C'),{'before':before},Path('unit'))
        with patch.object(auto,'control',return_value={'services':down}):
            self.assertTrue(trial.state(down=True))
            self.assertIsNone(trial.state())
        with patch.object(auto,'control',return_value={'services':before}):
            self.assertIsNone(trial.state(down=True))
            self.assertTrue(trial.state())

    def test_b_down_requires_transport_failures_on_both_nodes_and_own_rule(self):
        with tempfile.TemporaryDirectory() as directory:
            run=Path(directory)
            p14.write_json(run/'metadata.json',{'marks':[{'event':'interrupt-intent','utc':'2026-10-24T00:00:00Z'}]})
            trial=auto.Trial(SimpleNamespace(scenario='B'),{},run)
            events={n:[] for n in p14.NODES}
            failure={'kind':'http','utc':'2026-10-24T00:00:01Z','code':-1,'ack':0}
            events[p14.NODES[0]].append(failure)
            with patch.object(trial,'state',return_value={'automatic_verified':True}), patch.object(trial,'events',return_value=events):
                self.assertFalse(trial.down())
                events[p14.NODES[1]].append(dict(failure,code=401))
                self.assertFalse(trial.down())
                events[p14.NODES[1]][0]=failure
                self.assertTrue(trial.down())
            with patch.object(trial,'state',return_value=None): self.assertFalse(trial.down())

    def test_ready_requires_two_distinct_serial_and_sql_keys_per_node(self):
        with tempfile.TemporaryDirectory() as directory:
            run=Path(directory)
            p14.write_json(run/'metadata.json',{'scenario':'C','marks':[],'start_utc':p14.utc(),'end_utc':None,'php':'unit'})
            events={n:[{'kind':'puback' if n==p14.NODES[2] else 'http','seq':1,'ack':1,'code':201}] * 2 for n in p14.NODES}
            snapshot={'status':'OK','nodes':{n:{'identity':{'id':i},'rows':[{'seq':1,'fixture_marker':None,'node_id':n,'device_id':i}]} for i,n in enumerate(p14.NODES)}}
            with patch.object(p14,'read_events',return_value=(events,[])), patch.object(p14,'sql',return_value=snapshot):
                with self.assertRaises(p14.P14Error): p14.mark(run,'ready',True,{'automatic_verified':True})
            self.assertFalse(p14.load_json(run/'metadata.json')['marks'])

    def test_known_corrupt_baseline_does_not_wait_or_interrupt(self):
        trial=auto.Trial(SimpleNamespace(scenario='C'),{},Path('unit'))
        with patch.object(trial,'state',return_value={'automatic_verified':True}), \
                patch.object(p14,'mark',side_effect=p14.EvidenceIntegrityError('Baseline corrupta; no interrumpir')), \
                patch.object(auto,'control') as control:
            with self.assertRaisesRegex(auto.AutoError,'Baseline corrupta'): trial.ready()
            control.assert_not_called()

    def test_dry_run_does_not_capture_write_or_interrupt(self):
        with patch.object(auto, 'control', return_value={'plan': {'name': 'unit'}}) as control, \
                patch.object(p14, 'start', side_effect=AssertionError('No captura')), \
                patch.object(p14, 'sql', side_effect=AssertionError('No SQL')), redirect_stdout(io.StringIO()):
            self.assertEqual(auto.main(['run','C','--dry-run']), 0)
            self.assertEqual(control.call_args.args, ('Plan',))
            self.assertEqual(control.call_count, 1)

    def test_preflight_failure_never_starts_capture(self):
        with patch.object(auto, 'control', side_effect=auto.AutoError('preflight')), patch.object(p14, 'start') as start:
            with self.assertRaises(auto.AutoError): auto.main(['run','C','--confirm-locations'])
            start.assert_not_called()

    def test_real_mode_requires_explicit_usb_confirmation(self):
        with patch.object(auto, 'control', return_value={'plan': {}}), patch.object(p14, 'start') as start:
            with self.assertRaises(auto.AutoError): auto.main(['run','C'])
            start.assert_not_called()

    def test_recovery_limit_cannot_exceed_180_or_automate_a(self):
        with redirect_stderr(io.StringIO()):
            for argv in (['run','A'], ['run','C','--recovery-timeout','181']):
                with self.assertRaises(SystemExit): auto.main(argv)

    def exercise(self, directory, scenario='C', fault=None):
        run = Path(directory)
        plan = {'name':'unit-resource', 'scenario':scenario, 'before':services()}
        p14.write_json(run/'resources.json', {'plan':plan, 'armed':False, 'restored':False})
        p14.write_json(run/'metadata.json', {'scenario':scenario,'marks':[], 'end_utc':None})
        args = SimpleNamespace(scenario=scenario, baseline_timeout=3, down_timeout=3, restore_timeout=3, recovery_timeout=3)
        trial = auto.Trial(args, plan, run)
        clock = Clock()
        calls = []
        def mark(folder, event, confirm, verified_services=None):
            data = p14.load_json(folder/'metadata.json')
            self.assertEqual(event, p14.ORDER[len(data['marks'])])
            data['marks'].append({'event':event,'utc':p14.utc()})
            p14.write_json(folder/'metadata.json',data)
        def ready():
            if fault == 'baseline': return False
            mark(run,'ready',True)
            return True
        def control(action, plan):
            calls.append(action)
            if fault == action.lower(): raise auto.AutoError('fallo '+action)
            return {'status':'OK'}
        def finish(folder, abort=False):
            calls.append('finish')
            # Evidencia parcial real del fixture, siempre fuera de logs/evidence.
            p14.write_json(folder/'unit-partial.json', {'aborted':abort,'metadata':p14.load_json(folder/'metadata.json')})
            return 2 if abort or fault == 'recovery' else 0
        with patch.object(auto.time,'monotonic',clock.monotonic), patch.object(auto.time,'sleep',clock.sleep), \
                patch.object(trial,'ready',side_effect=ready), \
                patch.object(trial,'state',return_value={'automatic_verified':True}), \
                patch.object(trial,'down',return_value=False if fault=='down' else {'automatic_verified':True}), \
                patch.object(trial,'recovery',return_value=fault!='recovery'), \
                patch.object(auto,'control',side_effect=control), patch.object(p14,'mark',side_effect=mark), \
                patch.object(p14,'finish',side_effect=finish), redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
            # Los defaults de wait_until se enlazan al definir; inyectar reloj ficticio explicitamente.
            original_wait = auto.wait_until
            with patch.object(auto,'wait_until',side_effect=lambda check, seconds, reason:
                              original_wait(check,seconds,reason,clock.sleep,clock.monotonic)):
                result = trial.execute()
        return run, result, calls, clock.now

    def test_success_marks_order_and_sixty_seconds_for_b_and_c(self):
        for scenario in ('B','C'):
            with tempfile.TemporaryDirectory() as directory:
                run, code, calls, duration = self.exercise(directory, scenario)
                self.assertEqual(code,0)
                marks = p14.load_json(run/'metadata.json')['marks']
                self.assertEqual([m['event'] for m in marks],list(p14.ORDER))
                self.assertEqual([m['utc'] for m in marks], sorted(m['utc'] for m in marks))
                self.assertEqual(duration,60)
                self.assertEqual(calls,['Interrupt','Restore','finish'])
                self.assertTrue(p14.load_json(run/'resources.json')['restored'])

    def test_incomplete_baseline_does_not_arm_or_interrupt(self):
        with tempfile.TemporaryDirectory() as directory:
            run, code, calls, _ = self.exercise(directory, fault='baseline')
            self.assertEqual(code,2)
            self.assertEqual(calls,['finish'])
            self.assertFalse(p14.load_json(run/'resources.json')['armed'])

    def test_failed_interrupt_restores_before_partial_report(self):
        with tempfile.TemporaryDirectory() as directory:
            run, code, calls, _ = self.exercise(directory, fault='interrupt')
            self.assertEqual(code,2)
            self.assertEqual(calls,['Interrupt','Restore','finish'])
            self.assertTrue((run/'unit-partial.json').exists())
            self.assertEqual([m['event'] for m in p14.load_json(run/'metadata.json')['marks']], ['ready','interrupt-intent'])
            self.assertFalse(p14.load_json(run/'actions.json')[0]['confirmed'])

    def test_down_timeout_does_not_fabricate_down_mark_and_cleans(self):
        with tempfile.TemporaryDirectory() as directory:
            run, code, calls, _ = self.exercise(directory, scenario='B', fault='down')
            self.assertEqual(code,2)
            self.assertEqual(calls,['Interrupt','Restore','finish'])
            self.assertNotIn('down',[m['event'] for m in p14.load_json(run/'metadata.json')['marks']])

    def test_restore_failure_retries_cleanup_and_preserves_journal(self):
        with tempfile.TemporaryDirectory() as directory:
            run, code, calls, _ = self.exercise(directory, fault='restore')
            self.assertEqual(code,2)
            self.assertEqual(calls,['Interrupt','Restore','Restore','finish'])
            self.assertTrue(p14.load_json(run/'resources.json')['restore_failed'])
            self.assertFalse(p14.load_json(run/'resources.json')['restored'])
            self.assertTrue((run/'capture.stop').exists())

    def test_recovery_incomplete_is_decided_by_final_analysis_without_false_abort(self):
        with tempfile.TemporaryDirectory() as directory:
            run, code, calls, _ = self.exercise(directory, fault='recovery')
            self.assertEqual(code,2)
            self.assertEqual(calls,['Interrupt','Restore','finish'])
            self.assertTrue(p14.load_json(run/'resources.json')['restored'])
            self.assertFalse(p14.load_json(run/'unit-partial.json')['aborted'])
            self.assertNotIn('automation_errors',p14.load_json(run/'metadata.json'))

    def test_recover_unarmed_has_no_actions(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(auto,'control') as control, redirect_stdout(io.StringIO()):
            run=Path(directory)
            p14.write_json(run/'resources.json',{'armed':False})
            self.assertEqual(auto.recover(run,10),0)
            control.assert_not_called()

    def test_recover_only_restores_and_checks_no_interrupt_or_stop(self):
        with tempfile.TemporaryDirectory() as directory, redirect_stdout(io.StringIO()):
            run=Path(directory)
            p14.write_json(run/'resources.json',{'armed':True,'plan':{'scenario':'B','name':'unit'}})
            with patch.object(auto,'control',side_effect=[{'status':'OK'},{'status':'OK','blocked':False,'services':services()}]) as control:
                self.assertEqual(auto.recover(run,10),0)
                self.assertEqual([c.args[0] for c in control.call_args_list],['Restore','State'])
                self.assertTrue(p14.load_json(run/'resources.json')['restored'])

    def test_unknown_rule_or_process_rejection_cannot_record_recovery_success(self):
        with tempfile.TemporaryDirectory() as directory:
            run=Path(directory)
            p14.write_json(run/'resources.json',{'armed':True,'plan':{'scenario':'C','name':'unit'}})
            with patch.object(auto,'control',side_effect=auto.AutoError('Identidad ajena')):
                with self.assertRaises(auto.AutoError): auto.recover(run,10)
            self.assertFalse(p14.load_json(run/'resources.json').get('restored',False))


if __name__ == '__main__': unittest.main()
