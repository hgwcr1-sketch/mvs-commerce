$ErrorActionPreference = 'Stop'
$destination = 'C:\MVSCommerce\mvs-print-installer'
$staging = $PSScriptRoot
foreach ($directory in @('launcher','assets','tests')) {
    New-Item -ItemType Directory -Force -Path (Join-Path $destination $directory) | Out-Null
}
$files = @{
    'README.md'='README.md'
    'ATTRIBUTION.txt'='licenses\ATTRIBUTION.txt'
    'mvs-print-installer.nsi'='installer\mvs-print-installer.nsi'
    'mvs-public-certificate.pem'='installer\mvs-public-certificate.crt'
    'build-installer.ps1'='scripts\build-installer.ps1'
    'build-launcher.ps1'='scripts\build-launcher.ps1'
    'build.ps1'='scripts\build.ps1'
    'Launcher.cs'='launcher\Launcher.cs'
    'Trust.cs'='launcher\Trust.cs'
    'launcher.manifest'='launcher\launcher.manifest'
    'LauncherTests.cs'='tests\LauncherTests.cs'
    'Test-Installer.ps1'='tests\Test-Installer.ps1'
    'branding.json'='assets\branding.json'
    'logo-mvs-corto.png'='assets\logo-mvs-corto.png'
}
foreach ($entry in $files.GetEnumerator()) {
    $target = [IO.Path]::GetFullPath((Join-Path $destination $entry.Value))
    if (!$target.StartsWith($destination+'\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Path outside installer repository' }
    Copy-Item -LiteralPath (Join-Path $staging $entry.Key) -Destination $target -Force
}
[IO.File]::WriteAllText((Join-Path $destination 'VERSION'),"1.0.1`r`n")
$license = Join-Path $destination 'installer\license.txt'
$text = [IO.File]::ReadAllText($license).Replace('MVS Print 1.0.0','MVS Print 1.0.1')
[IO.File]::WriteAllText($license,$text)
Write-Output 'Installer sources updated; prior source copies preserved in workspace storage.'
