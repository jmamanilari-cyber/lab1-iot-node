#!/usr/bin/env python3
"""Report monotonic PHP service timings from a P15 latency capture CSV."""
import csv
import math
import pathlib
import statistics
import sys
from collections import defaultdict


def percentile(values, q):
    ordered = sorted(values)
    pos = (len(ordered) - 1) * q
    lower = math.floor(pos)
    upper = math.ceil(pos)
    return ordered[lower] + (ordered[upper] - ordered[lower]) * (pos - lower)


def main():
    if len(sys.argv) != 2:
        print('Uso: python scripts/p15-latency-report.py <measurements.csv>')
        return 2
    path = pathlib.Path(sys.argv[1])
    if not path.is_file():
        print(f'No se encuentra el archivo: {path}')
        return 2
    groups = defaultdict(list)
    count = 0
    with path.open('r', newline='', encoding='utf-8-sig') as handle:
        reader = csv.DictReader(handle)
        needed = {'time_utc', 'transport', 'node_id', 'seq', 'result', 'duration_ms'}
        if not needed.issubset(reader.fieldnames or []):
            print('CSV invalido: faltan columnas')
            return 2
        for row in reader:
            try:
                ms = float(row['duration_ms'])
                if not math.isfinite(ms) or ms < 0:
                    raise ValueError()
            except (ValueError, TypeError):
                print(f'Duracion invalida en fila {reader.line_num}')
                return 2
            transport, result = row['transport'], row['result']
            groups[(transport, 'TODOS', result)].append(ms)
            groups[(transport, row['node_id'], result)].append(ms)
            count += 1
    print('P15 — TelemetryService::receive() PHP: validacion + transaccion + commit')
    print('NO es RTT Wi-Fi/HTTPS, ni tiempo MQTT a MariaDB desde el ESP32.')
    print('p50/p95: interpolacion lineal, solo muestras presentes; errores separados.\n')
    print(f'Total de operaciones registradas: {count}')
    print(f'{"Transporte":10} {"Nodo":14} {"Resultado":10} {"N":>5} {"Media":>9} {"p50":>9} {"p95":>9} {"Max":>9}  (ms)')
    for (transport, node, outcome), nums in sorted(groups.items()):
        print(f'{transport:10} {node:14} {outcome:10} {len(nums):5d} '
              f'{statistics.mean(nums):9.3f} {percentile(nums, .5):9.3f} '
              f'{percentile(nums, .95):9.3f} {max(nums):9.3f}')
    if count == 0:
        print('Sin mediciones: verificar los servicios y que la campaña estaba activa.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
