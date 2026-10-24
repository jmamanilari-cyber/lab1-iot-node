# Windows PowerShell 5.1 / PowerShell 7. No inicia ni detiene procesos.
# Prepare genera D2; Validate comprueba archivos; Running comprueba la instancia real.
# No ejecutar pruebas D1 como evidencia D2: su security.json no identifica el -c real.
[CmdletBinding()]
param(
    [ValidateSet('Prepare', 'Validate', 'Running')][string]$Mode = 'Validate',
    [string]$Broker = 'C:\Program Files\mosquitto\mosquitto.exe',
    [string]$OpenSsl = 'C:\xampp\apache\bin\openssl.exe'
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$d1 = Join-Path $root 'config\mqtt-private\broker.conf'
$d2 = Join-Path $root 'config\mqtt-d2\broker.conf'
$tlsDir = Join-Path $env:LOCALAPPDATA 'Lab2TLS'
$ca = Join-Path $tlsDir 'ca.crt'
$cert = Join-Path $tlsDir 'server.crt'
$key = Join-Path $tlsDir 'server.key'

function Require-File([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "Falta archivo requerido: $Path" }
}
function Invoke-Check([string]$Executable, [string[]]$Arguments) {
    # Nunca mostrar salida nativa ni ejecutar utilidades que inspeccionen secretos.
    $savedPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $null = & $Executable @Arguments 2>&1
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $savedPreference }
    if ($code -ne 0) { throw "Validacion nativa fallida (salida $code); no iniciar D2." }
}
foreach ($file in @($d1, $Broker, $OpenSsl, $ca, $cert, $key)) { Require-File $file }

# Admitir solo las directivas D1 conocidas. No includes, plugins ni overrides ocultos.
$settings = @{}
$allowed = @('listener', 'allow_anonymous', 'password_file', 'acl_file', 'persistence',
             'persistence_location', 'persistence_file', 'autosave_interval', 'log_dest', 'log_type')
$d1Text = [System.IO.File]::ReadAllText($d1)
foreach ($line in ($d1Text -split '\r?\n')) {
    $line = $line.Trim()
    if (-not $line -or $line.StartsWith('#')) { continue }
    if ($line -notmatch '^(\S+)\s+(.+)$') { throw 'Directiva D1 incompleta.' }
    $name, $value = $Matches[1], $Matches[2]
    if ($name -cnotin $allowed) { throw "Directiva D1 no prevista: $name; revisar manualmente." }
    if ($settings.ContainsKey($name) -and $name -ne 'log_type') { throw "Directiva D1 duplicada: $name" }
    $settings[$name] = $value
}
foreach ($name in $allowed) {
    if (-not $settings.ContainsKey($name)) { throw "Falta directiva D1: $name" }
}
if ($settings.listener -cne '1884 127.0.0.1' -or $settings.allow_anonymous -cne 'false' -or
    $settings.persistence -cne 'true') { throw 'D1 no coincide con el listener y seguridad esperados.' }
foreach ($name in @('password_file', 'acl_file', 'persistence_location')) {
    if (-not [System.IO.Path]::IsPathRooted($settings[$name])) { throw "D1 necesita ruta absoluta: $name" }
}
Require-File $settings.password_file  # Solo existencia/metadatos; nunca leer ni hashear passwords.
Require-File $settings.acl_file
if (-not (Test-Path -LiteralPath $settings.persistence_location -PathType Container)) {
    throw 'Falta el directorio de persistencia D1; no se reinicializa.'
}
# ACL D1 conocida: no permitir que una regla global inadvertida habilite la LAN.
$aclLines = @([System.IO.File]::ReadAllLines($settings.acl_file) |
    ForEach-Object { $_.Trim() } | Where-Object { $_ -and -not $_.StartsWith('#') })
$expectedAcl = @('user esp32-01', 'topic write lab2/esp32-01/telemetry',
    'user esp32-02', 'topic write lab2/esp32-02/telemetry',
    'user esp32-03', 'topic write lab2/esp32-03/telemetry',
    'user lab2-subscriber', 'topic read lab2/+/telemetry')
if (($aclLines -join "`n") -cne ($expectedAcl -join "`n")) { throw 'ACL D1 distinta de la esperada; revisar sin modificarla.' }

Invoke-Check $OpenSsl @('verify', '-CAfile', $ca, '-purpose', 'sslserver',
    '-verify_hostname', 'telemetry.lab.test', $cert)
