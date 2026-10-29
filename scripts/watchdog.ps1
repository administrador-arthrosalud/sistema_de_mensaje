$ErrorActionPreference = 'SilentlyContinue'
$taskName = 'Chatbot-Incident-Watchdog'
$containerSpecs = @(
    @{ Name = 'siges-dashboard-db'; StartEvent = 'database_container_started'; FailEvent = 'database_container_start_failed'; StopEvent = 'database_container_stopped' },
    @{ Name = 'siges-dashboard-web'; StartEvent = 'web_container_started'; FailEvent = 'web_container_start_failed'; StopEvent = 'web_container_stopped' },
    @{ Name = 'siges-dashboard-sync'; StartEvent = 'sync_container_started'; FailEvent = 'sync_container_start_failed'; StopEvent = 'sync_container_stopped' },
    @{ Name = 'siges-dashboard-auto-reminders'; StartEvent = 'auto_reminders_container_started'; FailEvent = 'auto_reminders_container_start_failed'; StopEvent = 'auto_reminders_container_stopped' }
)

$script:stateDirectory = Join-Path $env:LOCALAPPDATA 'ChatbotIncidentWatchdog'
New-Item -ItemType Directory -Force -Path $script:stateDirectory | Out-Null
$script:logFile = Join-Path $script:stateDirectory 'watchdog.log'
$script:spoolFile = Join-Path $script:stateDirectory 'pending-events.txt'
$script:lastAlerts = @{}
$script:lastRestarts = @{}
$script:dockerExe = @(
    (Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\resources\bin\docker.exe'),
    (Join-Path $env:ProgramFiles 'Docker\Docker\resources\bin\docker.exe')
) | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1

function Write-WatchdogLog([string] $message) {
    if ((Test-Path -LiteralPath $script:logFile) -and (Get-Item -LiteralPath $script:logFile).Length -gt 1048576) {
        Move-Item -LiteralPath $script:logFile -Destination ($script:logFile + '.1') -Force
    }
    $line = '{0} {1}' -f [DateTimeOffset]::Now.ToString('o'), $message
    Add-Content -LiteralPath $script:logFile -Value $line -Encoding UTF8
}

function Add-Incident([string] $eventCode, [int] $cooldownSeconds = 900) {
    $now = [DateTimeOffset]::UtcNow
    if (-not $script:lastAlerts.ContainsKey($eventCode) -or ($now - $script:lastAlerts[$eventCode]).TotalSeconds -ge $cooldownSeconds) {
        if (-not $script:pendingEvents.Contains($eventCode)) { [void] $script:pendingEvents.Add($eventCode) }
        $script:lastAlerts[$eventCode] = $now
        Write-WatchdogLog "incident=$eventCode"
    }
}

function Add-Event([string] $eventCode) {
    if (-not $script:pendingEvents.Contains($eventCode)) { [void] $script:pendingEvents.Add($eventCode) }
    Write-WatchdogLog "event=$eventCode"
}

function Get-ContainerState([string] $name) {
    $raw = & $script:dockerExe inspect --format '{{.State.Status}}|{{.State.ExitCode}}' $name 2>$null
    if ($LASTEXITCODE -ne 0 -or -not $raw) { return $null }
    $parts = ([string] $raw).Trim().Split('|')
    if ($parts.Count -ne 2) { return $null }
    return @{ Status = $parts[0]; ExitCode = [int] $parts[1] }
}

function Test-Cooldown([string] $key, [int] $seconds) {
    $now = [DateTimeOffset]::UtcNow
    if ($script:lastRestarts.ContainsKey($key) -and ($now - $script:lastRestarts[$key]).TotalSeconds -lt $seconds) { return $false }
    $script:lastRestarts[$key] = $now
    return $true
}

function Start-StoppedContainer($spec) {
    $state = Get-ContainerState $spec.Name
    if ($null -eq $state) {
        Add-Incident 'container_not_found'
        return
    }
    if ($state.Status -eq 'running') { return }
    if ($state.Status -eq 'created' -or $state.Status -eq 'dead' -or ($state.Status -eq 'exited' -and $state.ExitCode -ne 0)) {
        & $script:dockerExe start $spec.Name 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) {
            Add-Event $spec.StartEvent
        } else {
            Add-Incident $spec.FailEvent
        }
        return
    }
    if ($state.Status -eq 'exited' -or $state.Status -eq 'paused') {
        Add-Incident $spec.StopEvent
    }
}

function Get-DatabaseReady {
    $state = Get-ContainerState 'siges-dashboard-db'
    if ($null -eq $state -or $state.Status -ne 'running') { return $false }
    for ($attempt = 0; $attempt -lt 12; $attempt++) {
        & $script:dockerExe exec siges-dashboard-db pg_isready -U postgres -d siges_dashboard 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { return $true }
        Start-Sleep -Seconds 5
    }
    return $false
}

