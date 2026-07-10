[CmdletBinding()]
param(
    [string]$SecretDirectory = (Join-Path $PSScriptRoot "secrets")
)

$ErrorActionPreference = "Stop"
New-Item -ItemType Directory -Force -Path $SecretDirectory | Out-Null

function New-RandomSecret([int]$Bytes = 36) {
    $data = New-Object byte[] $Bytes
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $rng.GetBytes($data)
    } finally {
        $rng.Dispose()
    }
    return [Convert]::ToBase64String($data)
}

function Write-SecretIfMissing([string]$Name, [string]$Value) {
    $path = Join-Path $SecretDirectory $Name
    if (-not (Test-Path -LiteralPath $path)) {
        [System.IO.File]::WriteAllText($path, $Value, [System.Text.Encoding]::ASCII)
        Write-Host "Created $Name"
    }
}

Write-SecretIfMissing "app_key" ("base64:" + (New-RandomSecret 32))
Write-SecretIfMissing "postgres_password" (New-RandomSecret)
Write-SecretIfMissing "redis_password" (New-RandomSecret)
Write-SecretIfMissing "openrouter_key" ""
Write-SecretIfMissing "github_client_secret" ""
Write-SecretIfMissing "github_webhook_secret" (New-RandomSecret)
Write-SecretIfMissing "reverb_app_secret" (New-RandomSecret)

$jobKey = Join-Path $SecretDirectory "job_private_key"
if (-not (Test-Path -LiteralPath $jobKey)) {
    $php = Get-Command php -ErrorAction Stop
    $phpCode = '$key = openssl_pkey_new([''private_key_bits'' => 3072, ''private_key_type'' => OPENSSL_KEYTYPE_RSA]); if (!$key || !openssl_pkey_export($key, $pem)) { fwrite(STDERR, ''RSA key generation failed''); exit(1); } file_put_contents($argv[1], $pem);'
    & $php.Source -r $phpCode $jobKey
    if ($LASTEXITCODE -ne 0) {
        throw "Unable to create the device-job RSA private key."
    }
    Write-Host "Created job_private_key"
}

Write-Host "Secrets are ready in $SecretDirectory. Fill openrouter_key and github_client_secret before enabling those integrations."
