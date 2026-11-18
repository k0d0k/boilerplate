<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Api\Patient\Concerns\PresentsPatientApi;
use App\Platform\Access\PermissionKey;
use App\Platform\Api\Support\ApiResponse;
use App\Platform\Branches\Models\TenantBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Consultations\Exceptions\ConsultationException;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Models\ConsultationMessage;
use Modules\Consultations\Services\ConsultationBooking;
use Modules\Consultations\Services\ConsultationManager;
use Modules\Consultations\Services\ConsultationTariffService;

/**
 * Workstream 2 — patient consultation booking, history and cancellation.
 *
 * A patient books a HUMAN expert session (in person or teleconsult) at one
 * active branch of the token's bound clinic; the write goes through the SAME
 * {@see ConsultationManager} the clinic uses, so tenant-local existence and
 * lifecycle guards apply unchanged. The SINA chat session is created through the
 * SINA endpoints instead, so `sina_chat` is never accepted here.
 *
 * Every query is scoped to the token's bound clinic AND the acting identity's
 * OWN patient record, so a foreign id simply resolves to nothing (404) and a
 * consultation of another clinic can never be read or cancelled.
 */
final class ConsultationController extends PatientApiController
{
    use PresentsPatientApi;

    public function index(Request $request): JsonResponse
    {
        $this->auth->authorize(PermissionKey::CONSULTATION_OWN);
        $patient = $this->auth->patient();

        $query = Consultation::query()
            ->with(['branch', 'appointment'])
            ->where('patient_id', $patient->getKey())
            ->orderByDesc('scheduled_at')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $status = (string) $request->query('status');

            if (! in_array($status, Consultation::STATUSES, true)) {
                throw ValidationException::withMessages(['status' => 'Unknown consultation status.']);
            }

            $query->where('status', $status);
        }

        $paginator = $query
            ->paginate($this->perPage($request))
            ->through(fn (Consultation $consultation) => $this->consultationArray($consultation));

        return ApiResponse::paginated($paginator);
    }

    public function show(Request $request, string $consultation): JsonResponse
    {
        $this->auth->authorize(PermissionKey::CONSULTATION_OWN);
        $patient = $this->auth->patient();

        return ApiResponse::ok($this->consultationArray($this->ownConsultation($consultation, (string) $patient->getKey())));
    }

    public function messages(Request $request, string $consultation): JsonResponse
    {
        $this->auth->authorize(PermissionKey::CONSULTATION_OWN);
        $patient = $this->auth->patient();

        $model = $this->ownConsultation($consultation, (string) $patient->getKey());

        $paginator = ConsultationMessage::query()
            ->where('consultation_id', $model->getKey())
            ->orderBy('created_at')
            ->paginate($this->perPage($request))
            ->through(fn (ConsultationMessage $message) => $this->consultationMessageArray($message));

        return ApiResponse::paginated($paginator);
    }

    /**
     * Book a HUMAN expert consultation for the acting patient.
     *
     * `patient_id` is FORCED to the acting identity's own record, so a client
     * can never book for someone else. The branch must be an ACTIVE branch of
     * the bound clinic; the manager re-validates it against the verified tenant
     * database. The disclosed tariff/refund terms are returned with the record.
     */
    public function store(Request $request): JsonResponse
    {
        $this->auth->authorize(PermissionKey::CONSULTATION_OWN);
        $patient = $this->auth->patient();

        $data = $request->validate([
            'branch_id' => ['required', 'string'],
            'channel' => ['required', 'string', Rule::in([
                Consultation::CHANNEL_IN_PERSON,
                Consultation::CHANNEL_TELECONSULT,
            ])],
            'type' => ['nullable', 'string', Rule::in(Consultation::TYPES)],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:500'],
            'practitioner_id' => ['nullable', 'string'],
        ]);

        $branch = TenantBranch::query()->active()->whereKey($data['branch_id'])->first();

        if ($branch === null) {
            throw ValidationException::withMessages([
                'branch_id' => __('lba.patient.consultations.errors.branch'),
            ]);
        }

        try {
            $consultation = app(ConsultationBooking::class)->book(
                $branch,
                [
                    'patient_id' => $patient->getKey(),
                    'branch_id' => $data['branch_id'],
                    'channel' => $data['channel'],
                    'type' => $data['type'] ?? Consultation::TYPE_CONSULTATION,
                    'status' => Consultation::STATUS_SCHEDULED,
                    'scheduled_at' => Carbon::parse($data['scheduled_at']),
                    'reason' => $data['reason'] ?? null,
                ],
                $data['practitioner_id'] ?? null,
                $this->auth->user(),
            );
        } catch (ConsultationException $exception) {
            return ApiResponse::error($exception->errorCode(), $exception->getMessage(), 409);
        }

        return ApiResponse::ok(
            $this->consultationArray($consultation->fresh()->load(['branch', 'appointment'])),
            [],
            201,
        );
    }

    /**
     * Cancel the acting patient's OWN not-yet-started consultation.
     *
     * Cancellation honours the clinic's disclosed policy: it is refused outright
     * when cancellation is not allowed, when the session already started, or
     * when the lifecycle does not permit the transition. The transition runs
     * through the same manager the clinic uses.
     */
    public function cancel(Request $request, string $consultation): JsonResponse
    {
        $this->auth->authorize(PermissionKey::CONSULTATION_OWN);
        $patient = $this->auth->patient();
        $model = $this->ownConsultation($consultation, (string) $patient->getKey());

        $tariff = app(ConsultationTariffService::class);
        $notStarted = $model->status === Consultation::STATUS_SCHEDULED && $model->started_at === null;

        if (! $tariff->cancellableBeforeStart() || ! $notStarted || ! $model->canTransitionTo(Consultation::STATUS_CANCELLED)) {
            return ApiResponse::error('not_cancellable', __('lba.patient.consultations.errors.not_cancellable'), 409);
        }

        $note = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ])['note'] ?? null;

        try {
            $model = app(ConsultationManager::class)->transition(
                $model,
                Consultation::STATUS_CANCELLED,
                $this->auth->user(),
                $note,
            );
        } catch (ConsultationException $exception) {
            return ApiResponse::error($exception->errorCode(), $exception->getMessage(), 409);
        }

        return ApiResponse::ok($this->consultationArray($model->fresh()->load(['branch', 'appointment'])));
    }

    /**
     * Load a consultation that belongs to the acting patient, or 404. A foreign
     * id simply resolves to nothing, so existence is never leaked.
     */
    private function ownConsultation(string $consultationId, string $patientId): Consultation
    {
        $consultation = Consultation::query()
            ->with(['branch', 'appointment'])
            ->whereKey($consultationId)
            ->where('patient_id', $patientId)
            ->first();

        abort_if($consultation === null, 404);

        return $consultation;
    }
}