function Get-WebPort {
    $portsJson = & $script:dockerExe inspect --format '{{json .NetworkSettings.Ports}}' siges-dashboard-web 2>$null
    if ($LASTEXITCODE -ne 0) { return $null }
    try {
        $ports = ([string] $portsJson | ConvertFrom-Json)
        $bindings = $ports.PSObject.Properties['80/tcp'].Value
        if ($null -eq $bindings -or $bindings.Count -eq 0) { return $null }
        $port = [string] $bindings[0].HostPort
    } catch {
        return $null
    }
    if ($port -match '^\d{1,5}$') { return $port }
    return $null
}

function Queue-PendingEvents {
    $remaining = New-Object System.Collections.ArrayList
    if (Test-Path -LiteralPath $script:spoolFile) {
        foreach ($savedCode in Get-Content -LiteralPath $script:spoolFile) {
            if ($savedCode -match '^[a-z0-9_]{1,48}$' -and -not $script:pendingEvents.Contains($savedCode)) { [void] $script:pendingEvents.Add($savedCode) }
        }
    }

    $webState = Get-ContainerState 'siges-dashboard-web'
    foreach ($eventCode in $script:pendingEvents) {
        $queueExitCode = 1
        if ($null -ne $webState -and $webState.Status -eq 'running') {
            & $script:dockerExe exec siges-dashboard-web php /var/www/app/scripts/system-watchdog-report.php $eventCode 2>$null | Out-Null
            $queueExitCode = $LASTEXITCODE
        }
        if ($queueExitCode -eq 0) {
            Write-WatchdogLog "alert_queued=$eventCode"
        } else {
            [void] $remaining.Add($eventCode)
        }
    }

    if ($remaining.Count -gt 0) {
        Set-Content -LiteralPath $script:spoolFile -Value $remaining -Encoding ASCII
    } elseif (Test-Path -LiteralPath $script:spoolFile) {
        Remove-Item -LiteralPath $script:spoolFile -Force
    }
}

if (-not $script:dockerExe) {
    Write-WatchdogLog 'docker_cli_not_found'
    exit 1
}

Write-WatchdogLog "started task=$taskName"
while ($true) {
    $script:pendingEvents = New-Object System.Collections.ArrayList
    try {
        & $script:dockerExe info --format '{{.ServerVersion}}' 2>$null | Out-Null
        if ($LASTEXITCODE -ne 0) {
            Add-Incident 'docker_daemon_unavailable'
            Queue-PendingEvents
            Start-Sleep -Seconds 60
            continue
        }

        Start-StoppedContainer ($containerSpecs[0])
        $databaseReady = Get-DatabaseReady
        if (-not $databaseReady) { Add-Incident 'database_health_failed' }
        if ($databaseReady) {
            for ($index = 1; $index -lt $containerSpecs.Count; $index++) { Start-StoppedContainer $containerSpecs[$index] }
        }

        $webState = Get-ContainerState 'siges-dashboard-web'
        if ($databaseReady -and $null -ne $webState -and $webState.Status -eq 'running') {
            $port = Get-WebPort
            if ($port) {
                try {
                    $null = Invoke-WebRequest -Uri "http://127.0.0.1:$port/login.php" -UseBasicParsing -TimeoutSec 5
                } catch {
                    Add-Incident 'web_http_health_failed'
                }
            } else {
                Add-Incident 'web_http_health_failed'
            }

            $snapshotRaw = & $script:dockerExe exec siges-dashboard-web php /var/www/app/scripts/watchdog-status.php 2>$null
            if ($LASTEXITCODE -eq 0 -and $snapshotRaw) {
                try {
                    $snapshot = ($snapshotRaw -join "`n") | ConvertFrom-Json
                    if ($snapshot.ok) {
                        $syncAge = $snapshot.sync_age_seconds
                        if ($null -eq $syncAge -or [int] $syncAge -gt 30) {
                            if ($null -ne $syncAge -and [int] $syncAge -gt 60 -and (Test-Cooldown 'sync' 600)) {
                                & $script:dockerExe restart siges-dashboard-sync 2>$null | Out-Null
                                if ($LASTEXITCODE -eq 0) { Add-Event 'sync_worker_restarted' } else { Add-Incident 'sync_worker_restart_failed' }
                            } else {
                                Add-Incident 'sync_worker_heartbeat_stale'
                            }
                        }

                        $autoAge = $snapshot.auto_worker_age_seconds
                        if ($null -eq $autoAge -or [int] $autoAge -gt 1800) {
                            $autoEnabled = [bool] $snapshot.auto_enabled
                            if (-not $autoEnabled -and $null -ne $autoAge -and [int] $autoAge -gt 1800 -and (Test-Cooldown 'auto_reminders' 900)) {
                                & $script:dockerExe restart siges-dashboard-auto-reminders 2>$null | Out-Null
                                if ($LASTEXITCODE -eq 0) { Add-Event 'auto_reminders_worker_restarted' } else { Add-Incident 'auto_reminders_worker_restart_failed' }
                            } else {
                                Add-Incident 'auto_reminders_worker_heartbeat_stale'
                            }
                        }
                    }
                } catch {
                    Add-Incident 'database_health_failed'
                }
            } else {
                Add-Incident 'database_health_failed'
            }

        }
        Queue-PendingEvents
    } catch {
        Write-WatchdogLog ('watchdog_exception=' + $_.Exception.GetType().Name)
    }
    Start-Sleep -Seconds 60
}
