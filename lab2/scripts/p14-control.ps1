# Control exclusivo de recursos P14. Entrada JSON por stdin; nunca imprime comandos privados.
[CmdletBinding()]
param([ValidateSet('Plan','Interrupt','Restore','State')][string]$Action = 'Plan')
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$broker = 'C:\Program Files\mosquitto\mosquitto.exe'
$config = Join-Path $root 'config\mqtt-d2\broker.conf'
$apache = 'C:\xampp\apache\bin\httpd.exe'
$publicReason = 'Accion rechazada o no confirmada; verificar permisos, identidad, reglas y listeners. Conservar resources.json y ejecutar recover.'
function Reject([string]$Message) {
    $script:publicReason = $Message
    throw 'P14: rechazo controlado.'
}
function Snapshot {
    $text = & (Join-Path $PSScriptRoot 'p14-inspect.ps1')
    $s = $text | ConvertFrom-Json
    if ($s.status -ne 'OK') { throw 'Inspeccion inaccesible.' }
    return $s
}
function Admin {
    return ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}
function Assert-LabHttps($s) {
    # Misma inspeccion de includes activos que start-lab2; no reinicia Apache.
    $main = 'C:\xampp\apache\conf\httpd.conf'
    $includes = @(& $apache -f $main -t -D DUMP_INCLUDES 2>&1)
    if ($LASTEXITCODE -ne 0) { throw 'No se valido configuracion Apache.' }
    $files = @($main)
    foreach ($line in $includes) {
        if ("$line" -match '^\s*\((?:\*|\d+)\)\s+(.+)$') { $files += $Matches[1].Trim() }
    }
    $text = (@($files | Select-Object -Unique) | ForEach-Object { [IO.File]::ReadAllText($_) }) -join "`n"
    $text = [regex]::Replace($text, '(?m)^\s*#.*$', '')
    $blocks = @([regex]::Matches($text, '(?is)<VirtualHost\s+[^>]*:8443\s*>.*?</VirtualHost>'))
    if ($text -notmatch '(?m)^\s*Listen\s+8443\s+https\s*$' -or $blocks.Count -ne 1 -or
        $blocks[0].Value -notmatch '(?im)^\s*SSLEngine\s+on\s*$' -or
        $blocks[0].Value -notmatch '(?im)^\s*ProxyPass\s+"?/api/v1/telemetry"?\s+"?http://127\.0\.0\.1:18082/api/v1/telemetry"?(?:\s|$)') {
        throw '8443 no corresponde inequivocamente al proxy TLS del laboratorio.'
    }
    $s.apache | Add-Member -NotePropertyName lab_https_verified -NotePropertyValue $true -Force
}
function Assert-Plan($p) {
    if ($p.name -notmatch '^Lab2-P14-[BC]-[a-f0-9]{32}$' -or $p.scenario -notin @('B','C') -or
        $p.name.Substring(9,1) -ne $p.scenario -or $p.broker -ine $broker -or $p.config -ine $config) { throw 'Plan no verificable.' }
    if ($p.scenario -eq 'C' -and $p.config_sha256 -ne (Get-FileHash -LiteralPath $config -Algorithm SHA256).Hash) { Reject 'Config D2 cambio; revision manual necesaria.' }
}
function Own-Rule($p) {
    $r = Get-NetFirewallRule -PolicyStore PersistentStore -Name $p.name -ErrorAction SilentlyContinue
    if (-not $r) { return $null }
    $ports = $r | Get-NetFirewallPortFilter
    $addr = $r | Get-NetFirewallAddressFilter
    $app = $r | Get-NetFirewallApplicationFilter
    if (@($r).Count -ne 1 -or $r.Description -ne $p.name -or $r.Direction -ne 'Inbound' -or
        $r.Action -ne 'Block' -or $r.Enabled -ne 'True' -or $r.Profile -ne 'Any' -or
        $ports.Protocol -notin @('TCP','6') -or [string]$ports.LocalPort -ne '8443' -or
        [string]$ports.RemotePort -ne 'Any' -or [string]$addr.LocalAddress -ne 'Any' -or
        (@($addr.RemoteAddress | Sort-Object) -join ',') -ne (@($p.clients | Sort-Object) -join ',') -or
        $app.Program -ine $apache) { Reject 'Regla modificada o ajena; no se elimina.' }
    return $r
}
function Remove-OwnRule($p) {
    $rule = Own-Rule $p
    if ($rule) { $rule | Remove-NetFirewallRule }
    if (Own-Rule $p) { Reject 'La regla propia sigue presente.' }
}
function Assert-D2Identity($p, $s) {
    if (-not $s.d2.verified -or $s.d2.windows_service_owned -or @($s.d2.identity).Count -ne 1 -or
        @($p.original_identity).Count -ne 1 -or $s.d2.identity[0].pid -ne $p.original_identity[0].pid -or
        $s.d2.identity[0].created_utc -ne $p.original_identity[0].created_utc) { Reject 'Identidad D2 cambio; no se termina ningun proceso.' }
}
try {
    $request = [Console]::In.ReadToEnd() | ConvertFrom-Json
    if ($Action -eq 'Plan') {
        $s = Snapshot
        if (-not $s.apache.verified -or -not $s.d2.verified -or $s.d2.windows_service_owned -or
            -not $s.dependencies.'18082' -or -not $s.dependencies.'3306' -or -not $s.subscriber.verified) { Reject 'Servicios o identidades incompletos: revisar Apache, PHP, MariaDB, D2 y suscriptor con inspect.' }
        $publicReason = 'No se verifico un unico VirtualHost TLS 8443 con proxy del laboratorio a 127.0.0.1:18082; no interrumpir.'
        Assert-LabHttps $s
        $publicReason = 'Accion rechazada o no confirmada; revisar identidades y listeners sin cambiar servicios.'
        $clients = @($request.clients)
        if ($request.scenario -eq 'B') {
            $publicReason = 'B requiere PowerShell como administrador ANTES de abrir USB; no se solicita elevacion durante el ensayo.'
            if (-not (Admin)) { throw 'B requiere consola elevada ANTES de abrir USB.' }
            $publicReason = 'B requiere dos IP privadas distintas, confirmadas por el operador para esp32-01 y esp32-02, en el enlace local.'
            if ($clients.Count -ne 2 -or @($clients | Select-Object -Unique).Count -ne 2) { throw 'Indique las dos IP verificadas de los nodos HTTPS.' }
            foreach ($client in $clients) {
                $ip = [Net.IPAddress]::Parse($client)
                if ($ip.AddressFamily -ne 'InterNetwork' -or $client -notmatch '^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)' -or
                    [string]$ip -ne $client -or @(Get-NetIPAddress -IPAddress $client -ErrorAction SilentlyContinue).Count) { throw 'IP cliente no aislable.' }
                $route = Find-NetRoute -RemoteIPAddress $client
                if (-not @($route | Where-Object { $_.PSObject.Properties['NextHop'] -and $_.NextHop -eq '0.0.0.0' }).Count) { throw 'Cliente fuera del enlace local.' }
            }
            $publicReason = 'Firewall no verificable/habilitado en todos los perfiles; no se cambia su configuracion.'
            if (@(Get-NetFirewallProfile -PolicyStore ActiveStore | Where-Object { -not $_.Enabled }).Count) { throw 'Firewall no habilitado en todos los perfiles; no se cambia su configuracion.' }
        } else {
            $publicReason = 'No hay permisos para abrir un handle de control D2; usar una consola con permisos suficientes antes del ensayo.'
            $probe = [Diagnostics.Process]::GetProcessById([int]$s.d2.identity[0].pid)
            try { $null = $probe.Handle } finally { $probe.Dispose() }
        }
        $plan = @{name=('Lab2-P14-' + $request.scenario + '-' + [guid]::NewGuid().ToString('N')); scenario=$request.scenario;
            clients=$clients; broker=$broker; config=$config; config_sha256=(Get-FileHash -LiteralPath $config -Algorithm SHA256).Hash;
            original_identity=@($s.d2.identity); before=$s; operation=$(if ($request.scenario -eq 'B') { 'Bloquear solo entrada TCP 8443, programa Apache, dos IP indicadas; Apache sigue activo.' } else { 'Detener solo PID/creacion/exe/config D2; restaurar -c con la misma configuracion.' })}
        @{status='OK'; plan=$plan} | ConvertTo-Json -Depth 15 -Compress
        exit 0
    }
    $p = $request.plan
    Assert-Plan $p
    if ($p.scenario -eq 'B') {
        if ($Action -ne 'State' -and -not (Admin)) { Reject 'B requiere consola elevada; no se solicita elevacion.' }
        $rule = Own-Rule $p
        if ($Action -eq 'Interrupt') {
            if ($rule) { Reject 'La regla ya existe; recuperar antes de otro ensayo.' }
            $null = New-NetFirewallRule -PolicyStore PersistentStore -Name $p.name -DisplayName $p.name -Description $p.name `
                -Direction Inbound -Action Block -Enabled True -Profile Any -Protocol TCP -LocalPort 8443 `
                -RemoteAddress $p.clients -Program $apache
            $rule = Own-Rule $p
            if (-not $rule) { Reject 'No se confirmo creacion de regla.' }
        } elseif ($Action -eq 'Restore' -and $rule) {
            Remove-OwnRule $p
            $rule = Own-Rule $p
            if ($rule) { Reject 'La regla propia sigue presente.' }
        }
        @{status='OK'; blocked=[bool]$rule; services=(Snapshot)} | ConvertTo-Json -Depth 15 -Compress
        exit 0
    }
    $s = Snapshot
    if ($Action -eq 'Interrupt') {
        Assert-D2Identity $p $s
        # Retener el objeto/handle evita terminar un PID reutilizado entre inspeccion y Kill.
        $target = [Diagnostics.Process]::GetProcessById([int]$s.d2.identity[0].pid)
        try {
            $null = $target.Handle
            if ($target.MainModule.FileName -ine $broker -or
                [Math]::Abs(($target.StartTime.ToUniversalTime() - [datetime]::Parse($s.d2.identity[0].created_utc).ToUniversalTime()).TotalMilliseconds) -ge 1) { Reject 'Handle no corresponde a D2.' }
            $again = Snapshot
            if (-not $again.d2.verified -or $again.d2.windows_service_owned -or
                $again.d2.identity[0].created_utc -ne $s.d2.identity[0].created_utc -or $again.d2.identity[0].pid -ne $target.Id) { Reject 'D2 cambio durante verificacion.' }
            $target.Kill()
            if (-not $target.WaitForExit(10000)) { Reject 'D2 no termino.' }
        } finally { $target.Dispose() }
    } elseif ($Action -eq 'Restore' -and -not ($s.d2.verified -and -not $s.d2.windows_service_owned)) {
        if (@($s.sockets | Where-Object port -in @(1884,8883)).Count) { Reject 'Puertos D2 ocupados por identidad no verificable; no duplicar.' }
        # Tambien rechazar un broker con este config que aun no haya abierto listeners.
        $candidates = @(Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -and $_.CommandLine.IndexOf($config,[StringComparison]::OrdinalIgnoreCase) -ge 0 })
        if ($candidates.Count) { Reject 'Proceso con config D2 sin listeners verificables; no duplicar.' }
        & (Join-Path $PSScriptRoot 'mqtt-d2.ps1') -Mode Validate -Broker $broker -OpenSsl 'C:\xampp\apache\bin\openssl.exe' *> $null
        # Mismo mecanismo de start-lab2: ejecutable y -c absolutos, sin preparar/configurar ni Stop general.
        $privateLogs = Join-Path $root '.runtime\start-lab2'
        $null = New-Item -ItemType Directory -Path $privateLogs -Force
        $logName = $p.name + '-' + [guid]::NewGuid().ToString('N')
        $null = Start-Process -FilePath $broker -ArgumentList ('-c "' + $config + '"') -WorkingDirectory $root -WindowStyle Hidden -PassThru `
            -RedirectStandardOutput (Join-Path $privateLogs ($logName + '.out.log')) -RedirectStandardError (Join-Path $privateLogs ($logName + '.err.log'))
    }
    @{status='OK'; services=(Snapshot)} | ConvertTo-Json -Depth 15 -Compress
} catch {
    # No volcar excepciones CIM/firewall ni comandos que puedan contener configuraciones privadas.
    @{status='INCOMPLETO'; reason=$publicReason} | ConvertTo-Json -Compress
    exit 2
}
