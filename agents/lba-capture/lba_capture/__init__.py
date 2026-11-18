"""LBA Cloud microscope export-folder capture agent.

A small, portable companion application that watches a configured
manufacturer-software export directory, stabilises completed JPEG/PNG/WebP
files, spools them locally and uploads them to the LBA Cloud scoped ingest
endpoint using a short-lived, revocable bearer credential.

Design constraints (WS6.1 P1):

* Python 3.11+ with only the standard library (``keyring`` is an OPTIONAL
  enhancement for OS secure storage).
* The bearer credential is NEVER written to the config file, to logs or to
  Git; it lives in the OS secure store (keyring) or, when the keyring is
  unavailable, in a permission-restricted file.
* The agent never chooses a tenant, patient or examination: that scope comes
  solely from the server-issued credential. Filenames are treated as opaque
  labels and are never parsed for identifiers.
* The agent only writes inside its own config/state/spool directories. It never
  writes to a web-served directory, never streams video and never syncs to any
  third-party drive.
"""

__all__ = ["__version__"]

__version__ = "1.0.0"
