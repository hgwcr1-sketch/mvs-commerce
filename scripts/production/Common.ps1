Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Resolve-MvsPath {
    param([Parameter(Mandatory)][string]$Path)

    return [System.IO.Path]::GetFullPath($Path.Trim().Trim('"'))
}

function Get-MvsEnvironment {
    param([Parameter(Mandatory)][string]$ApplicationPath)

    $envPath = Join-Path $ApplicationPath '.env'
    if (-not (Test-Path -LiteralPath $envPath -PathType Leaf)) {
        throw "No existe el archivo de entorno: $envPath"
    }

    $values = @{}
    foreach ($line in Get-Content -LiteralPath $envPath) {
        $trimmed = $line.Trim()
        if ($trimmed.Length -eq 0 -or $trimmed.StartsWith('#') -or -not $trimmed.Contains('=')) {
            continue
        }

        $parts = $trimmed.Split('=', 2)
        $values[$parts[0].Trim()] = $parts[1].Trim().Trim('"').Trim("'")
    }

    return $values
}

function Assert-MvsProductionEnvironment {
    param([Parameter(Mandatory)][hashtable]$Environment)

    if ($Environment['APP_ENV'] -ne 'production') { throw 'APP_ENV debe ser production.' }
    if ($Environment['APP_DEBUG'] -ne 'false') { throw 'APP_DEBUG debe ser false.' }
    if (-not $Environment['APP_KEY']) { throw 'APP_KEY no puede estar vacío.' }
    if ($Environment['APP_URL'] -notmatch '^https://') { throw 'APP_URL debe usar HTTPS.' }
    if ($Environment['SESSION_SECURE_COOKIE'] -ne 'true') { throw 'SESSION_SECURE_COOKIE debe ser true.' }
}
