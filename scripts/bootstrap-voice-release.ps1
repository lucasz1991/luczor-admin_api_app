[CmdletBinding()]
param(
    [string]$ReleaseVersion = (Get-Date -Format 'yyyy.MM.dd'),
    [string]$BaseUrl = 'https://luczor.follow-flow.de',
    [switch]$Force,
    [switch]$SkipDownload
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$releaseRoot = Join-Path $root "storage\app\voice\releases\$ReleaseVersion"
$keyRelative = 'storage/app/keys/luczor_device_job_private.pem'
$manifestRelative = "storage/app/voice/releases/$ReleaseVersion/manifest.json"
$publicEnv = Join-Path (Split-Path -Parent $root) 'app\.env.voice'

function Set-DotEnvValue([string]$Path, [string]$Name, [string]$Value) {
    $line = "$Name=$Value"
    $content = if (Test-Path -LiteralPath $Path) { Get-Content -LiteralPath $Path } else { @() }
    $found = $false
    $updated = foreach ($current in $content) {
        if ($current -match "^$([regex]::Escape($Name))=") { $found = $true; $line } else { $current }
    }
    if (-not $found) { $updated += $line }
    Set-Content -LiteralPath $Path -Value $updated -Encoding utf8
}

function Get-ZipRuntimePath([string]$Archive, [string]$Executable) {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::OpenRead($Archive)
    try {
        $entry = $zip.Entries | Where-Object { $_.FullName -match "(?i)(^|/)$([regex]::Escape($Executable))$" } | Select-Object -First 1
        if (-not $entry) { throw "Executable '$Executable' fehlt in $Archive" }
        return $entry.FullName
    } finally { $zip.Dispose() }
}

New-Item -ItemType Directory -Force -Path $releaseRoot | Out-Null
$headers = @{ 'User-Agent' = 'Luczor-voice-release-bootstrap' }
$whisperZip = Join-Path $releaseRoot 'whisper-bin-x64.zip'
$piperZip = Join-Path $releaseRoot 'piper_windows_amd64.zip'
$whisperModel = Join-Path $releaseRoot 'ggml-base.bin'
$piperModel = Join-Path $releaseRoot 'de_DE-thorsten-medium.onnx'
$piperConfig = "$piperModel.json"

if (-not $SkipDownload) {
    $release = Invoke-RestMethod -Headers $headers -Uri 'https://api.github.com/repos/ggml-org/whisper.cpp/releases/latest'
    $whisperUrl = ($release.assets | Where-Object { $_.name -eq 'whisper-bin-x64.zip' } | Select-Object -First 1).browser_download_url
    if (-not $whisperUrl) { throw 'Offizielles whisper.cpp Windows-x64-Release wurde nicht gefunden.' }
    $downloads = @(
        @{ Url = $whisperUrl; Path = $whisperZip },
        @{ Url = 'https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_windows_amd64.zip'; Path = $piperZip },
        @{ Url = 'https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-base.bin?download=true'; Path = $whisperModel },
        @{ Url = 'https://huggingface.co/rhasspy/piper-voices/resolve/main/de/de_DE/thorsten/medium/de_DE-thorsten-medium.onnx?download=true'; Path = $piperModel },
        @{ Url = 'https://huggingface.co/rhasspy/piper-voices/resolve/main/de/de_DE/thorsten/medium/de_DE-thorsten-medium.onnx.json?download=true'; Path = $piperConfig }
    )
    foreach ($download in $downloads) {
        if ($Force -or -not (Test-Path -LiteralPath $download.Path)) {
            Write-Host "Lade $(Split-Path -Leaf $download.Path) ..."
            Invoke-WebRequest -Headers $headers -Uri $download.Url -OutFile $download.Path
        }
    }
}

foreach ($file in @($whisperZip, $piperZip, $whisperModel, $piperModel, $piperConfig)) {
    if (-not (Test-Path -LiteralPath $file)) { throw "Voice-Asset fehlt: $file" }
}

$keyPath = Join-Path $root $keyRelative.Replace('/', '\\')
if (-not (Test-Path -LiteralPath $keyPath)) {
    & php artisan luczor:device-signing-key --path=$keyRelative
    if ($LASTEXITCODE -ne 0) { throw 'Voice-Signaturschlüssel konnte nicht erzeugt werden.' }
}
Set-DotEnvValue (Join-Path $root '.env') 'LUCZOR_JOB_PRIVATE_KEY_FILE' $keyRelative
Set-DotEnvValue (Join-Path $root '.env') 'LUCZOR_VOICE_MANIFEST_FILE' $manifestRelative

$whisperRuntime = Get-ZipRuntimePath $whisperZip 'whisper-cli.exe'
$piperRuntime = Get-ZipRuntimePath $piperZip 'piper.exe'
$assetBaseUrl = $BaseUrl.TrimEnd('/') + "/api/v1/voice/releases/$ReleaseVersion"
& php artisan luczor:voice-release `
    --release-version=$ReleaseVersion `
    --base-url=$assetBaseUrl `
    --platform=windows-x86_64 `
    --stt-binary=$whisperZip `
    --stt-binary-runtime-path=$whisperRuntime `
    --stt-model=$whisperModel `
    --tts-binary=$piperZip `
    --tts-binary-runtime-path=$piperRuntime `
    --tts-model=$piperModel `
    --tts-config=$piperConfig `
    --manifest-output=$manifestRelative
if ($LASTEXITCODE -ne 0) { throw 'Voice-Manifest konnte nicht erzeugt werden.' }

$publicLine = & php artisan luczor:device-signing-key --path=$keyRelative --print-public-key | Where-Object { $_ -match '^LUCZOR_VOICE_MANIFEST_PUBLIC_KEY_B64=' } | Select-Object -Last 1
if (-not $publicLine) { throw 'Voice-Public-Key konnte nicht aus dem Signaturschlüssel gelesen werden.' }
$publicB64 = ($publicLine -split '=', 2)[1]
Set-Content -LiteralPath $publicEnv -Value "LUCZOR_VOICE_MANIFEST_PUBLIC_KEY_B64=$publicB64" -Encoding ascii

Write-Host ''
Write-Host "Voice-Release $ReleaseVersion ist bereit."
Write-Host "Manifest: $manifestRelative"
Write-Host "Tauri Public-Key-Datei: $publicEnv"
Write-Host 'Als Nächstes: im app-Ordner `pnpm.cmd tauri build` bzw. `pnpm.cmd tauri dev` ausführen.'
