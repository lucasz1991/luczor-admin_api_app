[CmdletBinding()]
param(
    [string]$SecretDirectory
)

$ErrorActionPreference = "Stop"

$environmentDirectory = [Environment]::GetEnvironmentVariable("LUCZOR_DOCKER_SECRETS_DIR")
if ([string]::IsNullOrWhiteSpace($SecretDirectory)) {
    if (-not [string]::IsNullOrWhiteSpace($environmentDirectory)) {
        if (-not [IO.Path]::IsPathFullyQualified($environmentDirectory)) {
            throw "LUCZOR_DOCKER_SECRETS_DIR must be an absolute path when it is set."
        }
        $SecretDirectory = $environmentDirectory
    } else {
        $SecretDirectory = Join-Path $PSScriptRoot "secrets"
    }
}

$SecretDirectory = [IO.Path]::GetFullPath($SecretDirectory)
if (Test-Path -LiteralPath $SecretDirectory) {
    $directory = Get-Item -LiteralPath $SecretDirectory -Force
    if (-not $directory.PSIsContainer -or ($directory.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw "Secret directory must be a real directory, not a link: $SecretDirectory"
    }
} else {
    New-Item -ItemType Directory -Path $SecretDirectory | Out-Null
}

$isWindowsHost = $env:OS -eq "Windows_NT"
if (-not $isWindowsHost) {
    & chmod 700 $SecretDirectory
    if ($LASTEXITCODE -ne 0) {
        throw "Unable to apply mode 0700 to the secret directory."
    }
}

function New-RandomSecret([int]$Bytes = 36) {
    $data = New-Object byte[] $Bytes
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $rng.GetBytes($data)
    } finally {
        $rng.Dispose()
    }
    return [Convert]::ToBase64String($data)
}

function Assert-SafeSecretFile([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) {
        return
    }

    $item = Get-Item -LiteralPath $Path -Force
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw "Secret target must be a regular file, not a link: $Path"
    }
}

function Set-SecretFileMode([string]$Path) {
    if (-not $isWindowsHost) {
        & chmod 600 $Path
        if ($LASTEXITCODE -ne 0) {
            throw "Unable to apply mode 0600 to secret file: $Path"
        }
    }
}

function Write-SecretIfMissing([string]$Name, [string]$Value) {
    $path = Join-Path $SecretDirectory $Name
    Assert-SafeSecretFile $path
    if (Test-Path -LiteralPath $path -PathType Leaf) {
        Set-SecretFileMode $path
        return
    }

    $temporaryPath = Join-Path $SecretDirectory (".$Name." + [Guid]::NewGuid().ToString("N"))
    try {
        $bytes = [Text.Encoding]::ASCII.GetBytes($Value)
        $stream = [IO.File]::Open($temporaryPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
        try {
            $stream.Write($bytes, 0, $bytes.Length)
        } finally {
            $stream.Dispose()
        }
        Set-SecretFileMode $temporaryPath
        try {
            [IO.File]::Move($temporaryPath, $path)
            Write-Host "Created $Name"
        } catch [IO.IOException] {
            Assert-SafeSecretFile $path
            if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
                throw
            }
        }
    } finally {
        if (Test-Path -LiteralPath $temporaryPath -PathType Leaf) {
            Remove-Item -LiteralPath $temporaryPath -Force
        }
    }
    Set-SecretFileMode $path
}

Write-SecretIfMissing "app_key" ("base64:" + (New-RandomSecret 32))
Write-SecretIfMissing "postgres_password" (New-RandomSecret)
Write-SecretIfMissing "redis_password" (New-RandomSecret)
Write-SecretIfMissing "openrouter_key" ""
Write-SecretIfMissing "github_client_secret" ""
Write-SecretIfMissing "github_webhook_secret" (New-RandomSecret)
Write-SecretIfMissing "reverb_app_secret" (New-RandomSecret)
Write-SecretIfMissing "internal_service_key" (New-RandomSecret)
Write-SecretIfMissing "cognee_api_key" ""
Write-SecretIfMissing "cognee_postgres_password" (New-RandomSecret)
Write-SecretIfMissing "cognee_llm_api_key" ""
Write-SecretIfMissing "cognee_embedding_api_key" ""
Write-SecretIfMissing "cognee_jwt_secret" (New-RandomSecret 48)
Write-SecretIfMissing "cognee_default_password" (New-RandomSecret)
Write-SecretIfMissing "cognee_verification_secret" (New-RandomSecret 48)
Write-SecretIfMissing "cognee_reset_secret" (New-RandomSecret 48)

