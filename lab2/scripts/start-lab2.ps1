# Gestor local, sin pruebas de integracion. Windows PowerShell 5.1.
[CmdletBinding()]
param(
    [ValidateSet('Start', 'Status', 'Stop')][string]$Mode = 'Status',
    [string]$XamppRoot = 'C:\xampp',
    [string]$Broker = 'C:\Program Files\mosquitto\mosquitto.exe'
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$XamppRoot = [IO.Path]::GetFullPath($XamppRoot)
$Broker = [IO.Path]::GetFullPath($Broker)
$root = Split-Path $PSScriptRoot -Parent
$runtime = Join-Path $root '.runtime\start-lab2'
$statePath = Join-Path $runtime 'processes.json'
$php = Join-Path $XamppRoot 'php\php.exe'
$apache = Join-Path $XamppRoot 'apache\bin\httpd.exe'
$apacheConfig = Join-Path $XamppRoot 'apache\conf\httpd.conf'
$mysql = Join-Path $XamppRoot 'mysql\bin\mysqld.exe'
$mysqlConfig = Join-Path $XamppRoot 'mysql\bin\my.ini'
$d2 = Join-Path $root 'config\mqtt-d2\broker.conf'
$router = Join-Path $root 'public\index.php'
$subscriber = Join-Path $PSScriptRoot 'mqtt-subscriber.php'
$udpScript = Join-Path $PSScriptRoot 'test-udp-discovery.ps1'
$powershell = Join-Path $PSHOME 'powershell.exe'
$script:records = @()
$script:failed = $false

function Quote([string]$Value) {
    if ($Value.Contains('"') -or $Value.EndsWith('\')) { throw 'Ruta no admitida en argumentos.' }
    return '"' + $Value + '"'
}
function Require-File([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "Falta archivo requerido: $Path" }
}
function Save-State {
    $data = @{ Version = 1; Root = $root; Processes = @($script:records) }
    $temporary = $statePath + '.tmp'
    [IO.File]::WriteAllText($temporary, ($data | ConvertTo-Json -Depth 6), [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporary -Destination $statePath -Force
}
function Get-Snapshot {
    # Fallar antes de arrancar/detener si Windows no permite comprobar identidades.
    try {
        $script:processes = @(Get-CimInstance Win32_Process -ErrorAction Stop)
        $script:tcp = @(Get-NetTCPConnection -State Listen -ErrorAction Stop)
        $script:udp = @(Get-NetUDPEndpoint -ErrorAction Stop)
    } catch {
        throw 'Windows no permite consultar procesos/sockets. Use una consola con permisos de consulta suficientes; no se cambian privilegios ni servicios.'
    }
}
function Get-Stamp($Process) { return $Process.CreationDate.ToUniversalTime().Ticks.ToString() }
function Test-Identity($Record, $Process) {
    return ($null -ne $Process -and $Process.ExecutablePath -and $Process.CommandLine -and
        $Record.Executable -ieq $Process.ExecutablePath -and
        $Record.Command -ceq $Process.CommandLine -and $Record.Created -eq (Get-Stamp $Process))
}
function Get-Record($Process, [string]$Service, [string]$StopFile = '') {
    if (-not $Process.ExecutablePath -or -not $Process.CommandLine) {
        throw 'No se puede registrar la identidad del proceso; revise permisos de consulta.'
    }
    return [pscustomobject]@{ Service = $Service; Id = [int]$Process.ProcessId
        Created = Get-Stamp $Process; Executable = $Process.ExecutablePath
        Command = $Process.CommandLine; StopFile = $StopFile }
}
function Update-OwnedChildren {
    # Registrar tambien hijos (Apache y mosquitto_sub) mientras su parentesco es comprobable.
    $changed = $false
    for ($level = 0; $level -lt 8; $level++) {
        $added = @()
        foreach ($record in @($script:records)) {
            $parent = @($script:processes | Where-Object ProcessId -eq $record.Id) | Select-Object -First 1
            if (-not (Test-Identity $record $parent)) { continue }
            foreach ($child in @($script:processes | Where-Object {
                $_.ParentProcessId -eq $parent.ProcessId -and $_.CreationDate -ge $parent.CreationDate
            })) {
                $known = @($script:records | Where-Object { $_.Id -eq $child.ProcessId -and (Test-Identity $_ $child) })
                if (-not $known.Count) { $added += Get-Record $child $record.Service }
            }
        }
        if (-not $added.Count) { break }
        $script:records += $added
        $changed = $true
    }
    if ($changed) { Save-State }
}
function Get-Arguments($Process) {
    # Los comandos admitidos no contienen comillas escapadas; rutas siempre entre comillas.
    $tokens = @([regex]::Matches($Process.CommandLine, '(?:"[^"]*"|[^\s"]+)+') |
        ForEach-Object { $_.Value.Replace('"','') })
    if ($tokens.Count -le 1) { return @() }
    return @($tokens[1..($tokens.Count - 1)])
}
function Test-PathArgument([string]$Actual, [string]$Expected) {
    # Una ruta relativa externa no acredita el directorio de trabajo del proceso.
    if (-not [IO.Path]::IsPathRooted($Actual)) { return $false }
    return [IO.Path]::GetFullPath($Actual) -ieq [IO.Path]::GetFullPath($Expected)
}
function Test-ServiceProcess($Service, $Process) {
    if (-not $Process.ExecutablePath -or -not $Process.CommandLine -or
        $Process.ExecutablePath -ine $Service.Exe) { return $false }
    $arguments = @(Get-Arguments $Process)
    switch ($Service.Name) {
        'Apache' {
            # XAMPP sin -f usa apache/conf/httpd.conf. Rechazar overrides no conocidos.
            for ($i = 0; $i -lt $arguments.Count; $i++) {
                switch ($arguments[$i]) {
                    '-f' { $i++; if ($i -ge $arguments.Count -or -not (Test-PathArgument $arguments[$i] $apacheConfig)) { return $false } }
                    '-d' { $i++; if ($i -ge $arguments.Count -or -not (Test-PathArgument $arguments[$i] (Join-Path $XamppRoot 'apache'))) { return $false } }
                    '-k' { $i++; if ($i -ge $arguments.Count -or $arguments[$i] -ne 'runservice') { return $false } }
                    default { return $false }
                }
            }
            return $true
        }
        'MariaDB' {
            return ($arguments.Count -in @(1,2) -and $arguments[0] -like '--defaults-file=*' -and
                (Test-PathArgument $arguments[0].Substring(16) $mysqlConfig) -and
                ($arguments.Count -eq 1 -or $arguments[1] -eq '--standalone'))
        }
        'PHP' {
            $expected = @('-d','display_errors=0','-S','127.0.0.1:18082','-t',(Join-Path $root 'public'),$router)
            return (($arguments -join "`n") -ieq ($expected -join "`n"))
        }
        'MQTT D2' { return ($arguments.Count -eq 2 -and $arguments[0] -eq '-c' -and (Test-PathArgument $arguments[1] $d2)) }
        'Subscriber' {
            return ($arguments.Count -in @(1,2) -and (Test-PathArgument $arguments[0] $subscriber) -and
                ($arguments.Count -eq 1 -or $arguments[1] -match '^--stop-file=[a-zA-Z0-9_.-]{1,100}$'))
        }
        'UDP' {
            return (($arguments -join "`n") -ieq (@('-NoProfile','-NonInteractive','-File',$udpScript) -join "`n"))
        }
    }
    return $false
}
function Get-ServiceState($Service) {
    $sockets = @()
    if ($Service.Name -eq 'UDP') { $sockets = @($script:udp | Where-Object LocalPort -eq 37020) }
    elseif ($Service.Ports.Count) { $sockets = @($script:tcp | Where-Object { $_.LocalPort -in $Service.Ports }) }
    $candidates = @($script:processes | Where-Object {
        $_.Name -ieq [IO.Path]::GetFileName($Service.Exe) -and (Test-ServiceProcess $Service $_)
    })
    $candidateIds = @($candidates | ForEach-Object { $_.ProcessId })
    if ($candidates.Count -eq 0 -and $sockets.Count -eq 0) {
        # Otros Mosquitto (p. ej. servicio Windows en 1883/9883) no compiten con D2.
        # Si 1884/8883 estan ocupados, sus propietarios se verifican mas abajo.
        if ($Service.Name -eq 'MQTT D2') {
            return @{ State = 'DETENIDO'; Detail = 'Puertos D2 libres; sin instancia D2 verificable.'; Pids = @() }
        }
        # Detectar tambien procesos del mismo servicio con opciones/rutas ambiguas.
        $ambiguous = @($script:processes | Where-Object {
            ($Service.Name -in @('Apache','MariaDB','MQTT D2') -and $_.Name -ieq [IO.Path]::GetFileName($Service.Exe)) -or
            ($_.Name -ieq [IO.Path]::GetFileName($Service.Exe) -and (-not $_.ExecutablePath -or -not $_.CommandLine)) -or
            ($Service.Name -eq 'PHP' -and $_.CommandLine -match '-S\s+"?127\.0\.0\.1:18082(?:"|\s|$)') -or
            ($Service.Name -eq 'Subscriber' -and $_.CommandLine -like '*mqtt-subscriber.php*') -or
            ($Service.Name -eq 'UDP' -and $_.CommandLine -like '*test-udp-discovery.ps1*')
        })
        if ($ambiguous.Count) { return @{ State = 'CONFLICTO'; Detail = 'Proceso existente con configuracion no verificable; no se duplica.'; Pids = @($ambiguous | ForEach-Object { $_.ProcessId }) } }
        return @{ State = 'DETENIDO'; Detail = ''; Pids = @() }
    }
    $owners = @($sockets | Select-Object -ExpandProperty OwningProcess -Unique)
    if (@($owners | Where-Object { $_ -notin $candidateIds }).Count) {
        return @{ State = 'CONFLICTO'; Detail = 'Puerto ocupado por otro ejecutable/configuracion, o identidad inaccesible.'; Pids = $owners }
    }
    if ($Service.Name -eq 'Subscriber') {
        if ($candidates.Count -ne 1) { return @{ State = 'CONFLICTO'; Detail = 'Varios suscriptores; revisar manualmente.'; Pids = $candidateIds } }
        $children = @($script:processes | Where-Object {
            $_.ParentProcessId -eq $candidates[0].ProcessId -and $_.ExecutablePath -ieq $script:subscriberExe
        })
        if (-not $children.Count) { return @{ State = 'PENDIENTE'; Detail = 'PHP activo sin transporte mosquitto_sub verificable.'; Pids = @($candidates.ProcessId) } }
    } else {
        foreach ($port in $Service.Ports) {
            if (-not @($sockets | Where-Object LocalPort -eq $port).Count) {
                return @{ State = 'PENDIENTE'; Detail = "Proceso presente, falta listener $port."; Pids = $candidateIds }
            }
        }
        if ($Service.Name -in @('PHP','MQTT D2','UDP')) {
            $expectedAddresses = @{ PHP = @('127.0.0.1'); 'MQTT D2' = @('127.0.0.1','0.0.0.0'); UDP = @('0.0.0.0') }
            if ($sockets.Count -ne $Service.Ports.Count -or $owners.Count -ne 1 -or $candidates.Count -ne 1) {
                return @{ State = 'CONFLICTO'; Detail = 'Cantidad inesperada de procesos/listeners.'; Pids = $candidateIds }
            }
            for ($i = 0; $i -lt $Service.Ports.Count; $i++) {
                if (@($sockets | Where-Object { $_.LocalPort -eq $Service.Ports[$i] -and $_.LocalAddress -eq $expectedAddresses[$Service.Name][$i] }).Count -ne 1) {
                    return @{ State = 'CONFLICTO'; Detail = 'Direccion de escucha distinta de la esperada.'; Pids = $owners }
                }
            }
        }
    }
    foreach ($file in $Service.Files) {
        foreach ($process in $candidates) {
            if ((Get-Item -LiteralPath $file).LastWriteTimeUtc -gt $process.CreationDate.ToUniversalTime()) {
                return @{ State = 'PENDIENTE'; Detail = 'Configuracion cambiada despues del arranque; revisar/reiniciar manualmente.'; Pids = $candidateIds }
            }
        }
    }
    return @{ State = 'ACTIVO'; Detail = 'Ejecutable, argumentos y listeners verificados.'; Pids = $candidateIds }
}
function Invoke-Native([string]$Exe, [string[]]$Arguments) {
    $saved = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& $Exe @Arguments 2>&1)
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $saved }
    if ($code -ne 0) { throw 'Validacion local fallida; revise configuracion y logs privados (salida omitida).' }
    return $output
}
function Validate-Service($Service) {
    Require-File $Service.Exe
    foreach ($file in $Service.Files) { Require-File $file }
    switch ($Service.Name) {
        'Apache' {
            $includes = @(Invoke-Native $apache @('-f',$apacheConfig,'-t','-D','DUMP_INCLUDES'))
            $files = @($apacheConfig)
            foreach ($line in $includes) {
                if ("$line" -match '^\s*\((?:\*|\d+)\)\s+(.+)$') { $files += $Matches[1].Trim() }
            }
            $text = ($files | Select-Object -Unique | ForEach-Object { [IO.File]::ReadAllText($_) }) -join "`n"
            $blocks = @([regex]::Matches($text, '(?is)<VirtualHost\s+[^>]*:8443\s*>.*?</VirtualHost>'))
            if ($text -notmatch '(?m)^\s*Listen\s+8443\s+https\s*$' -or
                -not @($blocks | Where-Object { $_.Value -match '(?im)^\s*SSLEngine\s+on\s*$' }).Count) {
                throw 'Apache no tiene Listen 8443 https y VirtualHost TLS activos en sus archivos incluidos.'
            }
            $Service.Files = @($files | Select-Object -Unique)
        }
        'MariaDB' {
            if (-not (Test-Path -LiteralPath (Join-Path $XamppRoot 'mysql\data\mysql') -PathType Container)) {
                throw 'Falta el directorio MariaDB existente; no se inicializara una base nueva.'
            }
            $section = ''; $settings = @{}
            foreach ($line in [IO.File]::ReadAllLines($mysqlConfig)) {
                if ($line -match '^\s*\[([^]]+)\]') { $section = $Matches[1] }
                elseif ($section -eq 'mysqld' -and $line -match '^\s*(port|datadir)\s*=\s*(.*?)\s*$') { $settings[$Matches[1]] = $Matches[2].Trim('"') }
                elseif ($line -match '^\s*!include') { throw 'my.ini contiene includes; revisar manualmente antes del arranque.' }
            }
            if ($settings.port -ne '3306' -or -not (Test-PathArgument $settings.datadir (Join-Path $XamppRoot 'mysql\data'))) {
                throw 'my.ini no coincide con puerto 3306 y el datadir existente de XAMPP.'
            }
        }
        'MQTT D2' {
            # Reutiliza la validacion sin Prepare/Running: no genera ni inicia D1/D2.
            & (Join-Path $PSScriptRoot 'mqtt-d2.ps1') -Mode Validate -Broker $Broker -OpenSsl (Join-Path $XamppRoot 'apache\bin\openssl.exe') *> $null
        }
        'Subscriber' {
            # Solo lectura: comprobar el mismo requisito que mqtt-subscriber.php.
            # No imprime configuracion, fingerprints, opciones privadas ni credenciales.
            $code = @'
try { require $argv[1]; $c=Lab2\MqttRuntime::config(); $p=json_decode(file_get_contents($c['state_dir'].'/security.json'),true,8,JSON_THROW_ON_ERROR); if (($c['port']??0)!==1884 || !hash_equals(Lab2\MqttRuntime::fingerprint($c),$p['fingerprint']??'')) { exit(2); } echo json_encode([$c['state_dir'],$c['sub']],JSON_THROW_ON_ERROR); } catch (Throwable $e) { exit(3); }
'@
            $config = (Invoke-Native $php @('-d','display_errors=0','-r',$code,(Join-Path $root 'src\bootstrap.php'))) -join ''
            $values = ConvertFrom-Json -InputObject $config
            if ($values.Count -ne 2) { throw 'Respuesta de prerrequisitos MQTT no valida.' }
            $script:subscriberDir = [string]$values[0]
            $script:subscriberExe = [IO.Path]::GetFullPath([string]$values[1])
            Require-File $script:subscriberExe
        }
    }
}
function Stop-Owned {
    Get-Snapshot
    Update-OwnedChildren
    # Primero las raices: el subscriber recibe su archivo de parada antes de sus hijos.
    foreach ($record in @($script:records)) {
        $current = Get-CimInstance Win32_Process -Filter "ProcessId = $($record.Id)" -ErrorAction Stop
        if (-not (Test-Identity $record $current)) {
            Write-Host "[OMITIDO] $($record.Service) PID $($record.Id): termino o su identidad cambio."
            continue
        }
        if ($record.Service -eq 'Subscriber' -and $record.StopFile) {
            [IO.File]::WriteAllText($record.StopFile, '')
            for ($i = 0; $i -lt 30; $i++) {
                if (-not (Get-Process -Id $record.Id -ErrorAction SilentlyContinue)) { break }
                Start-Sleep -Milliseconds 100
            }
        }
        $live = Get-CimInstance Win32_Process -Filter "ProcessId = $($record.Id)" -ErrorAction Stop
        if (Test-Identity $record $live) {
            Stop-Process -Id $record.Id -ErrorAction Stop
            Write-Host "[DETENIDO] $($record.Service) PID $($record.Id)"
        }
    }
    Get-Snapshot
    Update-OwnedChildren
    $script:records = @($script:records | Where-Object {
        $entry = $_
        Test-Identity $entry (@($script:processes | Where-Object ProcessId -eq $entry.Id) | Select-Object -First 1)
    })
    Save-State
    Write-Host 'Stop completado; los procesos preexistentes no se administran ni se detienen.'
}

$lock = $null
try {
    $null = New-Item -ItemType Directory -Path $runtime -Force
    try { $lock = [IO.File]::Open((Join-Path $runtime 'manager.lock'), 'OpenOrCreate', 'ReadWrite', 'None') }
    catch { throw 'Otro gestor esta ejecutandose o el registro local no es escribible.' }
    if (Test-Path -LiteralPath $statePath) {
        $state = [IO.File]::ReadAllText($statePath) | ConvertFrom-Json
        if ($state.Version -ne 1 -or $state.Root -ine $root) { throw 'Registro local incompatible; no se gestionaran procesos.' }
        $script:records = @($state.Processes)
    }
    Get-Snapshot
    Update-OwnedChildren
    if ($Mode -eq 'Stop') { Stop-Owned; return }
    $services = @(
        @{ Name = 'Apache'; Exe = $apache; Args = ('-f ' + (Quote $apacheConfig)); Ports = @(8443); Files = @($apacheConfig) },
        @{ Name = 'MariaDB'; Exe = $mysql; Args = ('--defaults-file=' + (Quote $mysqlConfig) + ' --standalone'); Ports = @(3306); Files = @($mysqlConfig) },
        @{ Name = 'PHP'; Exe = $php; Args = ('-d display_errors=0 -S 127.0.0.1:18082 -t ' + (Quote (Join-Path $root 'public')) + ' ' + (Quote $router)); Ports = @(18082); Files = @($router) },
        @{ Name = 'MQTT D2'; Exe = $Broker; Args = ('-c ' + (Quote $d2)); Ports = @(1884,8883); Files = @($d2) },
        @{ Name = 'Subscriber'; Exe = $php; Args = (Quote $subscriber); Ports = @(); Files = @($subscriber, (Join-Path $root 'config\mqtt.local.php')) },
        @{ Name = 'UDP'; Exe = $powershell; Args = ('-NoProfile -NonInteractive -File ' + (Quote $udpScript)); Ports = @(37020); Files = @($udpScript) }
    )
    foreach ($service in $services) {
        try {
            Validate-Service $service
            Get-Snapshot
            $status = Get-ServiceState $service
            if ($Mode -eq 'Start' -and $status.State -eq 'DETENIDO') {
                if ($service.Name -eq 'Subscriber') {
                    foreach ($dependency in @('MariaDB','MQTT D2')) {
                        $dep = $services | Where-Object Name -eq $dependency
                        if ((Get-ServiceState $dep).State -ne 'ACTIVO') { throw "Subscriber requiere $dependency verificado." }
                    }
                }
                $stopFile = ''
                if ($service.Name -eq 'Subscriber') {
                    $stopName = 'start-lab2-' + [guid]::NewGuid().ToString('N') + '.stop'
                    $stopFile = Join-Path $script:subscriberDir $stopName
                    $service.Args += ' --stop-file=' + $stopName
                }
                $logName = $service.Name.Replace(' ','-') + '-' + [guid]::NewGuid().ToString('N')
                $started = Start-Process -FilePath $service.Exe -ArgumentList $service.Args -WorkingDirectory $root -WindowStyle Hidden -PassThru `
                    -RedirectStandardOutput (Join-Path $runtime ($logName + '.out.log')) -RedirectStandardError (Join-Path $runtime ($logName + '.err.log'))
                $process = Get-CimInstance Win32_Process -Filter "ProcessId = $($started.Id)"
                $started.Refresh()
                if (-not $process -or $started.HasExited -or -not (Test-ServiceProcess $service $process)) {
                    throw 'El proceso termino al arrancar o no se puede acreditar su identidad; revise sus logs locales privados.'
                }
                $script:records += Get-Record $process $service.Name $stopFile
                Save-State
                for ($i = 0; $i -lt 20; $i++) {
                    Start-Sleep -Milliseconds 250
                    Get-Snapshot
                    Update-OwnedChildren
                    $status = Get-ServiceState $service
                    if ($status.State -eq 'ACTIVO') { break }
                }
                if ($status.State -ne 'ACTIVO') { throw 'Arranque no confirmado; revise logs locales. El proceso registrado puede detenerse con Stop.' }
            }
            if ($service.Name -eq 'MQTT D2' -and $status.State -eq 'ACTIVO') {
                & (Join-Path $PSScriptRoot 'mqtt-d2.ps1') -Mode Running -Broker $Broker -OpenSsl (Join-Path $XamppRoot 'apache\bin\openssl.exe') *> $null
            }
            $ids = $status.Pids -join ','
            Write-Host "[$($status.State)] $($service.Name) | PID: $ids | $($status.Detail)"
            if ($status.State -in @('CONFLICTO','PENDIENTE') -or ($Mode -eq 'Start' -and $status.State -ne 'ACTIVO')) { $script:failed = $true }
        } catch {
            $script:failed = $true
            Write-Host "[ERROR] $($service.Name): $($_.Exception.Message)"
            if ($service.Name -in @('Apache','MariaDB')) {
                Write-Host '  Si XAMPP requiere permisos adicionales, inicie el servicio desde su panel autorizado. No se elevan permisos ni se modifica la configuracion.'
            }
            if ($service.Name -eq 'Subscriber') { Write-Host '  Requiere configuracion local y security.json valido preexistente; no se ejecutan pruebas para generarlo.' }
        }
    }
    Write-Host "Registro y logs privados: $runtime"
    Write-Host 'El estado no acredita autenticacion, ingesta ni conectividad LAN; no se envia telemetria.'
    if ($script:failed) { exit 1 }
} catch {
    Write-Host "[ERROR] $($_.Exception.Message)"
    exit 1
} finally {
    if ($null -ne $lock) { $lock.Dispose() }
}
