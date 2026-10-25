"""P14-B/C fisico. --dry-run no abre USB ni modifica recursos. Nunca automatiza A."""
import argparse
from contextlib import contextmanager
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time

spec = importlib.util.spec_from_file_location("p14", Path(__file__).with_name("p14-evidence.py"))
p14 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p14)


class AutoError(p14.P14Error):
    pass


class ControlTimeout(AutoError):
    """Solo TimeoutExpired del controlador, no rechazo, JSON invalido ni cambio de identidad."""


def control(action, plan=None, scenario=None, clients=None, timeout=45):
    """Unica frontera de acciones Windows; stdin JSON, errores privados suprimidos."""
    shell = Path(os.environ.get("SystemRoot", r"C:\Windows")) / "System32/WindowsPowerShell/v1.0/powershell.exe"
    command = [str(shell), "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File",
               str(Path(__file__).with_name("p14-control.ps1")), "-Action", action]
    try:
        # Evitar stdout=PIPE: al arrancar Mosquitto, un proceso descendiente de
        # PowerShell puede conservar abierta la tuberia aunque el controlador
        # haya terminado. En Windows, subprocess.run puede quedar esperando EOF
        # incluso despues del timeout y de matar el proceso PowerShell.
        # TemporaryFile elimina la dependencia del EOF de los descendientes.
        with tempfile.TemporaryFile(mode="w+b") as output:
            reply = subprocess.run(
                command,
                input=json.dumps({"plan": plan, "scenario": scenario,
                                  "clients": clients or []}).encode("utf-8"),
                stdout=output,
                stderr=subprocess.DEVNULL,
                timeout=timeout,
                check=False,
            )
            output.seek(0)
            raw = output.read(1024 * 1024 + 1)
        if len(raw) > 1024 * 1024:
            raise AutoError("Control " + action + " produjo una salida excesiva; revisar registro.")
        data = json.loads(raw.decode("utf-8-sig"))
        if not isinstance(data, dict):
            raise AutoError("Control " + action + " devolvio una respuesta no valida.")
        if reply.returncode or data.get("status") != "OK":
            raise AutoError("Control " + action + " rechazado: " +
                            str(data.get("reason", "estado no verificable")) +
                            " No iniciar otro ensayo; revise el registro.")
        return data
    except AutoError:
        raise
    except subprocess.TimeoutExpired:
        raise ControlTimeout("Control " + action + " sin confirmacion; timeout del controlador.") from None
    except (OSError, ValueError, subprocess.SubprocessError):
        raise AutoError("Control " + action + " sin confirmacion; conservar registro y ejecutar recover.") from None


def unchanged(before, now, scenario, down=False):
    """No basta con puertos abiertos: conservar propietarios de componentes no afectados."""
    if now.get("status") != "OK":
        return False
    if not all(now.get("dependencies", {}).get(str(port)) for port in (18082, 3306)):
        return False
    if not now.get("apache", {}).get("verified"):
        return False
    if sorted(now["apache"]["pids"]) != sorted(before["apache"]["pids"]):
        return False
    if sorted(now["apache"]["ports"]) != sorted(before["apache"]["ports"]):
        return False
    protected = {80, 443, 8443, 18082, 3306, 1883, 9883}
    sockets = lambda s: sorted((r["port"], r["pid"]) for r in s["sockets"] if r["port"] in protected)
    if sockets(now) != sockets(before):
        return False
    if now.get("subscriber", {}).get("pids") != before.get("subscriber", {}).get("pids"):
        return False
    if scenario == "B":
        if not now["d2"]["verified"] or now["d2"]["windows_service_owned"] or not now["subscriber"]["verified"]:
            return False
    if scenario == "B" and now["d2"]["identity"] != before["d2"]["identity"]:
        return False
    return True


def journal(run, **changes):
    path = run / "resources.json"
    data = p14.load_json(path)
    data.update(changes, updated_utc=p14.utc())
    p14.write_json(path, data)
    return data


