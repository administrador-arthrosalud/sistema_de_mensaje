$ErrorActionPreference = 'Stop'
$taskName = 'Chatbot-Incident-Watchdog'
$watchdog = Join-Path $PSScriptRoot 'watchdog.ps1'
if (-not (Test-Path -LiteralPath $watchdog)) { throw 'No se encuentra scripts/watchdog.ps1.' }

$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$powershellExe = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$arguments = '-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "{0}"' -f $watchdog
$action = New-ScheduledTaskAction -Execute $powershellExe -Argument $arguments
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $identity
$principal = New-ScheduledTaskPrincipal -UserId $identity -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -RestartCount 5 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero)

Register-ScheduledTask -TaskName $taskName -Description 'Vigila los contenedores y latidos de Chatbot; encola avisos técnicos y recupera workers de forma acotada.' -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
Start-ScheduledTask -TaskName $taskName
Get-ScheduledTask -TaskName $taskName | Select-Object -Property TaskName, State
