<?php

namespace Tests\Feature\Filament;

use App\Filament\Principal\Resources\AuditLogResource;
use App\Filament\Principal\Widgets\SecurityPostureWidget;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\TestCase;

/**
 * WS4.4 — Principal audit log and security review.
 *
 * Proves the audit surface lists REAL, redacted central events, is forbidden
 * without `platform.audit.view` and is strictly read-only (no create/edit/
 * delete), and that the security-posture summary reflects denied
 * authorizations and active governance identities.
 */
class PrincipalAuditLogTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->app->setLocale('id');
        Filament::setCurrentPanel(Filament::getPanel('principal'));
    }

    private function audit(string $result = ModuleAuditLog::RESULT_ALLOWED, ?int $actorId = null): ModuleAuditLog
    {
        return ModuleAuditLog::query()->create([
            'module_key' => 'demo.showcase',
            'action' => 'enable',
            'result' => $result,
            'reason' => 'test',
            'scope' => 'tenant',
            'scope_key' => 'tenant-1',
            'actor_id' => $actorId,
        ]);
    }

    // ------------------------------------------------------- Access control

    public function test_the_audit_log_lists_real_events_for_an_authorized_principal(): void
    {
        $actor = $this->principal();
        $this->audit(ModuleAuditLog::RESULT_ALLOWED, $actor->getKey());

        $this->actingAs($this->principal())
            ->get('/principal/audit-logs')
            ->assertOk()
            ->assertSee('demo.showcase')
            ->assertSee('enable')
            ->assertSee('Diizinkan');
    }

    public function test_the_audit_log_is_forbidden_without_the_audit_permission(): void
    {
        // brand_manager reaches the Principal panel but holds no
        // `platform.audit.view` permission.
        $brandManager = $this->principal(RoleKey::BRAND_MANAGER);

        $this->actingAs($brandManager)->get('/principal/audit-logs')->assertForbidden();
    }

    // ---------------------------------------------------------- Read-only

    public function test_the_audit_log_is_read_only(): void
    {
        $record = $this->audit();

        $this->actingAs($this->principal());

        $this->assertTrue(AuditLogResource::canViewAny());
        $this->assertFalse(AuditLogResource::canCreate());
        $this->assertFalse(AuditLogResource::canEdit($record));
        $this->assertFalse(AuditLogResource::canDelete($record));
        $this->assertFalse(AuditLogResource::canDeleteAny());
    }

    // ----------------------------------------------------- Security posture

    public function test_the_security_posture_surfaces_denied_authorizations(): void
    {
        $this->audit(ModuleAuditLog::RESULT_DENIED);
        $this->audit(ModuleAuditLog::RESULT_DENIED);
        $this->audit(ModuleAuditLog::RESULT_ALLOWED);

        // One active governance identity (the acting principal).
        $this->principal();

        Livewire::test(SecurityPostureWidget::class)
            ->assertSee('Postur Keamanan')
            ->assertSee('2 ditolak dalam 30 hari')
            ->assertSee('1 identitas dengan peran platform aktif')
            ->assertSee('Perhatian');
    }
}
