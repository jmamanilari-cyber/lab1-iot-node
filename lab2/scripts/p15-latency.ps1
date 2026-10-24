param([ValidateRange(30, 86400)][int]$Seconds = 300)
$ErrorActionPreference = 'Stop'
$root = Join-Path $PSScriptRoot '..\logs\latency'
New-Item -ItemType Directory -Path $root -Force | Out-Null
$active = Join-Path $root 'active'
if (Test-Path -LiteralPath $active) { throw 'Ya existe una medicion P15 activa. No se ha modificado.' }
$name = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ') + '_' + [Guid]::NewGuid().ToString('N').Substring(0,8)
$directory = Join-Path $root $name
New-Item -ItemType Directory -Path $directory | Out-Null
$csv = Join-Path $directory 'measurements.csv'
[System.IO.File]::WriteAllText($csv, "time_utc,transport,node_id,seq,result,duration_ms`n", [System.Text.UTF8Encoding]::new($false))
[System.IO.File]::WriteAllText($active, $name, [System.Text.Encoding]::ASCII)
$started = [DateTime]::UtcNow
$clock = [System.Diagnostics.Stopwatch]::StartNew()
Write-Host "Campana activa: $directory"
Write-Host "Medicion del backend por $Seconds segundos. Mantener PHP y el suscriptor MQTT activos."
try {
    Start-Sleep -Seconds $Seconds
} finally {
    $clock.Stop()
    $ended = [DateTime]::UtcNow
    Remove-Item -LiteralPath $active -Force -ErrorAction SilentlyContinue
    $metadata = @(
        "Inicio UTC: $($started.ToString('o'))",
        "Final UTC: $($ended.ToString('o'))",
        "Duracion monotona (s): $($clock.Elapsed.TotalSeconds)",
        "Medicion: TelemetryService::receive() hasta salida de metodo",
        "No incluye Wi-Fi, TLS de Apache, autenticacion previa, cola MQTT ni auditoria posterior"
    ) -join "`n"
    [System.IO.File]::WriteAllText((Join-Path $directory 'metadata.txt'), $metadata + "`n", [System.Text.UTF8Encoding]::new($false))
    Write-Host 'Campana cerrada.'
}
Start-Sleep -Milliseconds 1000
$summary = & python (Join-Path $PSScriptRoot 'p15-latency-report.py') $csv
$summary | Tee-Object -FilePath (Join-Path $directory 'summary.txt')
Write-Host "Conservar: $directory"
