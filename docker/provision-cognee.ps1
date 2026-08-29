[CmdletBinding()]
param(
    [string]$EnvironmentFile = (Join-Path (Resolve-Path (Join-Path $PSScriptRoot "..\..")) ".env.docker"),
    [string]$ComposeFile,
    [string]$SecretDirectory,
    [switch]$Force
)

$ErrorActionPreference = "Stop"
$workspaceRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
$appRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
if ([string]::IsNullOrWhiteSpace($ComposeFile)) {
    $workspaceCompose = Join-Path $workspaceRoot "docker-compose.yml"
    $ComposeFile = if (Test-Path -LiteralPath $workspaceCompose -PathType Leaf) {
        $workspaceCompose
    } else {
        Join-Path $appRoot "docker-compose.plesk-memory.yml"
    }
}

if ([string]::IsNullOrWhiteSpace($SecretDirectory)) {
    $environmentDirectory = [Environment]::GetEnvironmentVariable("LUCZOR_DOCKER_SECRETS_DIR")
    $SecretDirectory = if ([string]::IsNullOrWhiteSpace($environmentDirectory)) {
        Join-Path $PSScriptRoot "secrets"
    } else {
        if (-not [IO.Path]::IsPathFullyQualified($environmentDirectory)) {
            throw "LUCZOR_DOCKER_SECRETS_DIR must be an absolute path when it is set."
        }
        $environmentDirectory
    }
}

$SecretDirectory = [IO.Path]::GetFullPath($SecretDirectory)
$secretDirectoryItem = Get-Item -LiteralPath $SecretDirectory -Force -ErrorAction Stop
if (-not $secretDirectoryItem.PSIsContainer -or ($secretDirectoryItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
    throw "Cognee secret directory is missing or is a link: $SecretDirectory"
}

$apiKeyFile = Join-Path $SecretDirectory "cognee_api_key"
$defaultPasswordFile = Join-Path $SecretDirectory "cognee_default_password"

function Assert-SafeProvisioningFile([string]$Path) {
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw "Required Cognee provisioning file is missing or unsafe: $Path"
    }
}

foreach ($requiredPath in @($ComposeFile, $defaultPasswordFile, $apiKeyFile)) {
    Assert-SafeProvisioningFile $requiredPath
}

$existingKey = [IO.File]::ReadAllText($apiKeyFile).Trim()
if ($existingKey -and -not $Force) {
    Write-Host "Cognee service API key is already provisioned. Use -Force only for an intentional rotation."
    return
}

$pythonCode = @'
import json
import urllib.error
import urllib.parse
import urllib.request

base_url = "http://127.0.0.1:8000"
email = "cognee-service@luczor.follow-flow.de"
with open("/run/secrets/cognee_default_password", encoding="utf-8") as handle:
    password = handle.read().strip()
if not password:
    raise SystemExit("Cognee default password secret is empty")

def send(path, *, body=None, content_type="application/json", token=None, api_key=None):
    headers = {"Accept": "application/json", "Content-Type": content_type}
    if token:
        headers["Authorization"] = "Bearer " + token
    if api_key:
        headers["Authorization"] = "Bearer " + api_key
        headers["X-Api-Key"] = api_key
    request = urllib.request.Request(base_url + path, data=body, headers=headers, method="POST" if body is not None else "GET")
    with urllib.request.urlopen(request, timeout=15) as response:
        return json.loads(response.read().decode("utf-8") or "{}")

def login():
    form = urllib.parse.urlencode({"username": email, "password": password}).encode()
    result = send("/api/v1/auth/login", body=form, content_type="application/x-www-form-urlencoded")
    token = result.get("access_token") or result.get("token")
    if not isinstance(token, str) or not token:
        raise RuntimeError("Cognee login returned no bearer token")
    return token

try:
    bearer = login()
except urllib.error.HTTPError as login_error:
    if login_error.code not in (400, 401, 404):
        raise
    registration = json.dumps({"email": email, "password": password}).encode()
    try:
        send("/api/v1/auth/register", body=registration)
    except urllib.error.HTTPError as register_error:
        if register_error.code not in (400, 409):
            raise
    bearer = login()

created = send(
    "/api/v1/auth/api-keys",
    body=json.dumps({"name": "luczor-laravel"}).encode(),
    token=bearer,
)

def raw_api_key(value):
    if isinstance(value, dict):
        for name in ("api_key", "key", "token"):
            candidate = value.get(name)
            if isinstance(candidate, str) and candidate:
                return candidate
        for nested in value.values():
            candidate = raw_api_key(nested)
            if candidate:
                return candidate
    return None

api_key = raw_api_key(created)
if not api_key or len(api_key) < 20 or any(character.isspace() for character in api_key):
    raise SystemExit("Cognee returned no valid raw API key")

send("/api/v1/users/me", api_key=api_key)
print("LUCZOR_COGNEE_KEY=" + api_key)
'@

$dockerArguments = @("compose")
if (Test-Path -LiteralPath $EnvironmentFile -PathType Leaf) {
    $dockerArguments += @("--env-file", $EnvironmentFile)
}
$dockerArguments += @(
    "-f", $ComposeFile,
    "exec", "-T", "cognee", "/usr/local/bin/python", "-c", $pythonCode
)

$previousSecretDirectory = [Environment]::GetEnvironmentVariable("LUCZOR_DOCKER_SECRETS_DIR", "Process")
try {
    [Environment]::SetEnvironmentVariable("LUCZOR_DOCKER_SECRETS_DIR", $SecretDirectory, "Process")
    $provisionOutput = & docker @dockerArguments
} finally {
    [Environment]::SetEnvironmentVariable("LUCZOR_DOCKER_SECRETS_DIR", $previousSecretDirectory, "Process")
}
if ($LASTEXITCODE -ne 0) {
    throw "Cognee API-key provisioning failed. The existing local secret was not changed."
}

$marker = $provisionOutput | Where-Object { $_ -is [string] -and $_.StartsWith("LUCZOR_COGNEE_KEY=") } | Select-Object -Last 1
if (-not $marker) {
    throw "Cognee provisioning returned no validated API key. The existing local secret was not changed."
}
$apiKey = $marker.Substring("LUCZOR_COGNEE_KEY=".Length).Trim()
if ($apiKey.Length -lt 20 -or $apiKey -match "\s") {
    throw "Cognee returned an invalid API-key shape. The existing local secret was not changed."
}

$temporaryApiKey = Join-Path $SecretDirectory (".cognee_api_key." + [Guid]::NewGuid().ToString("N"))
try {
    [IO.File]::WriteAllText($temporaryApiKey, $apiKey, [Text.Encoding]::ASCII)
    if ($env:OS -ne "Windows_NT") {
        & chmod 600 $temporaryApiKey
        if ($LASTEXITCODE -ne 0) {
            throw "Unable to apply mode 0600 to the Cognee API key."
        }
    }
    Assert-SafeProvisioningFile $apiKeyFile
    Move-Item -LiteralPath $temporaryApiKey -Destination $apiKeyFile -Force
} finally {
    if (Test-Path -LiteralPath $temporaryApiKey -PathType Leaf) {
        Remove-Item -LiteralPath $temporaryApiKey -Force
    }
}

Write-Host "Cognee service API key was validated and stored. Restart Laravel/Horizon to load it."