@contextmanager
def resource_lock(run):
    """Bloqueo del SO: se libera al morir el proceso, sin borrar locks ajenos."""
    with (run / 'automation.lock').open('a+b') as file:
        file.seek(0, 2)
        if file.tell() == 0:
            file.write(b'0'); file.flush()
        file.seek(0)
        try:
            if os.name == 'nt':
                import msvcrt
                msvcrt.locking(file.fileno(), msvcrt.LK_NBLCK, 1)
            else:
                import fcntl
                fcntl.flock(file, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            raise AutoError('Otro proceso automatico sigue activo; recover no puede competir con el ensayo.') from None
        try:
            yield
        finally:
            file.seek(0)
            if os.name == 'nt':
                msvcrt.locking(file.fileno(), msvcrt.LK_UNLCK, 1)
            else:
                fcntl.flock(file, fcntl.LOCK_UN)


def action(run, name, plan):
    path = run / "actions.json"
    records = p14.load_json(path) if path.exists() else []
    record = {"action": name, "intent_utc": p14.utc(), "resource": plan["name"], "confirmed": False}
    records.append(record)
    p14.write_json(path, records)  # conservar intencion incluso si el hijo muere
    result = control(name, plan)
    record.update(confirmed=True, confirmed_utc=p14.utc(), result=result)
    p14.write_json(path, records)
    return result


def wait_until(check, seconds, reason, sleep=time.sleep, clock=time.monotonic):
    deadline = clock() + seconds
    while True:
        result = check()
        if result and clock() <= deadline:
            return result
        if clock() >= deadline:
            raise AutoError(reason)
        sleep(min(2, max(0, deadline - clock())))


class Trial:
    def __init__(self, args, plan, run):
        self.args, self.plan, self.run = args, plan, run
        self.deadline = None

    def remaining(self, maximum=45):
        return min(maximum, max(0.1, self.deadline - time.monotonic())) if self.deadline is not None else maximum

    def wait(self, check, seconds, reason):
        self.deadline = time.monotonic() + seconds
        try:
            return wait_until(check, seconds, reason)
        finally:
            self.deadline = None

    def metadata(self):
        return p14.load_json(self.run / "metadata.json")

    def state(self, down=False):
        data = control("State", self.plan, timeout=self.remaining())
        if not unchanged(self.plan["before"], data["services"], self.args.scenario, down):
            raise AutoError("Cambio de identidad o caida de un componente no afectado; restaurar y revisar aislamiento.")
        if self.args.scenario == "B" and data["blocked"] != down:
            return None
        if self.args.scenario == "C":
            services = data["services"]
            if down and any(s["port"] in (1884, 8883) for s in services["sockets"]):
                return None
            if not down and not (services["d2"]["verified"] and not services["d2"]["windows_service_owned"]
                                 and services["subscriber"]["verified"]):
                return None
        data["services"]["automatic_verified"] = True
        return data["services"]

    def events(self):
        meta = self.metadata()
        events, problems = p14.read_events(self.run, p14.analysis_start(meta), p14.utc())
        if problems:
            raise AutoError("Fuentes serie incompletas o identidad discordante; no continuar la interrupcion.")
        return events

    def ready(self):
        state = self.state()
        if not state:
            return False
        try:
            p14.mark(self.run, "ready", True, state, deadline=self.deadline)
            return True
        except p14.EvidenceIntegrityError as exc:
            raise AutoError(str(exc)) from None
        except p14.P14Error:
            if (self.run / "capture-status.json").exists():
                raise AutoError("Captura finalizada antes de baseline; no se interrumpe ningun componente.") from None
            return False

    def down(self):
        state = self.state(down=True)
        if not state:
            return False
        meta = self.metadata()
        intent = next(m["utc"] for m in meta["marks"] if m["event"] == "interrupt-intent")
        affected = p14.NODES[:2] if self.args.scenario == "B" else p14.NODES[2:]
        events = self.events()
        for node in affected:
            if not any(e["utc"] >= intent and (e["kind"] == "mqtt_retry" if node == "esp32-03" else
                       e["kind"] == "http" and e["code"] <= 0 and e["ack"] == 0) for e in events[node]):
                return False
        return state

    def recovery(self):
        state = self.state()
        if not state:
            return False
        meta = self.metadata()
        end = p14.utc()
        events = self.events()
        after = p14.sql(meta["php"], p14.observed_keys(events), p14.analysis_start(meta), end, timeout=self.remaining(30))
        p14.write_json(self.run / "sql-recovery-latest.json", after)
        candidate = dict(meta, end_utc=end)
        result = p14.assess(candidate, events, p14.load_json(self.run / "sql-ready.json"),
                            after, {"exit_code": 0, "forced": False})
        # Esta evaluacion provisional no acredita cierre de captura ni se publica como resultado final.
        return result["status"] == "PASS"

    def observe_recovery(self, *, clock=time.monotonic, sleep=time.sleep):
        """Observar sin lanzar consultas con un timeout residual insuficiente.

        La evaluacion definitiva corresponde a finish(). Una consulta State/SQL
        iniciada en los ultimos instantes agotaria el timeout de PowerShell y
        convertiria erroneamente el cierre en abort=True. Se reserva un margen
        para observacion pasiva; no se relajan verificaciones de seguridad.
        """
        deadline = clock() + self.args.recovery_timeout
        # En el ensayo normal (180 s): ultimos 30 s sin consultas State/SQL.
        # Mantener compatibilidad con ensayos cortos de la suite unitaria.
        reserve = min(30.0, max(0.5, self.args.recovery_timeout / 6.0))
        self.deadline = deadline

        def close_observation():
            remaining = max(0.0, deadline - clock())
            if remaining:
                sleep(remaining)  # captura serie sigue activa hasta finish()
            print("[P14] Observacion concluida sin PASS provisional; evaluando SQL/serie final.", flush=True)
            return False

        try:
            while True:
                if deadline - clock() <= reserve:
                    return close_observation()
                try:
                    result = self.recovery()
                except ControlTimeout as exc:
                    # Si State vencio cuando ya se agoto el margen, NO confundir
                    # ese timeout residual con un fallo de recuperacion.
                    # Fallos de identidad, servicios, SQL y errores anteriores
                    # al margen siguen siendo fatales.
                    if (str(exc).startswith("Control State sin confirmacion")
                            and deadline - clock() <= reserve):
                        print("[P14] Consulta State expiro al finalizar la ventana; se conserva el analisis definitivo.", flush=True)
                        return close_observation()
                    raise
                if result:
                    print("[P14] Recuperacion provisional verificada; cerrando para evaluacion final.", flush=True)
                    return True
                remaining = deadline - clock()
                if remaining <= reserve:
                    return close_observation()
                sleep(min(2, remaining - reserve))
        finally:
            self.deadline = None

    def restore(self):
        # Se llama ANTES de cerrar captura/consultar SQL/informar errores.
        print("[P14] Restaurando recurso del ensayo...", flush=True)
        action(self.run, "Restore", self.plan)
        print("[P14] Accion Restore completada; verificando listeners...", flush=True)
        state = self.wait(lambda: self.state(), self.args.restore_timeout,
                          "Timeout verificando restauracion de listeners/suscriptor.")
        journal(self.run, restored=True, restored_utc=p14.utc(), restored_services=state)
        print("[P14] Servicio restaurado y comprobado.", flush=True)
        return state

    def execute(self):
        interrupted = False
        error = None
        try:
            self.wait(self.ready, self.args.baseline_timeout, "Baseline sin dos claves SQL/serie por nodo; no se interrumpio nada.")
            # Ultima comprobacion antes de armar un recurso y realizar la accion.
            if not self.state():
                raise AutoError("Servicios dejaron de estar disponibles antes de interrumpir.")
            p14.mark(self.run, "interrupt-intent", True)
            journal(self.run, armed=True)  # persistir ANTES de crear regla o terminar D2
            interrupted = True
            action(self.run, "Interrupt", self.plan)
            state = self.wait(self.down, self.args.down_timeout, "No se confirmo interrupcion real en los nodos afectados.")
            p14.mark(self.run, "down", True, state)
            until = time.monotonic() + 60
            while time.monotonic() < until:
                if not self.state(down=True):
                    raise AutoError("La interrupcion dejo de estar confirmada durante los 60 segundos.")
                time.sleep(min(2, max(0, until - time.monotonic())))
            p14.mark(self.run, "restore-intent", True)
            state = self.restore()
            p14.mark(self.run, "restored", True, state)
            # PASS provisional puede ser imposible antes del cierre o por
            # evidencias ya perdidas durante la interrupcion. En tal caso no
            # forzar abort=True: finish() evaluara las pruebas definitivamente.
            self.observe_recovery()
        except (Exception, KeyboardInterrupt) as exc:
            # Solo mensajes propios; nunca guardar excepciones arbitrarias de dependencias.
            error = str(exc) if isinstance(exc, p14.P14Error) else "Ensayo interrumpido o error de dependencia; evidencia parcial."
        finally:
            data = p14.load_json(self.run / "resources.json")
            if interrupted and not data.get("restored"):
                try:
                    self.restore()
                except (Exception, KeyboardInterrupt):
                    error = "RESTAURACION NO CONFIRMADA: ejecutar recover en este directorio; no repetir ensayo."
                    journal(self.run, restore_failed=True)
            # La captura y las evidencias permanecen aunque falle la restauracion o el analisis.
            if error:
                meta = self.metadata()
                meta.setdefault("automation_errors", []).append({"status": "INCOMPLETO", "reason": error, "utc": p14.utc()})
                p14.write_json(self.run / "metadata.json", meta)
                print(error, file=sys.stderr)
            (self.run / "capture.stop").touch(exist_ok=True)
        return p14.finish(self.run, abort=bool(error))


def recover(run, timeout):
    data = p14.load_json(run / "resources.json")
    if not data.get("armed"):
        print("Ningun recurso armado; no se modifican servicios.")
        return 0
    plan = data["plan"]
    # No matar procesos, ni retirar reglas por puerto/nombre parcial. Restore verifica identidad.
    action(run, "Restore", plan)
    def restored():
        reply = control("State", plan)
        if plan["scenario"] == "B":
            return not reply["blocked"] and reply["services"]["apache"]["verified"]
        return (reply["services"]["d2"]["verified"] and not reply["services"]["d2"]["windows_service_owned"]
                and reply["services"]["subscriber"]["verified"])
    wait_until(restored, timeout, "Restauracion no confirmada; revisar manualmente sin terminar procesos desconocidos.")
    journal(run, restored=True, recovered_utc=p14.utc())
    (run / "capture.stop").touch(exist_ok=True)
    print("Recursos propios restaurados. Esto no aprueba el ensayo; ejecutar finish --abort o analyze.")
    return 0


def bounded(low, high):
    def convert(value):
        number = int(value)
        if not low <= number <= high:
            raise argparse.ArgumentTypeError(f"Use {low}..{high} segundos.")
        return number
    return convert


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    auto = commands.add_parser("run")
    auto.add_argument("scenario", choices=("B", "C"))
    auto.add_argument("--dry-run", action="store_true")
    auto.add_argument("--confirm-locations", action="store_true")
    auto.add_argument("--https-clients", nargs=2, default=[], metavar=("IP_ESP32_01", "IP_ESP32_02"))
    auto.add_argument("--php", default=r"C:\xampp\php\php.exe")
    auto.add_argument("--baseline-timeout", type=bounded(30,300), default=180)
    auto.add_argument("--stability-seconds", type=bounded(2,60), default=10,
                      help="Progreso minimo observado entre secuencias distintas de cada nodo; no es una espera fija.")
    auto.add_argument("--down-timeout", type=bounded(15,120), default=90)
    auto.add_argument("--restore-timeout", type=bounded(10,90), default=45)
    auto.add_argument("--recovery-timeout", type=bounded(30,180), default=180)
    recovery = commands.add_parser("recover")
    recovery.add_argument("--run", required=True)
    recovery.add_argument("--timeout", type=bounded(10,90), default=45)
    args = parser.parse_args(argv)
    if args.command == "recover":
        run = p14.run_path(args.run)
        with resource_lock(run):
            return recover(run, args.timeout)
    plan = control("Plan", scenario=args.scenario, clients=args.https_clients)["plan"]
    if args.dry_run:
        print(json.dumps({"mode": "DRY_RUN", "plan": plan, "outage_seconds": 60,
                          "recovery_max_seconds": args.recovery_timeout,
                          "actions": ["capture", "estabilizacion sin interrupciones", "baseline SQL/serie >=2/nodo", *p14.ORDER, "finish + analisis"],
                          "changes_executed": False}, ensure_ascii=True, indent=2))
        return 0
    if not args.confirm_locations:
        raise AutoError("Validar las tomas USB guardadas y usar --confirm-locations; no se abre captura.")
    if p14.sql(args.php).get("status") != "OK":
        raise AutoError("SQL no disponible; no se inicia captura ni interrupcion.")
    description = ("Indisponibilidad de HTTPS por bloqueo de red; Apache permanece activo."
                   if args.scenario == "B" else "Interrupcion exclusiva de MQTT D2; HTTPS permanece activo.")
    args.dedicated_apache = False
    args.max_seconds = args.baseline_timeout + args.down_timeout + 60 + args.restore_timeout * 2 + args.recovery_timeout + 90
    run = p14.start(args, automatic={"description": description, "resource": plan["name"],
                                    "outage_seconds": 60, "recovery_max_seconds": args.recovery_timeout,
                                    "serial_preparation": {"minimum_progress_seconds": args.stability_seconds,
                                                           "maximum_silence_seconds": max(30, 2 * args.stability_seconds)}})
    p14.write_json(run / "resources.json", {"format_version": 1, "plan": plan, "armed": False, "restored": False}, exclusive=True)
    with resource_lock(run):
        return Trial(args, plan, run).execute()


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (Exception, KeyboardInterrupt) as exc:
        print(str(exc) if isinstance(exc, p14.P14Error) else
              "Operacion incompleta. Conservar evidencia; ejecutar recover si existe resources.json armado.", file=sys.stderr)
        sys.exit(2)
