<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Api\Patient\Concerns\PresentsPatientApi;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\Contracts\ValidatesTenantScope;
use App\Platform\Api\PatientApiAuthorizer;
use App\Platform\Api\PatientApiTokenIssuer;
use App\Platform\Api\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8B — patient clinic selection.
 *
 * Lists ONLY the clinics the account holds an active membership for, and lets
 * the patient re-scope the session by exchanging their current token for one
 * bound to the chosen clinic. Eligibility is re-derived from central data, so a
 * foreign or suspended clinic can never be selected.
 */
final class ClinicController extends PatientApiController
{
    use PresentsPatientApi;

    public function __construct(
        PatientApiAuthorizer $auth,
        private readonly ManagesTenantContext $tenants,
        private readonly ValidatesTenantScope $scope,
        private readonly PatientApiTokenIssuer $issuer,
    ) {
        parent::__construct($auth);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->auth->user();

        $clinics = array_map(
            fn ($option) => $this->clinicArray($option),
            $this->tenants->eligibleOptions($user),
        );

        return ApiResponse::ok($clinics, ['current_clinic_id' => $this->auth->tenantId()]);
    }

    public function select(Request $request): JsonResponse
    {
        $data = $request->validate([
            'clinic_id' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ]);

        $user = $this->auth->user();

        if (! $this->scope->isValidFor($user, $data['clinic_id'])) {
            return ApiResponse::error('invalid_clinic', 'The selected clinic is not available for this account.', 422);
        }

        $issued = $this->issuer->issue($user, $data['clinic_id'], $data['device_name'] ?? 'mobile');

        return ApiResponse::ok([
            'token' => $issued['plain'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['token']->expires_at?->toIso8601String(),
        ]);
    }
}
