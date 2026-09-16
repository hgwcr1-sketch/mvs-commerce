param([string]$QzVersion = '2.2.6', [string]$MvsVersion = '1.0.2')
$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
if ($QzVersion -ne '2.2.6') { throw 'Only QZ 2.2.6 has been validated.' }
if ($MvsVersion -ne (Get-Content -LiteralPath (Join-Path $repo 'VERSION') -Raw).Trim()) { throw 'VERSION mismatch.' }
$compiler = 'C:\Program Files (x86)\NSIS\makensis.exe'
if (!(Test-Path -LiteralPath $compiler)) { throw 'NSIS compiler missing.' }
$source = $PSScriptRoot
$certPath = Join-Path $source 'mvs-public-certificate.crt'
$certText = Get-Content -LiteralPath $certPath -Raw
if ($certText.Contains('PRIVATE KEY') -or $certText -notmatch '-----BEGIN CERTIFICATE-----([\s\S]+?)-----END CERTIFICATE-----') { throw 'Public certificate required.' }
$der = [Convert]::FromBase64String($Matches[1])
$cert = New-Object Security.Cryptography.X509Certificates.X509Certificate2 -ArgumentList @(,$der)
if ($cert.NotAfter -le (Get-Date) -or $cert.NotBefore -gt (Get-Date)) { throw 'Expired certificate.' }
$qz = Join-Path $repo '_build\qz-tray\out\qz-tray-2.2.6-x86_64.exe'
if (!(Test-Path -LiteralPath $qz)) { throw 'Embedded QZ installer missing.' }
& (Join-Path $PSScriptRoot 'build-launcher.ps1')
if ($LASTEXITCODE -ne 0) { throw 'Launcher validation failed.' }
# Preserve QZ binaries. Recompile its native NSIS packaging without developer
# signing examples containing a public demonstration private key.
$qzScript = Join-Path $repo '_build\qz-tray\out\build\windows-installer.nsi'
$qzPrepared = Join-Path $repo '_build\qz-tray\out\build\windows-installer-mvs.nsi'
$qz = Join-Path $repo '_build\qz-tray\out\qz-tray-2.2.6-mvs-x86_64.exe'
$qzText = [IO.File]::ReadAllText($qzScript)
$qzText = [regex]::Replace($qzText, '(?m)^OutFile .*$', 'OutFile "' + $qz + '"')
$qzText = $qzText.Replace('File /r "', 'File /r /x sign-message.js /x sign-message.vue.js "')
$qzText = $qzText.Replace('VIAddVersionKey "ProductVersion" "2.2.6"', "VIAddVersionKey `"ProductVersion`" `"2.2.6`"`r`nVIAddVersionKey `"FileVersion`" `"2.2.6.0`"")
# QZ copies its existing uninstall.exe; generating unused uninstall pages is unnecessary.
$qzText = [regex]::Replace($qzText, '(?m)^!insertmacro MUI_UNPAGE_(CONFIRM|INSTFILES)\s*$', '')
[IO.File]::WriteAllText($qzPrepared,$qzText)
& $compiler $qzPrepared
if ($LASTEXITCODE -ne 0) { throw 'QZ packaging failed.' }
$dist = Join-Path $repo 'dist'
New-Item -ItemType Directory -Force -Path $dist | Out-Null
$output = Join-Path $dist 'MVS-Print-Setup.exe'
if (Test-Path -LiteralPath $output) {
    $previous = Join-Path $dist ('MVS-Print-Setup.previous-' + (Get-FileHash -LiteralPath $output -Algorithm SHA256).Hash.Substring(0,12) + '.exe')
    if (!(Test-Path -LiteralPath $previous)) { Copy-Item -LiteralPath $output -Destination $previous }
}
Push-Location $source
try {
    & $compiler "/DMVS_VERSION=$MvsVersion" 'mvs-print-installer.nsi'
    if ($LASTEXITCODE -ne 0) { throw 'NSIS build failed.' }
} finally { Pop-Location }
if (!(Test-Path -LiteralPath $output)) { throw 'Build output missing.' }
$info = (Get-Item -LiteralPath $output).VersionInfo
if ($info.ProductVersion -ne $MvsVersion) { throw 'EXE version mismatch.' }
$metadata = [ordered]@{
    version = $MvsVersion
    qz_version = $QzVersion
    file = 'MVS-Print-Setup.exe'
    size = (Get-Item -LiteralPath $output).Length
    sha256 = (Get-FileHash -LiteralPath $output -Algorithm SHA256).Hash.ToLowerInvariant()
    public_certificate_sha256 = (Get-FileHash -LiteralPath $certPath -Algorithm SHA256).Hash.ToLowerInvariant()
    qz_installer_sha256 = (Get-FileHash -LiteralPath $qz -Algorithm SHA256).Hash.ToLowerInvariant()
    launcher_size = (Get-Item -LiteralPath (Join-Path $repo '_build\mvs-launcher\MVS Print.exe')).Length
    launcher_sha256 = (Get-FileHash -LiteralPath (Join-Path $repo '_build\mvs-launcher\MVS Print.exe') -Algorithm SHA256).Hash.ToLowerInvariant()
}
$metadata | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $dist 'release.json') -Encoding UTF8
$metadata | ConvertTo-Json
