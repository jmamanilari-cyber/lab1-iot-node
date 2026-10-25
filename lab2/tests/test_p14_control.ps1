# Pruebas de funciones extraidas por AST; NO ejecutar el cuerpo de p14-control.ps1.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$tokens = $null; $parseErrors = $null
$ast = [Management.Automation.Language.Parser]::ParseFile((Join-Path $PSScriptRoot '..\scripts\p14-control.ps1'), [ref]$tokens, [ref]$parseErrors)
if ($parseErrors.Count) { throw 'Sintaxis PS invalida.' }
foreach ($name in @('Reject','Own-Rule','Remove-OwnRule','Assert-D2Identity')) {
    $functionAst = $ast.Find({param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq $name}, $true)
    Invoke-Expression $functionAst.Extent.Text
}
$script:removed = 0
$script:fakeRule = $null
$script:fakePort = '8443'
$script:fakeAddresses = @('192.168.1.2','192.168.1.3')
$script:fakeProgram = 'C:\xampp\apache\bin\httpd.exe'
$apache = 'C:\xampp\apache\bin\httpd.exe'
function Get-NetFirewallRule { param($PolicyStore,$Name,$ErrorAction) return $script:fakeRule }
function Get-NetFirewallPortFilter { process { [pscustomobject]@{Protocol='TCP'; LocalPort=$script:fakePort; RemotePort='Any'} } }
function Get-NetFirewallAddressFilter { process { [pscustomobject]@{LocalAddress='Any'; RemoteAddress=$script:fakeAddresses} } }
function Get-NetFirewallApplicationFilter { process { [pscustomobject]@{Program=$script:fakeProgram} } }
function Remove-NetFirewallRule { process { $script:removed++; $script:fakeRule=$null } }
function Assert-Rejected([scriptblock]$body) {
    $rejected=$false
    try { & $body } catch { $rejected=$true }
    if (-not $rejected) { throw 'No rechazo identidad ajena.' }
}
$plan = [pscustomobject]@{name='Lab2-P14-B-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; clients=@('192.168.1.2','192.168.1.3')}
Remove-OwnRule $plan
if ($script:removed -ne 0) { throw 'Elimino regla ausente.' }
$script:fakeRule = [pscustomobject]@{Description=$plan.name; Direction='Inbound'; Action='Block'; Enabled='True'; Profile='Any'}
Remove-OwnRule $plan
if ($script:removed -ne 1) { throw 'No elimino regla propia.' }
$script:fakeRule = [pscustomobject]@{Description='ajena'; Direction='Inbound'; Action='Block'; Enabled='True'; Profile='Any'}
Assert-Rejected { Remove-OwnRule $plan }
if ($script:removed -ne 1) { throw 'Elimino regla ajena.' }
$script:fakeRule.Description=$plan.name
$script:fakePort='443'
Assert-Rejected { Remove-OwnRule $plan }
$script:fakePort='8443'
$script:fakeAddresses=@('Any')
Assert-Rejected { Remove-OwnRule $plan }
$script:fakeAddresses=$plan.clients
$script:fakeProgram='C:\otro.exe'
Assert-Rejected { Remove-OwnRule $plan }
if ($script:removed -ne 1) { throw 'Elimino regla con filtros distintos.' }
$identity = [pscustomobject]@{pid=123; created_utc='2026-10-24T00:00:00Z'}
$d2plan = [pscustomobject]@{original_identity=@($identity)}
$state = [pscustomobject]@{d2=[pscustomobject]@{verified=$true; windows_service_owned=$false; identity=@($identity)}}
Assert-D2Identity $d2plan $state
$state.d2.windows_service_owned=$true
Assert-Rejected { Assert-D2Identity $d2plan $state }
$state.d2.windows_service_owned=$false
$state.d2.identity=@([pscustomobject]@{pid=123; created_utc='2026-10-24T00:01:00Z'})
Assert-Rejected { Assert-D2Identity $d2plan $state }
$state.d2.identity=@([pscustomobject]@{pid=456; created_utc=$identity.created_utc})
Assert-Rejected { Assert-D2Identity $d2plan $state }
$state.d2.verified=$false
Assert-Rejected { Assert-D2Identity $d2plan $state }
Write-Output 'PASS: 11 comprobaciones con mocks (reglas propias/ajenas, filtros e identidad D2). Ninguna accion Windows real.'
