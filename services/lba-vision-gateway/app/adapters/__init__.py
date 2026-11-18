"""Inference adapters.

The gateway dispatches to an adapter by provider name. Only the synthetic
``mock`` adapter ships enabled; a real image-capable model adapter may be added
later, but it must be validated before it is ever enabled in production.
"""

from __future__ import annotations

from typing import Protocol

from ..config import Settings
from ..schemas import AnalyzeRequest, AnalyzeResponse


class VisionAdapter(Protocol):
    """A vision inference adapter."""

    provider: str

    def analyze(self, request: AnalyzeRequest, settings: Settings) -> AnalyzeResponse:
        """Return a structured result, or raise to signal failure."""

    def model_info(self, settings: Settings) -> tuple[str, str]:
        """Return (model_name, model_version) reported to the caller."""


def resolve_adapter(provider: str) -> VisionAdapter:
    """Resolve an adapter by name. Unknown providers fail closed."""

    from .mock import MockVisionAdapter

    if provider == MockVisionAdapter.provider:
        return MockVisionAdapter()

    raise ValueError(
        f"unknown provider [{provider}]: only the synthetic mock adapter is "
        "enabled in this reference service"
    )


__all__ = ["VisionAdapter", "resolve_adapter"]
