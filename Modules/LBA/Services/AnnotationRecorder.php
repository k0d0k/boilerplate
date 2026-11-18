<?php

namespace Modules\LBA\Services;

use App\Models\User;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Models\Annotation;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;

/**
 * Phase 4 — manual annotation recording (Modules/LBA).
 *
 * A manual annotation is authored by a human with NO AI job: `job_id` stays
 * null and `provenance` is `human`, so the whole path works with the AI stack
 * offline. Geometry is stored NORMALISED against the original image (0..1), so
 * a stored position never changes when the viewer zooms, pans or resizes.
 */
final class AnnotationRecorder
{
    use RunsVerifiedTenantTransactions;

    public function __construct(private readonly ExaminationWorkflow $workflow) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Examination $examination, ExaminationImage $image, array $data, ?User $actor = null): Annotation
    {
        $this->assertEditable($examination);
        $this->assertImageOnExamination($examination, $image);

        $type = (string) ($data['type'] ?? '');
        $geometry = $this->normaliseGeometry($type, $data['geometry'] ?? null);

        return $this->tenantTransaction(function () use ($examination, $image, $data, $type, $geometry, $actor): Annotation {
            $annotation = Annotation::query()->create([
                'examination_id' => $examination->getKey(),
                'image_id' => $image->getKey(),
                'job_id' => null,
                'provenance' => Annotation::PROVENANCE_HUMAN,
                'type' => $type,
                'geometry' => $geometry,
                'label' => $data['label'] ?? null,
                'notes' => $data['notes'] ?? null,
                'actor_id' => $actor?->getKey(),
                'revision' => 1,
            ]);

            if (in_array($examination->status, [
                Examination::STATUS_UPLOADED,
                Examination::STATUS_RETURNED_FOR_CHANGES,
            ], true)) {
                $this->workflow->transition($examination, Examination::STATUS_MANUAL_ANNOTATION, $actor);
            }

            return $annotation;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Annotation $annotation, array $data, ?User $actor = null): Annotation
    {
        $examination = $annotation->examination()->firstOrFail();

        $this->assertEditable($examination);

        if (! $annotation->isManual()) {
            throw ExaminationException::invalidAnnotation('only a manual annotation may be edited here');
        }

        return $this->tenantTransaction(function () use ($annotation, $data): Annotation {
            $type = (string) ($data['type'] ?? $annotation->type);
            $geometry = $this->normaliseGeometry($type, $data['geometry'] ?? $annotation->geometry);

            $annotation->forceFill([
                'type' => $type,
                'geometry' => $geometry,
                'label' => $data['label'] ?? $annotation->label,
                'notes' => $data['notes'] ?? $annotation->notes,
                'revision' => ((int) $annotation->revision) + 1,
            ])->save();

            return $annotation;
        });
    }

    public function delete(Annotation $annotation): void
    {
        $examination = $annotation->examination()->firstOrFail();

        $this->assertEditable($examination);

        if (! $annotation->isManual()) {
            throw ExaminationException::invalidAnnotation('only a manual annotation may be deleted here');
        }

        $annotation->delete();
    }

    private function assertEditable(Examination $examination): void
    {
        if (! $examination->isEditable()) {
            throw ExaminationException::notEditable((string) $examination->status);
        }
    }

    private function assertImageOnExamination(Examination $examination, ExaminationImage $image): void
    {
        if ((string) $image->examination_id !== (string) $examination->getKey()) {
            throw ExaminationException::imageNotOnExamination();
        }
    }

    /**
     * Validate + normalise geometry for the given annotation type.
     *
     * @return array<string, mixed>
     */
    private function normaliseGeometry(string $type, mixed $geometry): array
    {
        if (! in_array($type, Annotation::TYPES, true)) {
            throw ExaminationException::invalidAnnotation('unknown annotation type');
        }

        if (! is_array($geometry)) {
            throw ExaminationException::invalidAnnotation('missing geometry');
        }

        return match ($type) {
            Annotation::TYPE_POINT => [
                'x' => $this->unit($geometry['x'] ?? null),
                'y' => $this->unit($geometry['y'] ?? null),
            ],
            Annotation::TYPE_RECTANGLE => $this->normaliseRectangle($geometry),
            Annotation::TYPE_POLYGON => $this->normalisePolygon($geometry),
        };
    }

    /**
     * @param  array<string, mixed>  $geometry
     * @return array<string, float>
     */
    private function normaliseRectangle(array $geometry): array
    {
        $x = $this->unit($geometry['x'] ?? null);
        $y = $this->unit($geometry['y'] ?? null);
        $width = $this->unit($geometry['width'] ?? null);
        $height = $this->unit($geometry['height'] ?? null);

        // Keep the rectangle inside the image bounds after clamping.
        $width = min($width, 1.0 - $x);
        $height = min($height, 1.0 - $y);

        return ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];
    }

    /**
     * @param  array<string, mixed>  $geometry
     * @return array{points: list<array{float, float}>}
     */
    private function normalisePolygon(array $geometry): array
    {
        $points = $geometry['points'] ?? null;

        if (! is_array($points) || count($points) < 3) {
            throw ExaminationException::invalidAnnotation('a polygon needs at least three points');
        }

        $normalised = [];

        foreach ($points as $point) {
            if (! is_array($point)) {
                throw ExaminationException::invalidAnnotation('a polygon point must be a coordinate pair');
            }

            $normalised[] = [
                $this->unit($point[0] ?? $point['x'] ?? null),
                $this->unit($point[1] ?? $point['y'] ?? null),
            ];
        }

        /** @var list<array{float, float}> $normalised */
        return ['points' => $normalised];
    }

    /**
     * Coerce a value to a float inside [0,1].
     */
    private function unit(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw ExaminationException::invalidAnnotation('a coordinate was not numeric');
        }

        return max(0.0, min(1.0, (float) $value));
    }
}
