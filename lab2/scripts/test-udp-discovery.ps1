# Prueba manual: conectar laptop y ESP32 al mismo hotspot Android de 2,4 GHz.
# Ejecutar: powershell -NoProfile -File .\scripts\test-udp-discovery.ps1
# Detener con Ctrl+C. No modifica el firewall.
[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$port = 37020
$udp = [System.Net.Sockets.UdpClient]::new([System.Net.Sockets.AddressFamily]::InterNetwork)

try {
    $udp.Client.Bind([System.Net.IPEndPoint]::new([System.Net.IPAddress]::Any, $port))
    Write-Host "Escuchando UDP IPv4 en el puerto $port. Detener con Ctrl+C."
    Write-Host 'Si Windows solicita acceso de red, permitirlo para la red del hotspot.'

    while ($true) {
        # Espera breve en PowerShell para que Ctrl+C pueda ejecutar finally.
        $pending = $udp.ReceiveAsync()
        while (-not $pending.IsCompleted) {
            Start-Sleep -Milliseconds 100
        }
        $packet = $pending.GetAwaiter().GetResult()
        $request = [System.Text.Encoding]::ASCII.GetString($packet.Buffer)
        if ($request -cmatch '\AUDP_DISCOVERY_TEST_V1 ([0-9A-F]{8})\z') {
            $reply = [System.Text.Encoding]::ASCII.GetBytes("UDP_DISCOVERY_REPLY_V1 $($Matches[1])")
            $null = $udp.Send($reply, $reply.Length, $packet.RemoteEndPoint)
            Write-Host "Solicitud de $($packet.RemoteEndPoint); respuesta unicast enviada."
        }
    }
}
finally {
    $udp.Dispose()
    Write-Host 'Socket UDP cerrado.'
}
