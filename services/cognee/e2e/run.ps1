[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$composeProject = "luczor-cognee-e2e-$PID-$([DateTimeOffset]::UtcNow.ToUnixTimeSeconds())"
if ($composeProject -notmatch '^luczor-cognee-e2e-[0-9]+-[0-9]+$') {
    throw 'Refusing to use an invalid Compose project name.'
}

$workspaceRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..\..')).Path
$composeFile = Join-Path $workspaceRoot 'docker-compose.cognee-e2e.yml'
$imageTag = "${composeProject}:local"
$previousImageTag = $env:LUCZOR_COGNEE_E2E_IMAGE_TAG
$previousComposeBake = $env:COMPOSE_BAKE
$engineWasRunning = $false
$desktopStartedByHarness = $false
$composeWasCreated = $false
$runError = $null
$cleanupErrors = [System.Collections.Generic.List[string]]::new()

function Test-DockerEngine {
    try {
        & docker info --format '{{.ServerVersion}}' *> $null
        return $LASTEXITCODE -eq 0
    } catch {
        # PowerShell 7 can promote a native non-zero exit into a terminating
        # error. A stopped daemon is expected state here, not a harness crash.
        return $false
    }
}

function Invoke-Compose {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]] $Arguments)
    & docker compose --project-name $composeProject --file $composeFile @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose failed with exit code $LASTEXITCODE"
    }
}

try {
    $engineWasRunning = Test-DockerEngine
    if (-not $engineWasRunning) {
        & docker desktop start
        if ($LASTEXITCODE -ne 0) {
            throw 'Docker Desktop could not be started.'
        }
        $desktopStartedByHarness = $true
        $deadline = [DateTimeOffset]::UtcNow.AddSeconds(90)
        while (-not (Test-DockerEngine)) {
            if ([DateTimeOffset]::UtcNow -ge $deadline) {
                throw 'Docker Desktop did not become ready within 90 seconds.'
            }
            Start-Sleep -Seconds 2
        }
    }

    $env:LUCZOR_COGNEE_E2E_IMAGE_TAG = $imageTag
    # Compose's optional Bake delegation intermittently closes its generated
    # input pipe on Docker Desktop. The classic Compose builder is deterministic
    # for this single local image and does not require an additional component.
    $env:COMPOSE_BAKE = 'false'
    Invoke-Compose config --quiet
    $composeWasCreated = $true
    Invoke-Compose up --build --detach --wait cognee
    Invoke-Compose -Arguments @(
        'run', '--rm', '--no-deps',
        '--entrypoint', '/app/.venv/bin/python',
        'e2e-runner', '-m', 'unittest', 'discover',
        '-s', '/opt/luczor-cognee-tests',
        '-p', 'test_luczor_*.py',
        '-v'
    )
    Invoke-Compose run --rm --no-deps e2e-runner
}
catch {
    $runError = $_
    if ($composeWasCreated -and (Test-DockerEngine)) {
        try {
            & docker compose --project-name $composeProject --file $composeFile logs --no-color --tail 150 cognee fake-openai cognee-api-key-init
        } catch {
            $cleanupErrors.Add("diagnostic logs failed: $($_.Exception.Message)")
        }
    }
}
finally {
    try {
        if (Test-DockerEngine) {
            & docker compose --project-name $composeProject --file $composeFile down --volumes --remove-orphans --rmi local --timeout 15
            if ($LASTEXITCODE -ne 0) {
                $cleanupErrors.Add("docker compose down failed with exit code $LASTEXITCODE")
            }
            $matchingImages = @(& docker image ls --quiet --filter "reference=$imageTag")
            if ($matchingImages.Count -gt 0) {
                & docker image rm --force $imageTag
                if ($LASTEXITCODE -ne 0) {
                    $cleanupErrors.Add("test image removal failed with exit code $LASTEXITCODE")
                }
            }

            $remainingContainers = @(& docker container ls --all --quiet --filter "label=com.docker.compose.project=$composeProject")
            $remainingVolumes = @(& docker volume ls --quiet --filter "label=com.docker.compose.project=$composeProject")
            $remainingNetworks = @(& docker network ls --quiet --filter "label=com.docker.compose.project=$composeProject")
            if ($remainingContainers.Count -gt 0 -or $remainingVolumes.Count -gt 0 -or $remainingNetworks.Count -gt 0) {
                $cleanupErrors.Add('the isolated Compose project was not fully removed')
            }
        }
    } catch {
        $cleanupErrors.Add("resource cleanup failed: $($_.Exception.Message)")
    }

    try {
        if ($null -eq $previousImageTag) {
            Remove-Item Env:LUCZOR_COGNEE_E2E_IMAGE_TAG -ErrorAction SilentlyContinue
        } else {
            $env:LUCZOR_COGNEE_E2E_IMAGE_TAG = $previousImageTag
        }
        if ($null -eq $previousComposeBake) {
            Remove-Item Env:COMPOSE_BAKE -ErrorAction SilentlyContinue
        } else {
            $env:COMPOSE_BAKE = $previousComposeBake
        }
    } catch {
        $cleanupErrors.Add("environment restoration failed: $($_.Exception.Message)")
    }

    if ($desktopStartedByHarness) {
        try {
            & docker desktop stop
            if ($LASTEXITCODE -ne 0) {
                $cleanupErrors.Add("Docker Desktop stop failed with exit code $LASTEXITCODE")
            }
        } catch {
            $cleanupErrors.Add("Docker Desktop stop failed: $($_.Exception.Message)")
        }
    }
}

if ($cleanupErrors.Count -gt 0) {
    $cleanupMessage = $cleanupErrors -join '; '
    if ($null -ne $runError) {
        throw "$($runError.Exception.Message); cleanup: $cleanupMessage"
    }
    throw "Cognee E2E cleanup failed: $cleanupMessage"
}
if ($null -ne $runError) {
    throw $runError
}
