# Ejecutar desde lab2: .\scripts\test-mqtt-d2.ps1
# Solo conecta al broker existente. No modifica D1 ni genera security.json.
[CmdletBinding()]
param([string]$Php = 'C:\xampp\php\php.exe')
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath $Php -PathType Leaf)) { throw 'PHP CLI no encontrado.' }
$guard = Join-Path $PSScriptRoot 'mqtt-d2.ps1'
$test = Join-Path (Split-Path $PSScriptRoot -Parent) 'tests\d2-security.php'

function Get-D2Process {
    $ids = @(Get-NetTCPConnection -State Listen -LocalPort 8883 |
        Select-Object -ExpandProperty OwningProcess -Unique)
    if ($ids.Count -ne 1) { throw 'No hay una unica instancia D2 en 8883.' }
    $p = Get-CimInstance Win32_Process -Filter "ProcessId = $($ids[0])"
    return "$($p.ProcessId)|$($p.CreationDate.ToUniversalTime().Ticks)"
}

# Running acredita listeners, PID, -c real y ausencia de cambios posteriores al arranque.
& $guard -Mode Running 6>$null
$before = Get-D2Process
Write-Host 'OK: instancia D2 y configuracion ejecutada verificadas; iniciando MQTT 5 sobre TLS 8883.'
& $Php -d display_errors=0 -d log_errors=0 $test
if ($LASTEXITCODE -ne 0) { throw 'FAIL: prueba MQTT D2 incompleta o con discrepancias; no hay evidencia de seguridad D2.' }
& $guard -Mode Running 6>$null
if ((Get-D2Process) -cne $before) { throw 'FAIL: el broker cambio durante la prueba; resultado no acreditado.' }
Write-Host 'PASS D2: TLS, autenticacion y ACL comprobados contra la misma instancia y su -c real. Sin evidencia D1 reutilizada.'