# Evitar TLS < 1.2. Autenticacion por usuario/password D1, sin exigir certificados cliente.
$expected = "per_listener_settings false`n" + $d1Text.TrimEnd() + "`n`n" + @"
listener 8883 0.0.0.0
socket_domain ipv4
certfile $($cert.Replace('\', '/'))
keyfile $($key.Replace('\', '/'))
cafile $($ca.Replace('\', '/'))
tls_version tlsv1.2
require_certificate false
"@
$expected = $expected.Replace("`r`n", "`n").TrimEnd() + "`n"
if ($Mode -eq 'Prepare') {
    # No sobrescribir una configuracion distinta, especialmente si pudiera estar en uso.
    if (Test-Path -LiteralPath $d2) {
        if ([System.IO.File]::ReadAllText($d2).Replace("`r`n", "`n") -cne $expected) {
            throw 'D2 ya existe con otro contenido; revisar manualmente, no se sobrescribe.'
        }
    } else {
        $null = New-Item -ItemType Directory -Path (Split-Path $d2 -Parent) -Force
        [System.IO.File]::WriteAllText($d2, $expected, [System.Text.UTF8Encoding]::new($false))
    }
}
Require-File $d2
if ([System.IO.File]::ReadAllText($d2).Replace("`r`n", "`n") -cne $expected) {
    throw 'D2 no coincide con la configuracion segura derivada de D1.'
}
# Mosquitto 2.1+ incluye --test-config: analiza sin abrir listeners ni arrancar broker.
Invoke-Check $Broker @('-c', $d2, '--test-config')
Write-Host 'OK archivos D2: listeners 127.0.0.1:1884 y TLS IPv4 :8883, ACL/passwords/persistencia D1 y certificado valido.'

if ($Mode -ne 'Running') {
    Write-Host 'Solo validacion de archivos; autenticacion/ACL en ejecucion y acceso desde LAN pendientes.'
    Write-Host 'Antes del arranque manual, 1884 y 8883 deben estar libres; no ejecutar D1 y D2 simultaneamente.'
    return
}

# Vincular la verificacion a ambos sockets, el mismo PID y el -c realmente ejecutado.
$listeners = @(Get-NetTCPConnection -State Listen -ErrorAction Stop)
$selected = @($listeners | Where-Object { $_.LocalPort -in @(1884, 8883) })
if ($selected.Count -ne 2 -or @($selected.OwningProcess | Select-Object -Unique).Count -ne 1 -or
    @($selected | Where-Object { $_.LocalPort -eq 1884 -and $_.LocalAddress -eq '127.0.0.1' }).Count -ne 1 -or
    @($selected | Where-Object { $_.LocalPort -eq 8883 -and $_.LocalAddress -eq '0.0.0.0' }).Count -ne 1) {
    throw 'Los dos listeners D2 esperados no pertenecen a una unica instancia.'
}
$brokerPid = $selected[0].OwningProcess
$process = Get-CimInstance Win32_Process -Filter "ProcessId = $brokerPid"
if (-not $process -or $process.ExecutablePath -ine (Resolve-Path -LiteralPath $Broker).Path -or
    $process.CommandLine -notmatch '^\s*(?:"[^"]+"|\S+)\s+-c\s+(?:"(?<config>[^"]+)"|(?<config>\S+))\s*$') {
    throw 'No se puede verificar el ejecutable y su comando exacto; usar el arranque indicado sin opciones extra.'
}
if ($Matches.config -ine $d2) { throw 'El proceso no ejecuta el archivo D2 validado.' }
if (@($listeners | Where-Object { $_.OwningProcess -eq $brokerPid }).Count -ne 2) {
    throw 'La instancia tiene listeners adicionales no previstos.'
}
if (@($listeners | Where-Object { $_.LocalPort -eq 1883 -and $_.LocalAddress -notin @('127.0.0.1', '::1') }).Count) {
    throw 'Otro listener 1883 esta expuesto fuera de loopback; revisar manualmente.'
}
# No aceptar archivos cambiados despues del arranque como prueba de lo cargado.
foreach ($file in @($d1, $d2, $settings.acl_file, $settings.password_file, $ca, $cert, $key)) {
    if ((Get-Item -LiteralPath $file).LastWriteTimeUtc -gt $process.CreationDate.ToUniversalTime()) {
        throw 'Un archivo fue cambiado despues del arranque; la comprobacion no acredita la configuracion cargada.'
    }
}
Write-Host "OK instancia D2: PID $brokerPid ejecuta el -c validado y ambos listeners."
Write-Host 'No se reutiliza ni genera security.json D1. Pruebas autenticadas/ACL y acceso LAN siguen pendientes; no se publicaron mensajes.'
