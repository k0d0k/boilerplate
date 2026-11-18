"""Command-line interface: setup, status, start/stop, run-once, revoke.

Every command is non-interactive unless it must prompt for the bearer secret,
which is read with ``getpass`` (never echoed, never dropped to shell history).
"""

from __future__ import annotations

import argparse
import getpass
import os
import subprocess
import sys
from pathlib import Path

from . import __version__
from .agent import CaptureAgent
from .config import (
    AgentConfig,
    default_config_path,
    default_log_dir,
    default_spool_dir,
    default_state_dir,
)
from .credentials import KEYRING_SERVICE, resolve_store
from .logging_setup import configure_logging
from .status import PidFile, Status, process_alive


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="lba-capture",
        description="LBA Cloud microscope export-folder capture agent",
    )
    parser.add_argument("--config", default=str(default_config_path()), help="path to the (non-secret) config file")
    parser.add_argument("--verbose", action="store_true", help="verbose logging")

    sub = parser.add_subparsers(dest="command", required=True)

    setup = sub.add_parser("setup", help="configure the agent and store the bearer credential securely")
    setup.add_argument("--base-url", help="LBA Cloud base URL, e.g. https://dev.lbacloud.com")
    setup.add_argument("--watch-dir", help="microscope export directory to watch")
    setup.add_argument("--instrument-label", help="human-readable device label recorded on intake")
    setup.add_argument("--token-stdin", action="store_true", help="read the bearer secret from standard input (no echo)")
    setup.add_argument("--no-keyring", action="store_true", help="force the permission-restricted file store")

    sub.add_parser("status", help="show configuration, credential presence and local backlog")
    sub.add_parser("run-once", help="scan and upload pending files once, then exit (cron / Task Scheduler)")

    start = sub.add_parser("start", help="run the agent (foreground, or --detach in the background)")
    start.add_argument("--detach", action="store_true", help="run in the background and write a pid file")
    start.add_argument("--max-cycles", type=int, default=None, help="stop after N cycles (testing aid)")

    sub.add_parser("stop", help="stop a detached agent")
    sub.add_parser("restart", help="stop a detached agent, then start it again")
    sub.add_parser("revoke", help="remove the local credential and print server-side revocation steps")
    sub.add_parser("version", help="print the agent version")

    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    config_path = Path(args.config)

    if args.command == "version":
        print(f"lba-capture {__version__}")
        return 0

    if args.command == "setup":
        return _cmd_setup(args, config_path)

    config = _load_config(config_path)
    if config is None:
        return 2

    if args.command == "status":
        return _cmd_status(config)

    if args.command == "revoke":
        return _cmd_revoke(config)

    if args.command in ("run-once", "start"):
        return _cmd_run(args, config, config_path=config_path)

    if args.command == "stop":
        return _cmd_stop(config)

    if args.command == "restart":
        _cmd_stop(config)
        return _cmd_run(args, config, config_path=config_path, force_detach=True)

    print(f"unknown command: {args.command}", file=sys.stderr)
    return 2


# --------------------------------------------------------------------- setup


def _cmd_setup(args: argparse.Namespace, config_path: Path) -> int:
    existing = _load_config(config_path, required=False) or AgentConfig()

    base_url = args.base_url or existing.base_url or _prompt("LBA Cloud base URL", default="https://dev.lbacloud.com")
    watch_dir = args.watch_dir or existing.watch_dir or _prompt("Microscope export directory to watch")

    if not watch_dir:
        print("error: a watch directory is required", file=sys.stderr)
        return 2

    if not Path(watch_dir).is_dir():
        print(f"error: watch directory does not exist: {watch_dir}", file=sys.stderr)
        return 2

    config = AgentConfig(
        base_url=base_url.rstrip("/"),
        watch_dir=str(Path(watch_dir).expanduser().resolve()),
        spool_dir=str(default_spool_dir()),
        state_dir=str(default_state_dir()),
        log_dir=str(default_log_dir()),
    )
    config.extensions = list(existing.extensions)

    if args.instrument_label:
        config.instrument_label = args.instrument_label

    problems = config.validate()
    if problems:
        for problem in problems:
            print(f"error: {problem}", file=sys.stderr)
        return 2

    config.ensure_directories()
    config.save(config_path)

    secret = _read_secret(args)
    if not secret:
        print("error: no bearer secret supplied", file=sys.stderr)
        return 2

    store = resolve_store(prefer_keyring=not args.no_keyring)
    store.set(secret.strip())

    print(f"Configured {config_path}")
    print(f"Watch directory: {config.watch_dir}")
    print(f"Credential stored via: {store.backend}")
    print("Next: `lba-capture start --detach` (or `lba-capture status`).")
    return 0


def _read_secret(args: argparse.Namespace) -> str:
    if getattr(args, "token_stdin", False):
        return sys.stdin.readline().strip()

    return getpass.getpass("Paste the bearer secret issued for this examination (input hidden): ").strip()


# -------------------------------------------------------------------- status


