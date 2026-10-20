# Laboratorio 2 - Demostracion verificable (Windows PowerShell 5.1 / PowerShell 7)
# Ejecutar desde el repositorio: .\lab2\scripts\demo.ps1
# Usa los scripts reales del proyecto, no contiene credenciales y no modifica PATH.
# Las pruebas insertan registros etiquetados de prueba. No reinicializar base de datos.

$ErrorActionPreference = 'Stop'
$script:RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$script:PhpProcess = $null
$script:BrokerProcess = $null
$script:HttpPort = 18082
$script:MqttPort = 1884
$script:DbPort = 3306
$script:HttpUrl = 'http://127.0.0.1:18082'

function Find-Executable {
    param([string[]] $Candidates, [string] $Name)
    foreach ($candidate in $Candidates) {
        if (Test-Path -LiteralPath $candidate -PathType Leaf) {
            return (Resolve-Path -LiteralPath $candidate).Path
        }
    }
    $cmd = Get-Command -Name $Name -CommandType Application -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($null -ne $cmd) { return $cmd.Source }
    return $null
}

$script:Php = Find-Executable -Candidates @('C:\xampp\php\php.exe') -Name 'php.exe'
$script:Mysql = Find-Executable -Candidates @('C:\xampp\mysql\bin\mysql.exe') -Name 'mysql.exe'
$script:Broker = Find-Executable -Candidates @('C:\Program Files\mosquitto\mosquitto.exe') -Name 'mosquitto.exe'
$script:DbConfig = Join-Path $script:RepoRoot 'lab2\config\database.local.php'
$script:MqttConfig = Join-Path $script:RepoRoot 'lab2\config\mqtt.local.php'
$script:BrokerConfig = Join-Path $script:RepoRoot 'lab2\config\mqtt-private\broker.conf'

if (-not (Test-Path -LiteralPath (Join-Path $script:RepoRoot 'lab2\public\index.php') -PathType Leaf)) {
    throw 'El script debe estar en lab2/scripts/ de un repositorio del Laboratorio 2.'
}

function Test-LocalPort {
    param([int] $Port)
    $client = New-Object System.Net.Sockets.TcpClient
    $pending = $null
    try {
        $pending = $client.BeginConnect('127.0.0.1', $Port, $null, $null)
        if (-not $pending.AsyncWaitHandle.WaitOne(600, $false)) { return $false }
        try { $client.EndConnect($pending); return $true }
        catch { return $false }
    } catch { return $false }
    finally {
        if ($null -ne $pending) { $pending.AsyncWaitHandle.Close() }
        $client.Close()
    }
}

function Wait-LocalPort {
    param([int] $Port, [int] $Attempts = 20)
    for ($i = 0; $i -lt $Attempts; $i++) {
        if (Test-LocalPort -Port $Port) { return $true }
        Start-Sleep -Milliseconds 250
    }
    return $false
}

function Assert-Executable {
    param([string] $Path, [string] $Name)
    if ([string]::IsNullOrWhiteSpace($Path) -or -not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "$Name no encontrado. Revise la opcion 1."
    }
}

function Show-Heading {
    param([string] $Text)
    Write-Host "`n========== $Text ==========" -ForegroundColor Cyan
}

