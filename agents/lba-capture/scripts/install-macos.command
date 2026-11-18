#!/bin/bash
#
# LBA Capture Agent — macOS installer (per-user, no sudo).
#
# Creates a virtual environment under
#   ~/Library/Application Support/LBACapture/venv
# installs the agent (+ keyring if available), and prints next steps.
#
# Usage:  ./install-macos.command
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
DATA_DIR="${HOME}/Library/Application Support/LBACapture"
VENV_DIR="${DATA_DIR}/venv"

echo "LBA Capture Agent — macOS install"
echo "Project: ${PROJECT_DIR}"
echo "Data:    ${DATA_DIR}"
echo

# Pick a Python 3.11+ interpreter.
PYTHON=""
for candidate in python3.13 python3.12 python3.11 python3; do
  if command -v "${candidate}" >/dev/null 2>&1; then
    if "${candidate}" -c 'import sys; raise SystemExit(0 if sys.version_info >= (3, 11) else 1)' 2>/dev/null; then
      PYTHON="${candidate}"
      break
    fi
  fi
done

if [ -z "${PYTHON}" ]; then
  echo "error: Python 3.11 or newer was not found on PATH." >&2
  echo "Install it from https://www.python.org/downloads/macos/ and re-run." >&2
  exit 1
fi

echo "Using ${PYTHON} ($(${PYTHON} --version 2>&1))"

mkdir -p "${DATA_DIR}"
if [ ! -d "${VENV_DIR}" ]; then
  "${PYTHON}" -m venv "${VENV_DIR}"
fi

# shellcheck disable=SC1091
source "${VENV_DIR}/bin/activate"
python -m pip install --upgrade pip >/dev/null
python -m pip install -e "${PROJECT_DIR}[secure]" || python -m pip install -e "${PROJECT_DIR}"

echo
echo "Installed. The lba-capture command is available at:"
echo "  ${VENV_DIR}/bin/lba-capture"
echo
echo "Add it to your PATH for this shell:"
echo "  export PATH=\"${VENV_DIR}/bin:\$PATH\""
echo
echo "Next:"
echo "  1. issue an ingestion credential for the target examination in LBA Cloud;"
echo "  2. ${VENV_DIR}/bin/lba-capture setup \\"
echo "       --base-url https://dev.lbacloud.com \\"
echo "       --watch-dir \"/path/to/microscope/exports\""
echo "  3. ${VENV_DIR}/bin/lba-capture start --detach"
echo "  4. ${VENV_DIR}/bin/lba-capture status"
echo
echo "To run automatically at login, see scripts/com.lbacloud.capture.plist."