$jobKey = Join-Path $SecretDirectory "job_private_key"
Assert-SafeSecretFile $jobKey
if (-not (Test-Path -LiteralPath $jobKey -PathType Leaf)) {
    $temporaryJobKey = Join-Path $SecretDirectory (".job_private_key." + [Guid]::NewGuid().ToString("N"))
    try {
        $php = Get-Command php -ErrorAction Stop
        $phpCode = '$key = openssl_pkey_new([''private_key_bits'' => 3072, ''private_key_type'' => OPENSSL_KEYTYPE_RSA]); if (!$key || !openssl_pkey_export($key, $pem)) { fwrite(STDERR, ''RSA key generation failed''); exit(1); } file_put_contents($argv[1], $pem);'
        & $php.Source -r $phpCode $temporaryJobKey
        if ($LASTEXITCODE -ne 0) {
            throw "Unable to create the device-job RSA private key."
        }
        Set-SecretFileMode $temporaryJobKey
        try {
            [IO.File]::Move($temporaryJobKey, $jobKey)
            Write-Host "Created job_private_key"
        } catch [IO.IOException] {
            Assert-SafeSecretFile $jobKey
            if (-not (Test-Path -LiteralPath $jobKey -PathType Leaf)) {
                throw
            }
        }
    } finally {
        if (Test-Path -LiteralPath $temporaryJobKey -PathType Leaf) {
            Remove-Item -LiteralPath $temporaryJobKey -Force
        }
    }
}
Set-SecretFileMode $jobKey

$localModelSigningKey = Join-Path $SecretDirectory "luczor_local_model_signing_private_key"
Assert-SafeSecretFile $localModelSigningKey
if (-not (Test-Path -LiteralPath $localModelSigningKey -PathType Leaf)) {
    $temporaryLocalModelSigningKey = Join-Path $SecretDirectory (".luczor_local_model_signing_private_key." + [Guid]::NewGuid().ToString("N"))
    try {
        $php = Get-Command php -ErrorAction Stop
        $phpCode = '$key = openssl_pkey_new([''private_key_bits'' => 3072, ''private_key_type'' => OPENSSL_KEYTYPE_RSA]); if (!$key || !openssl_pkey_export($key, $pem)) { fwrite(STDERR, ''RSA key generation failed''); exit(1); } file_put_contents($argv[1], $pem);'
        & $php.Source -r $phpCode $temporaryLocalModelSigningKey
        if ($LASTEXITCODE -ne 0) {
            throw "Unable to create the local-model catalog RSA signing key."
        }
        Set-SecretFileMode $temporaryLocalModelSigningKey
        try {
            [IO.File]::Move($temporaryLocalModelSigningKey, $localModelSigningKey)
            Write-Host "Created luczor_local_model_signing_private_key"
        } catch [IO.IOException] {
            Assert-SafeSecretFile $localModelSigningKey
            if (-not (Test-Path -LiteralPath $localModelSigningKey -PathType Leaf)) {
                throw
            }
        }
    } finally {
        if (Test-Path -LiteralPath $temporaryLocalModelSigningKey -PathType Leaf) {
            Remove-Item -LiteralPath $temporaryLocalModelSigningKey -Force
        }
    }
}
Set-SecretFileMode $localModelSigningKey

$php = Get-Command php -ErrorAction Stop
$fingerprintCode = '$key = openssl_pkey_get_private(file_get_contents($argv[1])); $details = $key ? openssl_pkey_get_details($key) : false; if (!is_array($details) || ($details[''type''] ?? null) !== OPENSSL_KEYTYPE_RSA || ($details[''bits''] ?? 0) < 3072 || !is_string($details[''key''] ?? null)) { fwrite(STDERR, ''Invalid local-model RSA signing key''); exit(1); } fwrite(STDOUT, hash(''sha256'', $details[''key'']));'
$localModelPublicKeySha256 = (& $php.Source -r $fingerprintCode $localModelSigningKey)
if ($LASTEXITCODE -ne 0 -or $localModelPublicKeySha256 -notmatch '^[a-f0-9]{64}$') {
    throw "Unable to validate the local-model signing key and derive its public-key SHA-256 pin."
}

Write-Host "Secrets are ready in $SecretDirectory. Fill openrouter_key, github_client_secret, cognee_llm_api_key, and cognee_embedding_api_key before enabling those integrations."
Write-Host "Set LUCZOR_LOCAL_MODEL_EXPECTED_PUBLIC_KEY_SHA256=$localModelPublicKeySha256 for the API release and pin the matching public key in Desktop."
