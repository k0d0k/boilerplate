<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Api\Patient\Concerns\PresentsPatientApi;
use App\Platform\Access\PermissionKey;
use App\Platform\Api\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Consultations\Models\SinaUsageRecord;
use Modules\Consultations\Services\SinaWalletService;

final class TokenWalletController extends PatientApiController
{
    use PresentsPatientApi;

    public function show(Request $request): JsonResponse
    {
        $this->auth->authorize($request->routeIs('api.patient.sina.*')
            ? PermissionKey::CONSULTATION_OWN
            : PermissionKey::WALLET_OWN);
        $patientId = (string) $this->auth->patient()->getKey();
        $tenantId = $this->auth->tenantId();

        $usage = SinaUsageRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('patient_id', $patientId);

        return ApiResponse::ok([
            'wallet' => $this->sinaWalletArray(app(SinaWalletService::class)->walletFor($tenantId, $patientId)),
            'usage_total_credits' => (int) $usage->clone()->sum('credit_units'),
            'usage_turns' => $usage->count(),
        ]);
    }

    public function usage(Request $request): JsonResponse
    {
        $this->auth->authorize($request->routeIs('api.patient.sina.*')
            ? PermissionKey::CONSULTATION_OWN
            : PermissionKey::WALLET_OWN);
        $patientId = (string) $this->auth->patient()->getKey();

        $paginator = SinaUsageRecord::query()
            ->where('tenant_id', $this->auth->tenantId())
            ->where('patient_id', $patientId)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->through(fn (SinaUsageRecord $record) => $this->sinaUsageArray($record));

        return ApiResponse::paginated($paginator);
    }
}
