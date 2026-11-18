"""Synthetic mock adapter.

This adapter runs NO model and performs NO inference. It returns clearly-marked
SYNTHETIC reference output (``synthetic = true``; labels prefixed
``synthetic_``; quality flags ``synthetic`` / ``not_a_diagnosis``). It NEVER
asserts, implies or fabricates a diagnosis.

It exists so the authenticated transport, strict schema validation and Laravel
persistence can be exercised safely, and it is never presented to an operator
as a real result.
"""

from __future__ import annotations

from ..config import Settings
from ..schemas import AnalyzeRequest, AnalyzeResponse, Detection, ModelInfo


class MockVisionAdapter:
    provider = "mock"

    def model_info(self, settings: Settings) -> tuple[str, str]:
        return settings.model_name, settings.model_version

    def analyze(self, request: AnalyzeRequest, settings: Settings) -> AnalyzeResponse:
        name, version = self.model_info(settings)

        return AnalyzeResponse(
            protocol_version=request.protocol_version,
            provider=self.provider,
            model=ModelInfo(name=name, version=version),
            synthetic=True,
            detections=[
                Detection(
                    label="synthetic_reference_marker",
                    type="rectangle",
                    geometry={"x": 0.10, "y": 0.12, "width": 0.24, "height": 0.20},
                    confidence=0.50,
                    quality_flags=["synthetic", "not_a_diagnosis"],
                ),
                Detection(
                    label="synthetic_secondary_marker",
                    type="point",
                    geometry={"x": 0.62, "y": 0.58},
                    confidence=0.50,
                    quality_flags=["synthetic", "not_a_diagnosis"],
                ),
            ],
        )
