$ErrorActionPreference = 'Stop'
$trustTestRoot = Join-Path $PSScriptRoot 'trust-test'
New-Item -ItemType Directory -Force -Path $trustTestRoot | Out-Null
[IO.File]::WriteAllText((Join-Path $trustTestRoot 'qz-tray.exe'),'test placeholder')
$properties = Join-Path $trustTestRoot 'qz-tray.properties'
$initial = "existing.setting=true`r`nauthcert.override=C:/existing/public.pem`r`n"
[IO.File]::WriteAllText($properties,$initial)
$certificate = Join-Path $PSScriptRoot 'mvs-public-certificate.pem'
& (Join-Path $PSScriptRoot 'configure-trust.ps1') -QzPath $trustTestRoot -CertificatePath $certificate
$first = [IO.File]::ReadAllText($properties)
if (!$first.Contains('mvs-public-certificate.pem')) { throw 'Trust not added' }
& (Join-Path $PSScriptRoot 'configure-trust.ps1') -QzPath $trustTestRoot -CertificatePath $certificate
if ($first -ne [IO.File]::ReadAllText($properties)) { throw 'Trust not idempotent' }
& (Join-Path $PSScriptRoot 'configure-trust.ps1') -QzPath $trustTestRoot -CertificatePath $certificate -Remove
if ([IO.File]::ReadAllText($properties) -ne $initial) { throw 'Other trust not preserved' }
Write-Output 'TRUST_TEST_PASS: add, repeat, remove preserve existing trust'
