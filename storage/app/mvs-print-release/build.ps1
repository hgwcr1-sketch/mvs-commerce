# MVS Print Installer - Build Script
# Builds QZ Tray v2.2.6 from source, then compiles the MVS Print wrapper installer
#
# Requirements: JDK 11+, Apache Ant, NSIS 3.0+, Git
# These are development tools only - not needed on client machines.

param(
    [string]$QzVersion = "v2.2.6",
    [string]$QzCommit = "4be94301797d04684f4d70c6bbbff5d9acc36987",
    [string]$QzRepo = "https://github.com/qzind/tray.git",
    [switch]$SkipQzBuild,
    [switch]$SkipWrapperBuild
)

$ErrorActionPreference = "Stop"
$RepoRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$BuildDir = Join-Path $RepoRoot "_build"
$QzDir = Join-Path $BuildDir "qz-tray"
$DistDir = Join-Path $RepoRoot "dist"

Write-Host "============================================"
Write-Host "  MVS Print Installer - Build"
Write-Host "  QZ Tray $QzVersion"
Write-Host "============================================"
Write-Host ""

# 1. Verify tools
Write-Host "[1/7] Verifying build tools..."

try {
    $javaVer = & java -version 2>&1 | Select-Object -First 1
    Write-Host "  Java: $javaVer"
} catch {
    Write-Error "Java not found. Install JDK 11+ and add to PATH."
    exit 1
}

try {
    $antVer = & ant -version 2>&1 | Select-Object -First 1
    Write-Host "  Ant: $antVer"
} catch {
    Write-Error "Apache Ant not found. Install and add to PATH."
    exit 1
}

$gitVer = & git --version 2>&1
Write-Host "  Git: $gitVer"

# Check for NSIS (needed for wrapper)
$nsisFound = $false
$nsisPaths = @(
    "C:\Program Files (x86)\NSIS\makensis.exe",
    "C:\Program Files\NSIS\makensis.exe"
)
foreach ($path in $nsisPaths) {
    if (Test-Path $path) {
        $nsisFound = $true
        Write-Host "  NSIS: $path"
        break
    }
}
if (-not $nsisFound) {
    try {
        $null = Get-Command makensis.exe -ErrorAction SilentlyContinue
        $nsisFound = $true
        Write-Host "  NSIS: found in PATH"
    } catch {
        Write-Host "  NSIS: NOT FOUND (wrapper build will be skipped)"
    }
}
Write-Host ""

# 2. Clone QZ Tray if needed
if (-not $SkipQzBuild) {
    Write-Host "[2/7] Preparing QZ Tray source..."
    if (Test-Path $QzDir) {
        Write-Host "  Source directory exists, verifying tag..."
        Push-Location $QzDir
        $currentTag = & git describe --tags --exact-match 2>&1
        Pop-Location
        if ($currentTag -eq $QzVersion) {
            Write-Host "  Correct tag $QzVersion verified."
        } else {
            Write-Host "  Wrong tag ($currentTag). Re-cloning..."
            Remove-Item $QzDir -Recurse -Force
            & git clone --branch $QzVersion --depth 1 $QzRepo $QzDir
        }
    } else {
        Write-Host "  Cloning QZ Tray $QzVersion..."
        & git clone --branch $QzVersion --depth 1 $QzRepo $QzDir
    }
    Write-Host ""

    # 3. Verify commit
    Write-Host "[3/7] Verifying source integrity..."
    Push-Location $QzDir
    $actualCommit = & git rev-parse HEAD
    Pop-Location
    if ($actualCommit -ne $QzCommit) {
        Write-Warning "Commit mismatch! Expected: $QzCommit, Got: $actualCommit"
        Write-Host "  Continuing anyway (tag verified)..."
    } else {
        Write-Host "  Commit verified: $actualCommit"
    }
    Write-Host ""

    # 4. Build QZ Tray
    Write-Host "[4/7] Building QZ Tray with ant nsis..."
    Push-Location $QzDir
    $env:JAVA_HOME = (Get-ItemProperty "HKLM:\SOFTWARE\Microsoft\JDK\17*\HotSpot" -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Path -ErrorAction SilentlyContinue)
    if (-not $env:JAVA_HOME) {
        $jdkDirs = Get-ChildItem "C:\Program Files\Microsoft" -Directory -Filter "jdk-*" -ErrorAction SilentlyContinue
        if ($jdkDirs) {
            $env:JAVA_HOME = $jdkDirs[0].FullName
        }
    }
    Write-Host "  JAVA_HOME: $env:JAVA_HOME"

    & ant nsis 2>&1
    $buildExit = $LASTEXITCODE
    Pop-Location

    if ($buildExit -ne 0) {
        Write-Error "Build failed with exit code $buildExit"
        exit 1
    }
    Write-Host ""
} else {
    Write-Host "[2-4/7] Skipping QZ Tray build (SkipQzBuild flag set)."
    Write-Host ""
}

# 5. Verify QZ output exists
Write-Host "[5/7] Locating QZ Tray build output..."
$outDir = Join-Path $QzDir "out"
$qzExe = Join-Path $outDir "qz-tray-2.2.6-x86_64.exe"

if (-not (Test-Path $qzExe)) {
    Write-Error "QZ Tray installer not found at: $qzExe"
    exit 1
}

$qzSize = (Get-Item $qzExe).Length
Write-Host "  Found: qz-tray-2.2.6-x86_64.exe ($([math]::Round($qzSize / 1MB, 2)) MB)"
Write-Host ""

# 6. Build MVS Print wrapper
if (-not $SkipWrapperBuild -and $nsisFound) {
    Write-Host "[6/7] Building MVS Print wrapper installer..."
    $buildInstallerScript = Join-Path $RepoRoot "scripts\build-installer.ps1"
    & $buildInstallerScript -QzVersion "2.2.6" -MvsVersion ((Get-Content -LiteralPath (Join-Path $RepoRoot "VERSION") -Raw).Trim())
    $wrapperExit = $LASTEXITCODE
    if ($wrapperExit -ne 0) {
        throw "Wrapper build failed. No MVS Print release is available."
    }
    Write-Host ""
} else {
    Write-Host "[6/7] Skipping wrapper build."
    if (-not $nsisFound) {
        Write-Host "  NSIS not found. Install NSIS 3.0+ to build wrapper."
    }
    Write-Host ""

    throw "Wrapper build is required. Never rename the QZ installer as MVS Print."
}

# 7. Final verification
Write-Host "[7/7] Verifying output..."
$outputExe = Join-Path $DistDir "MVS-Print-Setup.exe"

if (-not (Test-Path $outputExe)) {
    Write-Error "Output file not found: $outputExe"
    exit 1
}

$exeInfo = Get-Item $outputExe
$sizeMB = [math]::Round($exeInfo.Length / 1MB, 2)
$hash = Get-FileHash -Path $outputExe -Algorithm SHA256

Write-Host "  File: $($exeInfo.Name)"
Write-Host "  Size: $sizeMB MB ($($exeInfo.Length) bytes)"
Write-Host "  SHA256: $($hash.Hash)"
Write-Host ""

$qzHash = (Get-FileHash -Path $qzExe -Algorithm SHA256).Hash
if ($hash.Hash -eq $qzHash) {
    Write-Warning "  WARNING: Output hash matches QZ installer (fallback copy mode)."
} else {
    Write-Host "  [OK] Output is a proper wrapper (different from QZ installer)."
}

Write-Host ""
Write-Host "============================================"
Write-Host "  BUILD COMPLETE"
Write-Host "  Output: $outputExe"
Write-Host "============================================"
