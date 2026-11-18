<?php

namespace App\Http\Controllers\Principal;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Http\Controllers\Controller;
use App\Platform\Access\PermissionKey;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * WS4.4 — Principal "Audit Sistem" TailAdmin surface.
 *
 * A READ-ONLY, redacted view of the central module/authorization audit trail
 * (`module_audit_logs`) rendered inside the shared TailAdmin shell. Each record
 * carries only a module key, an action, an outcome, an optional reason and
 * opaque scoped references — never a patient, an examination, an image or a
 * clinical finding. No record may be created, edited or deleted through this
 * surface.
 *
 * Authorization is re-derived on the SERVER: viewing requires
 * `platform.audit.view`.
 */
final class AuditLogController extends Controller
{
    use AuthorizesPrincipalResources;

    /** @var list<string> */
    private const RESULTS = [ModuleAuditLog::RESULT_ALLOWED, ModuleAuditLog::RESULT_DENIED];

    private const PER_PAGE = 25;

    public function index(Request $request): View
    {
        abort_unless(self::principalCan(PermissionKey::PLATFORM_AUDIT_VIEW), 403);

        $filters = $request->validate([
            'result' => ['nullable', Rule::in(self::RESULTS)],
            'action' => ['nullable', 'string', 'max:255'],
            'module_key' => ['nullable', 'string', 'max:255'],
        ]);

        $result = (string) ($filters['result'] ?? '');
        $action = (string) ($filters['action'] ?? '');
        $moduleKey = (string) ($filters['module_key'] ?? '');

        $logs = ModuleAuditLog::query()
            ->with('actor')
            ->when($result !== '', static fn (Builder $query) => $query->where('result', $result))
            ->when($action !== '', static fn (Builder $query) => $query->where('action', $action))
            ->when($moduleKey !== '', static fn (Builder $query) => $query->where('module_key', $moduleKey))
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE)
            ->appends(array_filter($filters, static fn ($value): bool => $value !== null && $value !== ''));

        return view('principal.audit.index', [
            'logs' => $logs,
            'result' => $result,
            'action' => $action,
            'moduleKey' => $moduleKey,
            'actions' => self::distinctValues('action'),
            'moduleKeys' => self::distinctValues('module_key'),
        ]);
    }

    /**
     * Distinct, non-null values of a filterable column, as select options.
     *
     * @return array<string, string>
     */
    private static function distinctValues(string $column): array
    {
        return ModuleAuditLog::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all();
    }
}