function Show-Environment {
    Show-Heading '1. PROGRAMAS, SERVICIOS Y CONEXION A BASE DE DATOS'
    Write-Host "Carpeta del producto: $script:RepoRoot"
    foreach ($entry in @(
        @{ Name = 'PHP (ejecuta API y pruebas)'; Path = $script:Php },
        @{ Name = 'MariaDB CLI (administracion)'; Path = $script:Mysql },
        @{ Name = 'Mosquitto (broker MQTT)'; Path = $script:Broker }
    )) {
        if ([string]::IsNullOrWhiteSpace($entry.Path)) {
            Write-Host "[FALTA] $($entry.Name)" -ForegroundColor Yellow
        } else {
            Write-Host "[ENCONTRADO] $($entry.Name): $($entry.Path)" -ForegroundColor Green
        }
    }
    foreach ($entry in @(
        @{ Name = 'Credenciales de MariaDB (privadas)'; Path = $script:DbConfig },
        @{ Name = 'Configuracion MQTT (privada)'; Path = $script:MqttConfig },
        @{ Name = 'Broker aislado/ACL (privado)'; Path = $script:BrokerConfig }
    )) {
        if (Test-Path -LiteralPath $entry.Path -PathType Leaf) {
            Write-Host "[PRESENTE] $($entry.Name)"
        } else {
            Write-Host "[FALTA] $($entry.Name)" -ForegroundColor Yellow
        }
    }
    if (Test-LocalPort -Port $script:DbPort) {
        Write-Host '[EN ESCUCHA] MariaDB: 127.0.0.1:3306 (se comprobara acceso PDO)'
    } else {
        Write-Host '[DETENIDO/NO ACCESIBLE] MariaDB: 127.0.0.1:3306' -ForegroundColor Yellow
        Write-Host '  Abra XAMPP Control Panel y pulse Start en MySQL. No vuelva a importar schema.sql/seed.sql.'
    }
    if (Test-LocalPort -Port $script:HttpPort) {
        Write-Host '[EN ESCUCHA] API HTTP: 127.0.0.1:18082 (la prueba REST verifica el contenido)'
    } else {
        Write-Host '[DETENIDO] API HTTP: 127.0.0.1:18082'
    }
    if (Test-LocalPort -Port $script:MqttPort) {
        Write-Host '[EN ESCUCHA] MQTT seguro local: 127.0.0.1:1884 (verificar ACL con opcion 4)'
    } else {
        Write-Host '[DETENIDO] MQTT seguro local: 127.0.0.1:1884'
    }
    if (-not $script:Php) { return }
    Write-Host "`nComprobacion REAL de PHP -> PDO -> MariaDB (consulta preparada y UTC):"
    if (-not (Test-LocalPort -Port $script:DbPort)) {
        Write-Host '[PENDIENTE] MariaDB no responde; inicie MySQL en XAMPP.' -ForegroundColor Yellow
        return
    }
    $file = Join-Path $script:RepoRoot 'lab2\sql\check-connection.php'
    & $script:Php $file | Out-Host
    if ($LASTEXITCODE -eq 0) {
        Write-Host '[VERIFICADO] PHP conecto a MariaDB, ejecuto la consulta y comprobo UTC.' -ForegroundColor Green
    } else {
        Write-Host '[FALLO] PDO no conecto. Verifique MySQL en XAMPP y database.local.php (sin mostrar contrasenas).' -ForegroundColor Red
    }
}