def _cmd_status(config: AgentConfig) -> int:
    store = resolve_store()
    secret = store.get()
    status = Status.load(config.path_status())
    pid = PidFile(config.path_pid()).read()
    running = process_alive(pid)

    print(f"lba-capture {__version__}")
    print(f"Base URL         : {config.base_url or '(unset)'}")
    print(f"Watch directory  : {config.watch_dir or '(unset)'}")
    print(f"Spool directory  : {config.spool_dir}")
    print(f"Credential       : {'present' if secret else 'MISSING'} (store: {store.backend})")
    print(f"Agent            : {'running (pid ' + str(pid) + ')' if running else 'not running'}")
    print(f"State            : {status.state}")
    print(f"Spool pending    : {status.spool_pending}")
    print(f"Spool failed     : {status.spool_failed}")
    print(f"Uploaded         : {status.uploaded}")
    print(f"Duplicates       : {status.duplicates}")
    print(f"Rejected         : {status.rejected}")
    if status.last_error:
        print(f"Last error       : {status.last_error}")
    if status.note:
        print(f"Note             : {status.note}")

    return 0


# ----------------------------------------------------------------- run/start


def _cmd_run(
    args: argparse.Namespace,
    config: AgentConfig,
    *,
    config_path: Path | None = None,
    force_detach: bool = False,
) -> int:
    problems = config.validate()
    if problems:
        for problem in problems:
            print(f"error: {problem}", file=sys.stderr)
        return 2

    config.ensure_directories()

    detach = getattr(args, "detach", False) or force_detach
    pidfile = PidFile(config.path_pid())

    if detach:
        existing = pidfile.read()
        if process_alive(existing):
            print(f"agent already running (pid {existing})", file=sys.stderr)
            return 1

        child = _spawn_background(config, config_path or Path(args.config))
        pidfile.write(child.pid)
        print(f"agent started (pid {child.pid}); logs in {config.path_logs()}")
        return 0

    configure_logging(config.path_logs(), verbose=args.verbose)
    agent = CaptureAgent(config, resolve_store())
    _install_signal_handlers(agent)
    max_cycles = getattr(args, "max_cycles", None)
    agent.run(once=args.command == "run-once", max_cycles=max_cycles)
    return 0


def _spawn_background(config: AgentConfig, config_path: Path) -> subprocess.Popen:
    log_path = config.path_logs() / "agent.out.log"
    log_path.parent.mkdir(parents=True, exist_ok=True)
    handle = log_path.open("ab")

    command = [sys.executable, "-m", "lba_capture", "--config", str(config_path), "start"]

    kwargs: dict = {
        "stdout": handle,
        "stderr": handle,
        "stdin": subprocess.DEVNULL,
        "cwd": str(Path(__file__).resolve().parent.parent),
        "close_fds": True,
    }

    if os.name == "nt":
        kwargs["creationflags"] = 0x00000008 | 0x00000200  # DETACHED_PROCESS | CREATE_NEW_PROCESS_GROUP
    else:
        kwargs["start_new_session"] = True

    return subprocess.Popen(command, **kwargs)


# --------------------------------------------------------------------- stop


def _cmd_stop(config: AgentConfig) -> int:
    pidfile = PidFile(config.path_pid())
    pid = pidfile.read()

    if not process_alive(pid):
        pidfile.clear()
        print("agent is not running")
        return 0

    assert pid is not None
    if os.name == "nt":
        subprocess.run(["taskkill", "/PID", str(pid), "/F"], check=False)
    else:
        import signal

        os.kill(pid, signal.SIGTERM)

    pidfile.clear()
    print(f"stopped agent (pid {pid})")
    return 0


# -------------------------------------------------------------------- revoke


def _cmd_revoke(config: AgentConfig) -> int:
    store = resolve_store()
    store.clear()
    print("Local credential removed from this device.")
    print()
    print("Server-side: the credential is SHORT LIVED and stops being accepted")
    print("automatically once it expires (default 60 minutes after issuance).")
    print("No action is needed to retire it, and none of the images already")
    print("stored are affected.")
    print()
    print("If it must be made unusable immediately, a platform administrator can")
    print("revoke the credential through the LBA ingestion-credential service")
    print("(IngestCredentialIssuer::revoke); revocation is audited and takes")
    print("effect on the very next upload.")
    print(f"(OS secure-store service: {KEYRING_SERVICE})")
    return 0


# --------------------------------------------------------------------- utils


def _load_config(path: Path, *, required: bool = True) -> AgentConfig | None:
    if not path.exists():
        if required:
            print(f"error: no configuration at {path} (run `lba-capture setup`)", file=sys.stderr)
            return None
        return None

    try:
        return AgentConfig.load(path)
    except (OSError, ValueError) as error:
        print(f"error: could not read {path}: {error}", file=sys.stderr)
        return None


def _prompt(label: str, *, default: str = "") -> str:
    suffix = f" [{default}]" if default else ""
    answer = input(f"{label}{suffix}: ").strip()
    return answer or default


def _install_signal_handlers(agent: CaptureAgent) -> None:
    import signal

    def _handler(signum, frame):
        agent.stop()

    for name in ("SIGINT", "SIGTERM"):
        if hasattr(signal, name):
            try:
                signal.signal(getattr(signal, name), _handler)
            except (ValueError, OSError):
                pass
