"""Fixtures aislados del analizador; no USB, servicios, SQL ni evidencias fisicas."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / "scripts/p14-evidence.py"
spec = importlib.util.spec_from_file_location("p14_evidence", SCRIPT)
p14 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p14)


def stamp(seconds):
    return f"2026-01-01T00:{seconds // 60:02d}:{seconds % 60:02d}.000000Z"


def fixture(scenario):
    times = [30, 40, 45, 105, 110]
    meta = {"scenario": scenario, "start_utc": stamp(0), "end_utc": stamp(210),
            "marks": [{"event": name, "utc": stamp(t), "services_ok": True} for name, t in zip(p14.ORDER, times)]}
    events = {n: [] for n in p14.NODES}
    before = {"status": "OK", "nodes": {}}
    after = {"status": "OK", "nodes": {}}
    for i, node in enumerate(p14.NODES, 1):
        base = i * 100
        affected = scenario == "A" or scenario == "B" and i < 3 or scenario == "C" and i == 3
        own = events[node]
        rows = []

        def ack(seq, t):
            own.append({"kind": "puback" if i == 3 else "http", "seq": seq, "utc": stamp(t), "code": 201, "ack": 1})
            rows.append({"id": seq * 10, "device_id": i, "node_id": node, "seq": seq, "ts": None,
                         "value": 0, "rssi": -60, "received_at": stamp(t).replace("T", " ").rstrip("Z"), "fixture_marker": None})

        ack(base, 10); ack(base + 1, 20)
        before["nodes"][node] = {"identity": {"id": i, "node_id": node}, "rows": copy.deepcopy(rows)}
        if affected:
            if scenario == "A":
                own += [{"kind": "wifi_down", "utc": stamp(50)}, {"kind": "wifi_up", "utc": stamp(115)}]
            if i == 3:
                own += [{"kind": "mqtt_enqueue", "seq": base + 2, "utc": stamp(51)},
                        {"kind": "mqtt_retry", "utc": stamp(55)}, {"kind": "mqtt_connected", "utc": stamp(116)}]
            else:
                own.append({"kind": "http", "seq": base + 2, "code": -1, "ack": 0, "utc": stamp(55)})
            ack(base + 2, 120); ack(base + 3, 132)
        else:
            ack(base + 2, 60); ack(base + 3, 75); ack(base + 4, 120); ack(base + 5, 132)
        after["nodes"][node] = {"identity": {"id": i, "node_id": node}, "rows": rows, "truncated": False}
    return meta, events, before, after, {"exit_code": 0, "forced": False}


class P14Tests(unittest.TestCase):
    def test_assessment_without_end_is_incomplete_instead_of_crashing(self):
        args=list(fixture('C'))
        args[0]['end_utc']=None
        self.assertEqual(p14.assess(*args)['status'],'INCOMPLETO')

    def test_automatic_successful_ack_during_affected_outage_is_failure(self):
        args = list(fixture('B'))
        args[0]['automation'] = {'description':'unit firewall'}
        args[1]['esp32-01'].append({'kind':'http','seq':100,'utc':stamp(70),'code':200,'ack':1})
        result = p14.assess(*args)
        self.assertEqual(result['status'],'FAIL')
        self.assertTrue(any('aislamiento' in reason for reason in result['failures']))

    def test_automation_error_can_never_be_overridden_by_successful_telemetry(self):
        args=list(fixture('C'))
        args[0]['automation_errors']=[{'status':'INCOMPLETO','reason':'restauracion no verificada'}]
        self.assertEqual(p14.assess(*args)['status'],'INCOMPLETO')

    def test_three_scenarios_have_distinct_recovery_requirements(self):
        for scenario in ("A", "B", "C"):
            with self.subTest(scenario=scenario):
                result = p14.assess(*fixture(scenario))
                self.assertEqual(result["status"], "PASS", result)
                self.assertTrue(all(r["recovery_observed"] for r in result["nodes"].values()))
                self.assertTrue(any("JSON" in s for s in result["limits"]))

    def test_puback_without_sql_is_not_persistence(self):
        args = list(fixture("C"))
        args[3]["nodes"]["esp32-03"]["rows"] = [r for r in args[3]["nodes"]["esp32-03"]["rows"] if r["seq"] != 302]
        result = p14.assess(*args)
        self.assertEqual(result["status"], "FAIL")
        self.assertEqual(result["nodes"]["esp32-03"]["confirmed_missing_sql"], [302])

    def test_absent_sql_is_unknown_instead_of_zero_or_pass(self):
        args = list(fixture("B"))
        args[3] = {"status": "INCOMPLETO", "nodes": {}}
        result = p14.assess(*args)
        self.assertEqual(result["status"], "INCOMPLETO")
        self.assertFalse(result["failures"])
        self.assertFalse(result["nodes"]["esp32-01"]["sql_available"])

    def test_missing_marks_and_aborted_capture_never_pass(self):
        args = list(fixture("A"))
        args[0]["marks"] = []
        args[0]["aborted"] = True
        args[4] = {"exit_code": 2, "forced": True}
        self.assertNotEqual(p14.assess(*args)["status"], "PASS")

    def test_replayed_old_confirmations_do_not_prove_new_sql_progress(self):
        args = list(fixture("A"))
        for i, node in enumerate(p14.NODES, 1):
            base = i * 100
            kept = []
            for event in args[1][node]:
                if event["kind"] in ("mqtt_enqueue", "sample") or event["kind"] == "http" and event["ack"] == 0:
                    continue
                if event.get("seq", 0) in (base + 2, base + 3):
                    event["seq"] -= 2
                kept.append(event)
            args[1][node] = kept
            args[3]["nodes"][node]["rows"] = args[3]["nodes"][node]["rows"][:2]
        result = p14.assess(*args)
        self.assertEqual(result["status"], "INCOMPLETO")
        self.assertTrue(all(not r["new_recovered_keys"] for r in result["nodes"].values()))

    def test_restart_during_outage_prevents_automatic_recovery_pass(self):
        args = list(fixture("A"))
        args[1]["esp32-02"].append({"kind": "boot", "utc": stamp(115)})
        result = p14.assess(*args)
        self.assertEqual(result["status"], "INCOMPLETO")
        self.assertTrue(any("RESET" in r for r in result["nodes"]["esp32-02"]["incomplete_reasons"]))

    def test_duplicate_sql_is_failure_but_repeated_ack_is_reported(self):
        args = list(fixture("B"))
        args[1]["esp32-01"].append(copy.deepcopy(args[1]["esp32-01"][-1]))
        result = p14.assess(*args)
        self.assertEqual(result["status"], "PASS")
        self.assertEqual(result["nodes"]["esp32-01"]["repeated_serial_ack_occurrences"], 1)
        args[3]["nodes"]["esp32-01"]["rows"].append(copy.deepcopy(args[3]["nodes"]["esp32-01"]["rows"][-1]))
        self.assertEqual(p14.assess(*args)["status"], "FAIL")

    def test_nonaffected_transport_must_continue_during_outage(self):
        args = list(fixture("B"))
        args[1]["esp32-03"] = [e for e in args[1]["esp32-03"] if not stamp(45) <= e["utc"] <= stamp(105)]
        self.assertEqual(p14.assess(*args)["status"], "INCOMPLETO")

    def test_payload_change_of_existing_key_is_detected(self):
        args = list(fixture("A"))
        args[3]["nodes"]["esp32-01"]["rows"][0]["rssi"] = -70
        self.assertEqual(p14.assess(*args)["status"], "FAIL")

    def test_no_permanent_loss_claim_when_pending_needs_more_observation(self):
        args = list(fixture("C"))
        args[1]["esp32-03"] = [e for e in args[1]["esp32-03"] if e["utc"] < stamp(105)]
        args[3]["nodes"]["esp32-03"]["rows"] = args[3]["nodes"]["esp32-03"]["rows"][:2]
        result = p14.assess(*args)
        self.assertEqual(result["status"], "INCOMPLETO")
        self.assertFalse(result["failures"])

    def test_serial_parser_preserves_original_and_normalizes_precision(self):
        with tempfile.TemporaryDirectory(prefix="p14-unit-") as directory:
            run = Path(directory)
            folder = run / "serial/capture"
            folder.mkdir(parents=True)
            for node in p14.NODES:
                (folder / (node + ".log")).write_text("", encoding="utf-8")
            original = "2026-01-01T00:00:01.000Z [esp32-01] [COM12] HTTP=201 seq=100 ack=1 PIR=REAL\n"
            path = folder / "esp32-01.log"
            path.write_text(original, encoding="utf-8")
            digest = hashlib.sha256(path.read_bytes()).hexdigest()
            events, problems = p14.read_events(run, stamp(0), "2026-01-01T00:00:01.000001Z")
            self.assertFalse(problems)
            self.assertEqual(events["esp32-01"][0]["utc"], "2026-01-01T00:00:01.000000Z")
            self.assertEqual(events["esp32-01"][0]["source_utc"], "2026-01-01T00:00:01.000Z")
            self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), digest)

    def test_sql_does_not_hide_missing_intermediate_serial_sources(self):
        args = list(fixture("B"))
        args[1]["esp32-03"] = [e for e in args[1]["esp32-03"] if e.get("seq") != 303]
        result = p14.assess(*args)
        self.assertEqual(result["status"], "INCOMPLETO")
        self.assertEqual(result["nodes"]["esp32-03"]["serial_range_gaps"], [303])
        self.assertFalse(result["nodes"]["esp32-03"]["sql_range_gaps"])

    def test_closed_marker_without_exit_code_cannot_accredit_complete_capture(self):
        args = list(fixture("A"))
        args[4] = {"exit_code": None, "forced": None, "closure": "UNCONFIRMED"}
        self.assertEqual(p14.assess(*args)["status"], "INCOMPLETO")

    def test_empty_evidence_is_saved_as_incomplete_outside_experimental_directory(self):
        metadata = {"scenario": "A", "start_utc": stamp(0), "end_utc": stamp(1), "marks": []}
        result = p14.assess(metadata, {n: [] for n in p14.NODES},
                            {"status": "INCOMPLETO"}, {"status": "INCOMPLETO"},
                            {"exit_code": None, "forced": None})
        with tempfile.TemporaryDirectory(prefix="p14-unit-empty-") as directory:
            folder = Path(directory)
            p14.report_files(folder, metadata, result, {}, folder / "sql-after.json")
            saved = p14.load_json(folder / "evidence.json")
            self.assertEqual(saved["result"]["status"], "INCOMPLETO")
            self.assertTrue((folder / "summary.md").is_file())
            self.assertTrue((folder / "sequences.csv").is_file())
            text = (folder / "evidence.json").read_text() + (folder / "summary.md").read_text()
            self.assertNotIn("Authorization", text)
            self.assertNotIn("token_hash", text)

    def test_manual_actions_are_ordered_and_not_executed_by_mark(self):
        with tempfile.TemporaryDirectory(prefix="p14-unit-") as directory:
            run = Path(directory)
            metadata = {"marks": [], "end_utc": None}
            p14.write_json(run / "metadata.json", metadata, exclusive=True)
            with patch.object(p14, "inspect_services", side_effect=AssertionError("No debe ejecutar nada")):
                with self.assertRaises(p14.P14Error):
                    p14.mark(run, "down", True)
            self.assertEqual(p14.load_json(run / "metadata.json"), metadata)

    def test_foreign_mosquitto_ports_are_not_d2_and_service_owner_blocks(self):
        ready = {"status": "OK", "sockets": [{"port": p} for p in [8443, 18082, 3306, 1884, 8883, 1883, 9883]],
                 "apache": {"verified": True}, "d2": {"verified": True, "windows_service_owned": False}}
        self.assertTrue(p14.service_check(ready, "C", "ready"))
        down = copy.deepcopy(ready)
        down["sockets"] = [s for s in down["sockets"] if s["port"] not in [1884, 8883]]
        down["d2"]["verified"] = False
        self.assertTrue(p14.service_check(down, "C", "down"))
        self.assertFalse(p14.service_check(down, "B", "down"))
        ready["d2"]["windows_service_owned"] = True
        self.assertFalse(p14.service_check(ready, "C", "ready"))


if __name__ == "__main__":
    unittest.main()
