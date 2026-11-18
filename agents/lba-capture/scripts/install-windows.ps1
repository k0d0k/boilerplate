# LBA Capture Agent — Windows installer (per-user, no admin required).
#
# Creates a virtual environment under %LOCALAPPDATA%\LBACapture\venv,
# installs the agent (+ keyring if available) and, optionally, registers a
# Task Scheduler job that runs one scan/upload cycle every few minutes.
#
# Usage:
#   powershell -ExecutionPolicy Bypass -File scripts\install-windows.ps1
#   powershell -ExecutionPolicy Bypass -File scripts\install-windows.ps1 -RegisterScheduledTask

[CmdletBinding()]
param(
    [switch]$RegisterScheduledTask,
    [int]$IntervalMinutes = 5
)

$ErrorActionPreference = "Stop"

$ScriptDir  = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectDir = Split-Path -Parent $ScriptDir
$DataDir    = Join-Path $env:LOCALAPPDATA "LBACapture"
$VenvDir    = Join-Path $DataDir "venv"

Write-Host "LBA Capture Agent — Windows install"
Write-Host "Project: $ProjectDir"
Write-Host "Data:    $DataDir"
Write-Host ""

# Locate a Python 3.11+ interpreter.
$python = $null
foreach ($candidate in @("py -3.13", "py -3.12", "py -3.11", "python", "python3")) {
    try {
        $parts = $candidate.Split(" ")
        $exe = $parts[0]
        $exeArgs = @()
        if ($parts.Length -gt 1) { $exeArgs += $parts[1..($parts.Length - 1)] }
        $version = & $exe @exeArgs -c "import sys; print('%d.%d' % sys.version_info[:2])" 2>$null
        if ($LASTEXITCODE -eq 0 -and $version) {
            $major, $minor = $version.Split(".")
            if ([int]$major -eq 3 -and [int]$minor -ge 11) {
                $python = @{ Exe = $exe; Args = $exeArgs; Version = $version }
                break
            }
        }
    } catch { }
}

if (-not $python) {
    Write-Error "Python 3.11 or newer was not found. Install it from https://www.python.org/downloads/windows/ and re-run."
    exit 1
}

Write-Host "Using Python $($python.Version)"
New-Item -ItemType Directory -Force -Path $DataDir | Out-Null

if (-not (Test-Path $VenvDir)) {
    & $python.Exe @($python.Args) -m venv $VenvDir
}

$venvPython = Join-Path $VenvDir "Scripts\python.exe"
& $venvPython -m pip install --upgrade pip | Out-Null
try {
    & $venvPython -m pip install -e "$ProjectDir[secure]"
} catch {
    & $venvPython -m pip install -e $ProjectDir
}

$bin = Join-Path $VenvDir "Scripts\lba-capture.exe"
Write-Host ""
Write-Host "Installed. The lba-capture command is at:"
Write-Host "  $bin"
Write-Host ""
Write-Host "Next:"
Write-Host "  1. issue an ingestion credential for the target examination in LBA Cloud;"
Write-Host "  2. & '$bin' setup --base-url https://dev.lbacloud.com --watch-dir 'C:\Exports'"
Write-Host "  3. & '$bin' start --detach"
Write-Host "  4. & '$bin' status"

if ($RegisterScheduledTask) {
    $taskName = "LBACaptureAgent"
    $action = New-ScheduledTaskAction -Execute $bin -Argument "run-once"
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) `
        -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes)
    $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger `
        -Settings $settings -Force | Out-Null
    Write-Host ""
    Write-Host "Registered scheduled task '$taskName' (every $IntervalMinutes minutes)."
}
