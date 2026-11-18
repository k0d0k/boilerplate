# LBA Capture Agent — Windows launcher.
#
# Runs one scan/upload cycle and exits. Use this as the Task Scheduler action
# (see install-windows.ps1 -RegisterScheduledTask).
#
# Usage:
#   powershell -ExecutionPolicy Bypass -File scripts\run-windows.ps1

[CmdletBinding()]
param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$Args
)

$ErrorActionPreference = "Stop"

$bin = Join-Path $env:LOCALAPPDATA "LBACapture\venv\Scripts\lba-capture.exe"

if (-not (Test-Path $bin)) {
    Write-Error "$bin not found — run scripts\install-windows.ps1 first."
    exit 1
}

$arguments = @("run-once")
if ($Args) { $arguments = $Args }

& $bin @arguments
exit $LASTEXITCODE
