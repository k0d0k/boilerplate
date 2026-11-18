<?php

namespace App\Filament\Principal\Widgets;

use App\Models\PrincipalRoleAssignment;
use App\Models\User;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use Filament\Widgets\Widget;

/**
 * WS4.4 — Principal security posture summary.
 *
 * A read-only overview of central security signals, computed from the redacted
 * audit log and the central identity tables only:
 *
 *  - denied authorizations in the last 30 days (a rising count is a signal);
 *  - total audit events in the last 30 days;
 *  - governance identities holding an active principal role;
 *  - suspended identities.
 *
 * Presentation reuses the shared premium section partial (list variant), so the
 * card shell matches every other governance card. No clinical data is read.
 */
class SecurityPostureWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 1;

    /**
     * @var int | string | array<string, int | null>
     */
    protected int|string|array $columnSpan = 1;

    /**
     * @var view-string
     */
    protected string $view = 'filament.lba.widgets.section';

    protected function getViewData(): array
    {
        $since = now()->subDays(30);

        $denied = ModuleAuditLog::query()
            ->where('result', ModuleAuditLog::RESULT_DENIED)
            ->where('created_at', '>=', $since)
            ->count();

        $events = ModuleAuditLog::query()
            ->where('created_at', '>=', $since)
            ->count();

        $activePrincipals = PrincipalRoleAssignment::query()
            ->where('status', PrincipalRoleAssignment::STATUS_ACTIVE)
            ->distinct()
            ->count('user_id');

        $suspended = User::query()->where('is_active', false)->count();

        return [
            'variant' => 'list',
            'heading' => __('lba.audit.security_heading'),
            'description' => __('lba.audit.security_description'),
            'items' => [
                [
                    'title' => __('lba.audit.security_denied'),
                    'subtitle' => __('lba.audit.security_denied_value', ['count' => number_format($denied)]),
                    'meta' => '',
                    'badge' => [
                        'label' => $denied === 0 ? __('lba.audit.state_clear') : __('lba.audit.state_attention'),
                        'tone' => $denied === 0 ? 'teal' : 'rose',
                    ],
                ],
                [
                    'title' => __('lba.audit.security_events'),
                    'subtitle' => __('lba.audit.security_events_value', ['count' => number_format($events)]),
                    'meta' => '',
                    'badge' => [
                        'label' => __('lba.audit.state_normal'),
                        'tone' => 'teal',
                    ],
                ],
                [
                    'title' => __('lba.audit.security_principals'),
                    'subtitle' => __('lba.audit.security_principals_value', ['count' => number_format($activePrincipals)]),
                    'meta' => '',
                    'badge' => [
                        'label' => __('lba.audit.state_normal'),
                        'tone' => 'teal',
                    ],
                ],
                [
                    'title' => __('lba.audit.security_suspended'),
                    'subtitle' => __('lba.audit.security_suspended_value', ['count' => number_format($suspended)]),
                    'meta' => '',
                    'badge' => [
                        'label' => $suspended === 0 ? __('lba.audit.state_clear') : __('lba.audit.state_attention'),
                        'tone' => $suspended === 0 ? 'teal' : 'rose',
                    ],
                ],
            ],
            'primary' => null,
            'secondary' => null,
            'progress' => null,
            'progressLabel' => null,
            'note' => null,
        ];
    }
}
