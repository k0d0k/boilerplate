<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Api\Patient\Concerns\PresentsPatientApi;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\Contracts\ValidatesTenantScope;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Access\TenantOption;
use App\Platform\Api\PatientApiAuthorizer;
use App\Platform\Api\PatientApiTokenIssuer;
use App\Platform\Api\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Patients\Services\PatientIdentity;

/**
 * Phase 8B — patient API authentication and clinic selection.
 *
 * Login verifies the account password against the central identity, requires a
 * Patient-panel account, and resolves the CLINIC from the account's active
 * memberships only — a clinic id supplied by the client is validated through the
 * same scope validator the UI uses and is never trusted as authority.
 *
 * The issued token is bound to exactly one clinic, so the isolation boundary is
 * the token itself.
 */
final class AuthController extends PatientApiController
{
    use PresentsPatientApi;

    public function __construct(
        PatientApiAuthorizer $auth,
        private readonly ManagesTenantContext $tenants,
        private readonly ValidatesTenantScope $scope,
        private readonly PatientApiTokenIssuer $issuer,
        private readonly PatientIdentity $identity,
    ) {
        parent::__construct($auth);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'clinic_id' => ['nullable', 'string'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user instanceof User
            || ! $user->is_active
            || ! Hash::check($data['password'], (string) $user->password)) {
            return ApiResponse::error('invalid_credentials', 'The provided credentials are incorrect.', 401);
        }

        if (! PanelAccessPolicy::roleCanAccessPanel($user->role, PanelAccessPolicy::PANEL_PATIENT)) {
            return ApiResponse::error('forbidden', 'This account cannot access the patient API.', 403);
        }

        $clinics = $this->tenants->eligibleOptions($user);

        if ($clinics === []) {
            return ApiResponse::error('no_clinic', __('lba.patient_guard.no_active_clinic'), 403);
        }

        $clinicId = $data['clinic_id'] ?? null;

        if ($clinicId === null && count($clinics) === 1) {
            $clinicId = $clinics[0]->id;
        }

        // Several clinics and no explicit choice: return the eligible list and
        // require the client to choose — a clinic is never picked arbitrarily.
        if ($clinicId === null) {
            return ApiResponse::ok([
                'requires_clinic_selection' => true,
                'clinics' => $this->clinicList($clinics),
            ]);
        }

        if (! $this->scope->isValidFor($user, $clinicId)) {
            return ApiResponse::error('invalid_clinic', 'The selected clinic is not available for this account.', 422);
        }

        return ApiResponse::ok($this->tokenPayload($user, $clinicId, $data['device_name'] ?? 'mobile') + [
            'clinics' => $this->clinicList($clinics),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $this->auth->user();
        $tenantId = $this->auth->tenantId();
        $patient = $this->identity->current($user);

        $clinic = null;
        foreach ($this->tenants->eligibleOptions($user) as $option) {
            if ($option->id === $tenantId) {
                $clinic = $this->clinicArray($option);
                break;
            }
        }

        return ApiResponse::ok([
            'account' => [
                'id' => (string) $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
            ],
            'clinic' => $clinic,
            'patient' => $patient === null ? null : $this->patientArray($patient),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->issuer->revokeCurrent();

        return ApiResponse::ok(['revoked' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenPayload(User $user, string $clinicId, string $deviceName): array
    {
        $issued = $this->issuer->issue($user, $clinicId, $deviceName);

        $clinic = null;
        foreach ($this->tenants->eligibleOptions($user) as $option) {
            if ($option->id === $clinicId) {
                $clinic = $this->clinicArray($option);
                break;
            }
        }

        return [
            'requires_clinic_selection' => false,
            'token' => $issued['plain'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['token']->expires_at?->toIso8601String(),
            'clinic' => $clinic,
        ];
    }

    /**
     * @param  list<TenantOption>  $clinics
     * @return list<array{id: string, name: string, brand: string|null}>
     */
    private function clinicList(array $clinics): array
    {
        return array_map(fn ($option) => $this->clinicArray($option), $clinics);
    }
}
