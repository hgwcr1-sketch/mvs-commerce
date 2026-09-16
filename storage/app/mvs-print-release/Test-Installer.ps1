param([string]$Repository = (Split-Path -Parent $PSScriptRoot))
$ErrorActionPreference = 'Stop'
$nsi = Get-Content -LiteralPath (Join-Path $Repository 'installer/mvs-print-installer.nsi') -Raw
function Require([bool]$Condition,[string]$Message) { if (!$Condition) { throw $Message }; Write-Output "PASS $Message" }
Require ($nsi.Contains('InstallDir "$PROGRAMFILES64\MVS Print"')) 'Default x64 Program Files directory'
$assignments = [regex]::Matches($nsi,'(?m)^\s*StrCpy \$INSTDIR (.+)$')
Require ($assignments.Count -eq 1 -and $assignments[0].Groups[1].Value.Trim() -eq '"$PROGRAMFILES64\MVS Print"') 'No alternate INSTDIR assignment'
Require ($nsi.Contains('SetRegView 64') -and $nsi.Contains('CreateDirectory "$INSTDIR"')) '64-bit registry and automatic directory creation'
Require ($nsi.Contains('File "..\_build\mvs-launcher\MVS Print.exe"')) 'Own launcher included'
Require ($nsi.Contains('CreateShortCut "$DESKTOP\MVS Print.lnk" "$INSTDIR\MVS Print.exe"')) 'Desktop target is own launcher'
Require ($nsi.Contains('CreateShortCut "$SMPROGRAMS\MVS Print\MVS Print.lnk" "$INSTDIR\MVS Print.exe"')) 'Start menu target is own launcher'
Require ($nsi.Contains('Delete "$INSTDIR\version"')) 'Uninstall removes current version file'
$launcher = Join-Path $Repository '_build/mvs-launcher/MVS Print.exe'
$bytes = [IO.File]::ReadAllBytes($launcher)
$pe = [BitConverter]::ToInt32($bytes,60)
Require ([BitConverter]::ToUInt16($bytes,$pe+4) -eq 0x8664) 'Launcher PE x64'
Require ([BitConverter]::ToUInt16($bytes,$pe+24+68) -eq 2) 'Launcher GUI subsystem without console'
$sourceRoots = @('installer','launcher','assets','licenses')
$files = @($sourceRoots | ForEach-Object { Get-ChildItem -LiteralPath (Join-Path $Repository $_) -File -Recurse })
$forbidden = @($files | Where-Object { $_.Name -like '.env*' -or $_.Extension -in @('.pem','.key','.p12','.pfx') })
Require ($forbidden.Count -eq 0) 'No forbidden secret file extensions in product sources'
$textFiles = $files | Where-Object { $_.Extension -in @('.cs','.crt','.txt','.json','.nsi','.manifest') }
foreach ($file in $textFiles) {
    $content = Get-Content -LiteralPath $file.FullName -Raw
    Require ($content -notmatch '-----BEGIN (RSA |EC |OPENSSH |ENCRYPTED )?PRIVATE KEY-----') ('No private key in '+$file.Name)
}
$qz = Get-Content -LiteralPath (Join-Path $Repository '_build/qz-tray/out/build/windows-installer-mvs.nsi') -Raw
Require ($qz.Contains('/x sign-message.js /x sign-message.vue.js')) 'Developer signing examples excluded from QZ package'
$release = Get-Content -LiteralPath (Join-Path $Repository 'dist/release.json') -Raw | ConvertFrom-Json
Require ((Get-FileHash -LiteralPath (Join-Path $Repository 'dist/MVS-Print-Setup.exe')).Hash -eq $release.sha256) 'Installer release hash matches'
Require ((Get-FileHash -LiteralPath $launcher).Hash -eq $release.launcher_sha256) 'Launcher release hash matches'
Write-Output 'Static package checks do not replace an actual Windows installation or physical printer approval.'
