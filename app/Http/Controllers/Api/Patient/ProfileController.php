<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Api\Patient\Concerns\PresentsPatientApi;
use App\Platform\Api\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8B — patient profile (self-service).
 *
 * Read/write is always the acting identity's OWN patient record, resolved
 * server-side from the bound clinic database — never a client id. Only the
 * contact fields a patient may legitimately self-serve are editable; the
 * clinical-of-record fields (`full_name`, `date_of_birth`, `sex`/`gender`, the
 * patient number, the clinic registration) stay read-only, exactly like the
 * panel.
 */
final class ProfileController extends PatientApiController
{
    use PresentsPatientApi;

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->patientArray($this->auth->patient()));
    }

    public function update(Request $request): JsonResponse
    {
        $patient = $this->auth->patient();

        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
        ]);

        $patient->update($validated);

        return ApiResponse::ok($this->patientArray($patient->fresh()));
    }
}
