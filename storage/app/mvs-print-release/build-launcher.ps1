$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$release = $PSScriptRoot
$compiler = Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'
if (!(Test-Path -LiteralPath $compiler)) { throw 'Windows .NET Framework C# compiler missing.' }
$version = (Get-Content -LiteralPath (Join-Path $repo 'VERSION') -Raw).Trim()
$source = $release
$assets = $release
$out = Join-Path $repo '_build\mvs-launcher'
New-Item -ItemType Directory -Force -Path $out | Out-Null
$brand = Get-Content -LiteralPath (Join-Path $assets 'branding.json') -Raw | ConvertFrom-Json
if ($brand.gold -ne '#D4AF37' -or $brand.hover -ne '#B1922D') { throw 'Official MVS gold changed; reconcile with resources/css/app.css.' }
$code = 'namespace MvsPrint { internal static class Brand { public const string Gold = "' + $brand.gold + '"; } }'
[IO.File]::WriteAllText((Join-Path $out 'Brand.cs'),$code)
if (!(Get-Content -LiteralPath (Join-Path $source 'Launcher.cs') -Raw).Contains('AssemblyFileVersion("' + $version + '.0")')) { throw 'Launcher version differs from VERSION.' }
Add-Type -AssemblyName System.Drawing
$logo = [Drawing.Image]::FromFile((Join-Path $assets 'logo-mvs-corto.png'))
try {
    $iconBitmap = New-Object Drawing.Bitmap 128,128
    $graphics = [Drawing.Graphics]::FromImage($iconBitmap)
    try {
        $graphics.Clear([Drawing.Color]::White)
        $graphics.InterpolationMode = [Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
        $height = [int](120 * $logo.Height / $logo.Width)
        $graphics.DrawImage($logo,4,[int]((128-$height)/2),120,$height)
        $icon = [Drawing.Icon]::FromHandle($iconBitmap.GetHicon())
        $stream = [IO.File]::Create((Join-Path $out 'mvs-print.ico'))
        try { $icon.Save($stream) } finally { $stream.Dispose(); $icon.Dispose() }
    } finally { $graphics.Dispose(); $iconBitmap.Dispose() }
    $banner = New-Object Drawing.Bitmap 164,314
    $graphics = [Drawing.Graphics]::FromImage($banner)
    try {
        $graphics.Clear([Drawing.Color]::White)
        $brush = New-Object Drawing.SolidBrush ([Drawing.ColorTranslator]::FromHtml($brand.gold))
        try { $graphics.FillRectangle($brush,0,0,164,12) } finally { $brush.Dispose() }
        $graphics.DrawImage($logo,7,94,150,[int](150*$logo.Height/$logo.Width))
        $font = New-Object Drawing.Font 'Segoe UI',14,([Drawing.FontStyle]::Bold)
        try { $graphics.DrawString('MVS Print',$font,[Drawing.Brushes]::Black,25,225) } finally { $font.Dispose() }
        $banner.Save((Join-Path $out 'welcome.bmp'),[Drawing.Imaging.ImageFormat]::Bmp)
    } finally { $graphics.Dispose(); $banner.Dispose() }
} finally { $logo.Dispose() }
$references = @('/r:System.Windows.Forms.dll','/r:System.Drawing.dll','/r:System.Management.dll','/r:System.Web.Extensions.dll')
$files = @((Join-Path $source 'Launcher.cs'), (Join-Path $source 'Trust.cs'), (Join-Path $out 'Brand.cs'))
& $compiler /nologo /warnaserror+ /target:winexe /platform:x64 /optimize+ "/out:$out\MVS Print.exe" "/win32icon:$out\mvs-print.ico" "/win32manifest:$source\launcher.manifest" @references @files
if ($LASTEXITCODE -ne 0) { throw 'Launcher compilation failed.' }
& $compiler /nologo /warnaserror+ /target:exe /platform:x64 "/out:$out\LauncherTests.exe" /main:LauncherTests @references @files (Join-Path $release 'LauncherTests.cs')
if ($LASTEXITCODE -ne 0) { throw 'Launcher test compilation failed.' }
& (Join-Path $out 'LauncherTests.exe')
if ($LASTEXITCODE -ne 0) { throw 'Launcher tests failed.' }
Write-Output 'LAUNCHER_BUILD=PASS'