function Start-DemoServices {
    Show-Heading '2. ARRANQUE DE API REST Y BROKER MQTT AISLADO'
    Assert-Executable -Path $script:Php -Name 'PHP'
    Assert-Executable -Path $script:Broker -Name 'Mosquitto'
    if (-not (Test-LocalPort -Port $script:DbPort)) {
        throw 'MariaDB no responde en 3306. Abra XAMPP y pulse Start en MySQL; no se inicia una base nueva.'
    }
    if (-not (Test-Path -LiteralPath $script:DbConfig -PathType Leaf)) {
        throw 'Falta lab2/config/database.local.php; no se crearan credenciales.'
    }
    if (-not (Test-LocalPort -Port $script:HttpPort)) {
        Write-Host 'Iniciando PHP con router lab2/public/index.php...'
        $script:PhpProcess = Start-Process -FilePath $script:Php `
            -ArgumentList '-d display_errors=0 -S 127.0.0.1:18082 -t lab2/public lab2/public/index.php' `
            -WorkingDirectory $script:RepoRoot -WindowStyle Hidden -PassThru
        if (Wait-LocalPort -Port $script:HttpPort) {
            Write-Host "[EN ESCUCHA] API HTTP en $script:HttpUrl (PID $($script:PhpProcess.Id))" -ForegroundColor Green
            Write-Host '  Ruta principal: POST /api/v1/telemetry; acceso y respuestas se prueban en opcion 3.'
        } else {
            Write-Host '[FALLO] El servidor no abrio el puerto 18082.' -ForegroundColor Red
        }
    } else {
        Write-Host '[EN USO] 18082. No se inicia otro servidor; la opcion 3 comprobara que la API es correcta.' -ForegroundColor Yellow
    }

    if (-not (Test-Path -LiteralPath $script:BrokerConfig -PathType Leaf) -or
        -not (Test-Path -LiteralPath $script:MqttConfig -PathType Leaf)) {
        Write-Host '[PENDIENTE] MQTT sin configuracion privada: ejecutar setup-mqtt.php segun MQTT_D1.md.' -ForegroundColor Yellow
        return
    }
    if (-not (Test-LocalPort -Port $script:MqttPort)) {
        Write-Host 'Iniciando Mosquitto con listener local 1884, usuarios y permisos ACL...'
        $args = '-c "' + $script:BrokerConfig + '"'
        $script:BrokerProcess = Start-Process -FilePath $script:Broker `
            -ArgumentList $args -WorkingDirectory $script:RepoRoot -WindowStyle Hidden -PassThru
        if (Wait-LocalPort -Port $script:MqttPort) {
            Write-Host "[EN ESCUCHA] Broker MQTT en 127.0.0.1:1884 (PID $($script:BrokerProcess.Id))" -ForegroundColor Green
            Write-Host '  Su autenticacion y restricciones reales se verifican en opcion 4.'
        } else {
            Write-Host '[FALLO] El broker no abrio 1884; revise el log MQTT privado sin publicarlo.' -ForegroundColor Red
        }
    } else {
        Write-Host '[EN USO] 1884. No se inicia otro broker; la opcion 4 comprobara su seguridad.' -ForegroundColor Yellow
    }
    Write-Host 'No se modifica el listener original 1883 ni se abren puertos hacia Internet.'
}

function Invoke-PHPTest {
    param([string] $RelativePath, [string] $Title, [string[]] $Arguments = @())
    Assert-Executable -Path $script:Php -Name 'PHP'
    $file = Join-Path $script:RepoRoot $RelativePath
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw "Falta $RelativePath" }
    Show-Heading $Title
    Write-Host "Ejecutando prueba real: $RelativePath"
    Write-Host '----- Inicio de salida original de la prueba -----' -ForegroundColor DarkCyan
    # IMPORTANTE: Out-Host muestra la salida nativa en pantalla aunque la funcion
    # retorne un bool y el caller guarde/ignore ese bool (problema de la v1).
    & $script:Php $file @Arguments | Out-Host
    $exit = $LASTEXITCODE
    Write-Host '----- Fin de salida original de la prueba -----' -ForegroundColor DarkCyan
    if ($exit -eq 0) {
        Write-Host "[PRUEBA SATISFACTORIA] $Title (codigo de salida 0)" -ForegroundColor Green
        return $true
    }
    Write-Host "[PRUEBA FALLIDA] $Title (codigo de salida $exit)" -ForegroundColor Red
    return $false
}

function Get-TelemetryCount {
    # Query read-only. Return $null if the environment is not ready.
    if (-not $script:Php -or -not (Test-LocalPort -Port $script:DbPort)) { return $null }
    $file = Join-Path $script:RepoRoot 'lab2\scripts\demo-data.php'
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { return $null }
    $value = & $script:Php $file 'count'
    if ($LASTEXITCODE -ne 0) { return $null }
    $n = [long]0
    if ([long]::TryParse(($value -join '').Trim(), [ref]$n)) { return $n }
    return $null
}

function Show-CountChange {
    param($Before)
    $after = Get-TelemetryCount
    if ($null -ne $Before -and $null -ne $after) {
        $change = $after - $Before
        Write-Host "[SQL] Filas telemetry ANTES: $Before | DESPUES: $after | Nuevas filas: $change" -ForegroundColor Cyan
        Write-Host 'Este recuento proviene de SELECT COUNT(*) en la base lab2, no de un valor fijo.'
    } else {
        Write-Host '[AVISO] No se pudieron comparar contadores de MariaDB.' -ForegroundColor Yellow
    }
}

function Show-Data {
    param([ValidateSet('brief','full','audit')][string] $Mode = 'full')
    if (-not (Test-LocalPort -Port $script:DbPort)) {
        Write-Host 'MariaDB no responde: abra XAMPP e inicie MySQL.' -ForegroundColor Yellow
        return
    }
    $descriptions = @{
        brief = 'Evidencia SQL: cantidades por dispositivo y filas mas recientes'
        full = 'Evidencia SQL: tablas, cantidades, mediciones, eventos y configuraciones'
        audit = 'Evidencia MQTT: sucesos de la auditoria privada (campos no sensibles)'
    }
    [void](Invoke-PHPTest -RelativePath 'lab2\scripts\demo-data.php' -Title $descriptions[$Mode] -Arguments @($Mode))
}

function Test-Rest {
    Show-Heading '3. API HTTP: INGESTA, VALIDACION, AUTENTICACION Y CONSULTAS'
    if (-not (Test-LocalPort -Port $script:HttpPort)) {
        Write-Host 'Servidor HTTP no activo. Use primero la opcion 2.' -ForegroundColor Yellow
        return
    }
    $before = Get-TelemetryCount
    Write-Host 'A) POST /api/v1/telemetry: verifica 201 nuevo, 401 token invalido, 422 campos faltantes,'
    Write-Host '   200 reintento duplicado, 409 conflicto, 400 JSON y 403 identidad cruzada.'
    $ok = Invoke-PHPTest -RelativePath 'lab2\tests\c1-http.php' -Title 'HTTP: recibir y validar telemetria' -Arguments @($script:HttpUrl)
    if (-not $ok) {
        Write-Host 'Se omite la segunda suite porque fallo la recepcion de telemetria.' -ForegroundColor Yellow
        return
    }
    Write-Host "`nB) GET /devices; GET /devices/{id}/telemetry; PUT /devices/{id}/config; GET /events."
    Write-Host '   Comprueba filtros, permisos de administrador y dispositivo, paginacion y errores.'
    $ok = Invoke-PHPTest -RelativePath 'lab2\tests\c2-http.php' -Title 'HTTP: consultar dispositivos, historial, configuracion y eventos' -Arguments @($script:HttpUrl)
    if ($ok) {
        Show-CountChange -Before $before
        Write-Host "`nComprobacion independiente de persistencia mediante SELECT en MariaDB:"
        Show-Data -Mode brief
    }
}

