<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Controller;
use App\Platform\Api\PatientApiAuthorizer;
use Illuminate\Http\Request;

/**
 * Phase 8B — shared base for the patient REST API controllers.
 *
 * Holds the server-side authorizer (identity + clinic scope + permissions) and
 * the bounded pagination contract used by every list endpoint.
 */
abstract class PatientApiController extends Controller
{
    public function __construct(protected readonly PatientApiAuthorizer $auth) {}

    /**
     * A safe page size, clamped between 1 and the configured maximum so a client
     * can never request an unbounded page.
     */
    protected function perPage(Request $request): int
    {
        $default = (int) config('lba.patient_api.per_page_default', 15);
        $max = (int) config('lba.patient_api.per_page_max', 50);

        return max(1, min((int) $request->integer('per_page', $default), $max));
    }
}
