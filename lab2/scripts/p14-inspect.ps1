# Inspeccion solamente: no inicia ni detiene procesos ni cambia configuraciones.
[CmdletBinding()]
param([string]$XamppRoot = 'C:\xampp', [string]$Broker = 'C:\Program Files\mosquitto\mosquitto.exe')
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
try {
    $listeners = @(Get-NetTCPConnection -State Listen -ErrorAction Stop)
    $processes = @(Get-CimInstance Win32_Process -ErrorAction Stop)
    $services = @(Get-CimInstance Win32_Service -ErrorAction Stop)
    $apacheExe = [IO.Path]::GetFullPath((Join-Path $XamppRoot 'apache\bin\httpd.exe'))
    $apacheConfig = [IO.Path]::GetFullPath((Join-Path $XamppRoot 'apache\conf\httpd.conf'))
    $apacheSockets = @($listeners | Where-Object LocalPort -eq 8443)
    $apacheIds = @($apacheSockets | Select-Object -ExpandProperty OwningProcess -Unique)
    $apacheVerified = $apacheIds.Count -gt 0
    foreach ($ownerId in $apacheIds) {
        $p = @($processes | Where-Object ProcessId -eq $ownerId) | Select-Object -First 1
        if (-not $p -or $p.ExecutablePath -ine $apacheExe -or -not $p.CommandLine) { $apacheVerified = $false; continue }
        $tokens = @([regex]::Matches($p.CommandLine, '(?:"[^"]*"|[^\s"]+)+') | ForEach-Object { $_.Value.Replace('"','') })
        for ($i = 1; $i -lt $tokens.Count; $i++) {
            switch ($tokens[$i]) {
                '-f' { $i++; if ($i -ge $tokens.Count -or -not [IO.Path]::IsPathRooted($tokens[$i]) -or [IO.Path]::GetFullPath($tokens[$i]) -ine $apacheConfig) { $apacheVerified = $false } }
                '-d' { $i++; if ($i -ge $tokens.Count -or -not [IO.Path]::IsPathRooted($tokens[$i]) -or [IO.Path]::GetFullPath($tokens[$i]) -ine [IO.Path]::GetFullPath((Join-Path $XamppRoot 'apache'))) { $apacheVerified = $false } }
                '-k' { $i++; if ($i -ge $tokens.Count -or $tokens[$i] -ne 'runservice') { $apacheVerified = $false } }
                default { $apacheVerified = $false }
            }
        }
    }
    $apachePorts = @($listeners | Where-Object { $_.OwningProcess -in $apacheIds } | Select-Object -ExpandProperty LocalPort -Unique)
    $d2Sockets = @($listeners | Where-Object LocalPort -in @(1884,8883))
    $d2Ids = @($d2Sockets | Select-Object -ExpandProperty OwningProcess -Unique)
    $d2Verified = $false
    if ($d2Sockets.Count) {
        try {
            # Reutilizar validacion de archivos, sin ligar D2 a puertos de un broker ajeno.
            & (Join-Path $PSScriptRoot 'mqtt-d2.ps1') -Mode Validate -Broker $Broker -OpenSsl (Join-Path $XamppRoot 'apache\bin\openssl.exe') *> $null
            $d2Config = Join-Path (Split-Path $PSScriptRoot -Parent) 'config\mqtt-d2\broker.conf'
            if ($d2Ids.Count -eq 1 -and $d2Sockets.Count -eq 2) {
                $p = @($processes | Where-Object ProcessId -eq $d2Ids[0]) | Select-Object -First 1
                if ($p -and $p.ExecutablePath -ieq [IO.Path]::GetFullPath($Broker) -and
                    $p.CommandLine -match '^\s*(?:"[^"]+"|\S+)\s+-c\s+(?:"(?<config>[^"]+)"|(?<config>\S+))\s*$') {
                    $d2Verified = $Matches.config -ieq $d2Config -and
                        (Get-Item -LiteralPath $d2Config).LastWriteTimeUtc -le $p.CreationDate.ToUniversalTime() -and
                        @($listeners | Where-Object OwningProcess -eq $p.ProcessId).Count -eq 2 -and
                        @($d2Sockets | Where-Object { $_.LocalPort -eq 1884 -and $_.LocalAddress -eq '127.0.0.1' }).Count -eq 1 -and
                        @($d2Sockets | Where-Object { $_.LocalPort -eq 8883 -and $_.LocalAddress -eq '0.0.0.0' }).Count -eq 1
                }
            }
        } catch { $d2Verified = $false }
    }
    $serviceOwned = @($services | Where-Object { $_.ProcessId -ne 0 -and $_.ProcessId -in $d2Ids }).Count -gt 0
    $d2Identity = @($processes | Where-Object ProcessId -in $d2Ids | ForEach-Object {
        @{pid=[int]$_.ProcessId; created_utc=$_.CreationDate.ToUniversalTime().ToString('o')}
    })
    $sockets = @($listeners | Where-Object LocalPort -in @(8443,18082,3306,1884,8883,1883,9883) |
        ForEach-Object { @{port=[int]$_.LocalPort; pid=[int]$_.OwningProcess} })
    $root = Split-Path $PSScriptRoot -Parent
    $phpExe = Join-Path $XamppRoot 'php\php.exe'
    $subscriberScript = Join-Path $PSScriptRoot 'mqtt-subscriber.php'
    $subscriberParents = @($processes | Where-Object {
        if ($_.ExecutablePath -ine $phpExe -or -not $_.CommandLine) { return $false }
        $a = @([regex]::Matches($_.CommandLine, '(?:"[^"]*"|[^\s"]+)+') | ForEach-Object { $_.Value.Replace('"','') })
        return ($a.Count -in @(2,3) -and $a[1] -ieq $subscriberScript -and
            ($a.Count -eq 2 -or $a[2] -match '^--stop-file=[a-zA-Z0-9_.-]{1,100}$'))
    })
    $transports = @($processes | Where-Object {
        $_.ParentProcessId -in @($subscriberParents | ForEach-Object ProcessId) -and
        $_.ExecutablePath -ieq (Join-Path (Split-Path $Broker -Parent) 'mosquitto_sub.exe')
    })
    $verifiedDependencies = @{}
    foreach ($port in @(18082,3306)) {
        $owners = @($listeners | Where-Object LocalPort -eq $port | Select-Object -ExpandProperty OwningProcess -Unique)
        $p = @($processes | Where-Object ProcessId -in $owners)
        $valid = $p.Count -eq 1 -and [bool]$p[0].CommandLine
        if ($valid -and $port -eq 18082) {
            $a = @([regex]::Matches($p[0].CommandLine, '(?:"[^"]*"|[^\s"]+)+') | ForEach-Object { $_.Value.Replace('"','') })
            $expected = @($phpExe,'-d','display_errors=0','-S','127.0.0.1:18082','-t',(Join-Path $root 'public'),(Join-Path $root 'public\index.php'))
            $valid = $p[0].ExecutablePath -ieq $phpExe -and ($a -join "`n") -ieq ($expected -join "`n")
        } elseif ($valid) {
            $a = @([regex]::Matches($p[0].CommandLine, '(?:"[^"]*"|[^\s"]+)+') | ForEach-Object { $_.Value.Replace('"','') })
            $valid = $p[0].ExecutablePath -ieq (Join-Path $XamppRoot 'mysql\bin\mysqld.exe') -and
                $a.Count -in @(2,3) -and $a[1] -ieq ('--defaults-file=' + (Join-Path $XamppRoot 'mysql\bin\my.ini')) -and
                ($a.Count -eq 2 -or $a[2] -eq '--standalone')
        }
        $verifiedDependencies[[string]$port] = $valid
    }
    @{status='OK'; utc=[DateTime]::UtcNow.ToString('o'); sockets=$sockets;
        apache=@{verified=$apacheVerified; pids=$apacheIds; ports=$apachePorts; other_ports=@($apachePorts | Where-Object { $_ -ne 8443 }); dedicated_not_proven=$true};
        d2=@{verified=$d2Verified; pids=$d2Ids; identity=$d2Identity; windows_service_owned=$serviceOwned; ports=@($d2Sockets | Select-Object -ExpandProperty LocalPort -Unique)};
        dependencies=$verifiedDependencies;
        subscriber=@{verified=($subscriberParents.Count -eq 1 -and $transports.Count -eq 1); pids=@($subscriberParents | ForEach-Object ProcessId)}
    } | ConvertTo-Json -Depth 7 -Compress
} catch {
    @{status='INCOMPLETO'; reason='Windows no permite verificar procesos/sockets; no realizar interrupciones sin identificar la instancia.'} | ConvertTo-Json -Compress
    exit 2
}