function Test-Mqtt {
    Show-Heading '4. MQTT: CREDENCIALES, ACL Y PERSISTENCIA EN MARIADB'
    if (-not (Test-LocalPort -Port $script:MqttPort)) {
        Write-Host 'Broker MQTT seguro no activo en 1884. Use primero la opcion 2.' -ForegroundColor Yellow
        return
    }
    $before = Get-TelemetryCount
    Write-Host 'A) Broker: permite al nodo publicar en su propio topico y rechaza'
    Write-Host '   anonimos, claves incorrectas, escrituras ajenas y escritura del subscriber.'
    $ok = Invoke-PHPTest -RelativePath 'lab2\tests\d1-security.php' -Title 'MQTT: acceso autorizado y permisos por nodo'
    if (-not $ok) {
        Write-Host 'No se ejecuta ingesta MQTT sin ACL verificadas.' -ForegroundColor Red
        return
    }
    Write-Host "`nVerificacion observable: respuestas del broker a publicaciones autorizadas y rechazadas:"
    $ok = Invoke-PHPTest -RelativePath 'lab2\scripts\demo-acl.php' -Title 'MQTT: resultados originales del broker (PUBACK/errores)'
    if (-not $ok) {
        Write-Host 'No se ejecuta ingesta MQTT: los resultados del broker no coinciden con ACL esperadas.' -ForegroundColor Red
        return
    }
    Write-Host "`nB) Publicacion MQTT -> suscriptor PHP -> TelemetryService -> MariaDB."
    Write-Host '   Prueba insercion, duplicados, conflicto, identidad y reanudacion controlada.'
    Write-Host '   La prueba administra su propio suscriptor; no inicie otro simultaneamente.'
    $ok = Invoke-PHPTest -RelativePath 'lab2\tests\d1-mqtt.php' -Title 'MQTT: persistencia de mensajes y recuperacion del suscriptor'
    if ($ok) {
        Show-CountChange -Before $before
        Write-Host "`nRegistros de auditoria generados por el procesamiento MQTT:"
        Show-Data -Mode audit
        Write-Host "`nFilas reales persistidas tras la prueba:"
        Show-Data -Mode brief
    }
}

function Test-Concurrency {
    Show-Heading '5. CUATRO PROCESOS SIMULTANEOS: EVITAR FILAS DUPLICADAS'
    $before = Get-TelemetryCount
    Write-Host 'Cuatro procesos intentan insertar la misma identidad y secuencia.'
    Write-Host 'Se esperan exactamente una creacion y tres duplicados, verificados en MariaDB.'
    $ok = Invoke-PHPTest -RelativePath 'lab2\tests\c1-concurrency.php' -Title 'Prueba SQL de concurrencia e idempotencia'
    if ($ok) { Show-CountChange -Before $before; Show-Data -Mode brief }
}

