"""Request / response schemas for the gateway protocol (version 1).

The response deliberately mirrors Laravel's strict validator: only
``protocol_version``, ``provider``, ``model``, ``synthetic`` and ``detections``
are accepted, each detection carries only ``label``, ``type``, ``geometry``,
``confidence`` and ``quality_flags``, and all geometry is normalised to 0..1.
"""

from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, Field


class AnalyzeRequest(BaseModel):
    """One opaque analysis request. Carries no patient identity."""

    job_reference: str = Field(min_length=1, max_length=128)
    image_reference: str = Field(min_length=1, max_length=128)
    image_base64: str = Field(min_length=1)
    checksum: str = Field(min_length=1, max_length=128)
    content_type: str = Field(min_length=1, max_length=128)
    protocol_version: str = Field(min_length=1, max_length=16)


class ModelInfo(BaseModel):
    name: str
    version: str


class Detection(BaseModel):
    label: str
    type: Literal["point", "rectangle", "polygon"]
    geometry: dict[str, Any]
    confidence: float = Field(ge=0.0, le=1.0)
    quality_flags: list[str] = Field(default_factory=list)


class AnalyzeResponse(BaseModel):
    protocol_version: str
    provider: str
    model: ModelInfo
    synthetic: bool
    detections: list[Detection]


class HealthResponse(BaseModel):
    status: Literal["ok"]
    service: str
    provider: str
    model: ModelInfo
    synthetic: bool
    authenticated: bool
