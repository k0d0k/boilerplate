#!/bin/bash
#
# LBA Capture Agent — macOS launcher used by launchd.
#
# Runs a single scan/upload cycle and exits. Wire this to launchd
# (scripts/com.lbacloud.capture.plist) or call it from cron.
#
set -euo pipefail

VENV_DIR="${HOME}/Library/Application Support/LBACapture/venv"
BIN="${VENV_DIR}/bin/lba-capture"

if [ ! -x "${BIN}" ]; then
  echo "error: ${BIN} not found — run scripts/install-macos.command first." >&2
  exit 1
fi

exec "${BIN}" "$@"