function Stop-DemoServices {
    Show-Heading '7. DETENCION DE PROCESOS INICIADOS POR ESTE MENU'
    $count = 0
    foreach ($entry in @(
        @{ Name = 'Broker MQTT'; Process = $script:BrokerProcess },
        @{ Name = 'Servidor PHP/HTTP'; Process = $script:PhpProcess }
    )) {
        $process = $entry.Process
        if ($null -ne $process) {
            try {
                $process.Refresh()
                if (-not $process.HasExited) {
                    Stop-Process -Id $process.Id -ErrorAction Stop
                    Write-Host "[DETENIDO] $($entry.Name): PID $($process.Id)" -ForegroundColor Green
                    $count++
                } else {
                    Write-Host "[YA TERMINADO] $($entry.Name): PID $($process.Id)"
                }
            } catch {
                Write-Host "[AVISO] No se pudo detener $($entry.Name). Revise el proceso." -ForegroundColor Yellow
            }
        }
    }
    $script:BrokerProcess = $null
    $script:PhpProcess = $null
    if ($count -eq 0) { Write-Host 'No habia procesos propios activos que detener.' }
    Start-Sleep -Milliseconds 300
    foreach ($entry in @(
        @{ Name = 'Servidor HTTP'; Port = $script:HttpPort },
        @{ Name = 'Broker MQTT'; Port = $script:MqttPort }
    )) {
        if (Test-LocalPort -Port $entry.Port) {
            Write-Host "[EN ESCUCHA] $($entry.Name), puerto $($entry.Port): puede ser otro proceso preexistente." -ForegroundColor Yellow
        } else {
            Write-Host "[SIN ESCUCHA] $($entry.Name), puerto $($entry.Port)" -ForegroundColor Green
        }
    }
    Write-Host 'MariaDB/MySQL de XAMPP no se detiene ni se modifica.'
}

Write-Host "`nLABORATORIO 2 - DEMOSTRACION COMPROBABLE" -ForegroundColor Cyan
Write-Host "Proyecto: $script:RepoRoot"
Write-Host 'Cada prueba muestra la salida original del codigo PHP existente y evidencia SQL.'
Write-Host 'Los mensajes de prueba crean datos nuevos; no equivalen a tres ESP32 fisicos.'

try {
    $quit = $false
    while (-not $quit) {
        Write-Host "`n============== MENU DE DEMOSTRACION ==============" -ForegroundColor Cyan
        Write-Host '1. Verificar PHP, MariaDB, Mosquitto y estado de servicios'
        Write-Host '2. Iniciar servidor HTTP y broker MQTT local con autenticacion'
        Write-Host '3. Demostrar API REST: peticiones, errores, consultas y permisos'
        Write-Host '4. Demostrar MQTT: seguridad por nodo, recepcion y almacenamiento'
        Write-Host '5. Demostrar concurrencia: cuatro procesos, una sola fila'
        Write-Host '6. Consultar registros reales y auditoria MQTT (solo lectura)'
        Write-Host '7. Detener solo los servicios iniciados desde este menu'
        Write-Host '0. Salir'
        $option = Read-Host 'Elija una opcion'
        try {
            switch ($option) {
                '1' { Show-Environment }
                '2' { Start-DemoServices }
                '3' { Test-Rest }
                '4' { Test-Mqtt }
                '5' { Test-Concurrency }
                '6' { Show-Data -Mode full; Show-Data -Mode audit }
                '7' { Stop-DemoServices }
                '0' { $quit = $true }
                default { Write-Host 'Opcion no valida.' -ForegroundColor Yellow }
            }
        } catch {
            Write-Host "[ERROR] $($_.Exception.Message)" -ForegroundColor Red
        }
    }
} finally {
    # No detener el broker ni PHP de otro programa: solo procesos guardados por este menu.
    if ($null -ne $script:BrokerProcess -or $null -ne $script:PhpProcess) {
        Stop-DemoServices
    }
    Write-Host 'Fin de la demostracion.'
}
