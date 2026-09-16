param(
    [Parameter(Mandatory=$true)][string]$QzPath,
    [Parameter(Mandatory=$true)][string]$CertificatePath,
    [switch]$Remove
)
$ErrorActionPreference = 'Stop'
$qzRoot = [IO.Path]::GetFullPath($QzPath)
$certificate = [IO.Path]::GetFullPath($CertificatePath).Replace('\', '/')
$properties = Join-Path $qzRoot 'qz-tray.properties'
if (!(Test-Path -LiteralPath (Join-Path $qzRoot 'qz-tray.exe'))) { throw 'QZ Tray executable missing.' }
if ($certificate.Contains(';') -or $certificate.Contains('|')) { throw 'Unsupported certificate path.' }
$encoding = [Text.Encoding]::GetEncoding(28591)
$text = if (Test-Path -LiteralPath $properties) { [IO.File]::ReadAllText($properties, $encoding) } else { '' }
$pattern = '(?m)^(authcert\.override|trustedRootCert)[ \t]*[=:][ \t]*([^\r\n]*)'
$matchesFound = [regex]::Matches($text, $pattern)
if ($matchesFound.Count -gt 1) { throw 'Multiple trust settings: preserve existing configuration and review manually.' }
$value = if ($matchesFound.Count) { $matchesFound[0].Groups[2].Value } else { '' }
if ($value.EndsWith('\')) { throw 'Multiline trust setting: review manually.' }
$parts = @($value.Split(';') | Where-Object { $_ -and $_ -ne $certificate })
if (!$Remove) {
    $pem = [IO.File]::ReadAllText($CertificatePath)
    if ($pem.Contains('PRIVATE KEY') -or $pem -notmatch '-----BEGIN CERTIFICATE-----([\s\S]+?)-----END CERTIFICATE-----') { throw 'Public X509 certificate required.' }
    $der = [Convert]::FromBase64String($Matches[1])
    $cert = New-Object Security.Cryptography.X509Certificates.X509Certificate2 -ArgumentList @(,$der)
    if ($cert.NotAfter -le (Get-Date) -or $cert.NotBefore -gt (Get-Date)) { throw 'Certificate outside validity period.' }
    $parts += $certificate
}
$replacement = if ($parts.Count) { 'authcert.override=' + ($parts -join ';') } else { '' }
if ($matchesFound.Count) {
    $match = $matchesFound[0]
    $updated = $text.Substring(0,$match.Index) + $replacement + $text.Substring($match.Index+$match.Length)
} elseif ($Remove) {
    $updated = $text
} else {
    $updated = $text.TrimEnd() + "`r`nauthcert.override=$certificate`r`n"
}
if ($updated -ne $text) {
    if (Test-Path -LiteralPath $properties) {
        $backup = $properties + '.mvs-print-' + (Get-Date -Format 'yyyyMMddHHmmssfff') + '.bak'
        Copy-Item -LiteralPath $properties -Destination $backup
    }
    [IO.File]::WriteAllText($properties, $updated, $encoding)
}
Write-Output 'MVS public trust configured; other roots preserved.'
