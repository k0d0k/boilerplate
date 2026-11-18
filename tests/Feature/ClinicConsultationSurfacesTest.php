<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Models\ConsultationMessage;
use Modules\Consultations\Models\ConsultationStatusHistory;
use Modules\Consultations\Services\ConsultationManager;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Tahap 3 — the TailAdmin consultation book (human expert sessions and SINA).
 *
 * Covers the first-class TailAdmin surface that used to be a Filament escape
 * hatch:
 *
 *  - a consultation is booked, opened and moved through its validated
 *    lifecycle (start / complete / cancel) via the normal routes;
 *  - the book is branch-scoped and fails closed (a foreign branch is 403, a
 *    foreign tenant id is 404) and requires consultation.manage;
 *  - the SINA session list opens the consultation transcript, and the private
 *    transcript is rendered on the dossier.
 *
 * Every identity, patient and consultation here is synthetic (DECISIONS.md PRV-01).
 */
class ClinicConsultationSurfacesTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    // --------------------------------------------------------------- Helpers

    private function member(Tenant $tenant, string $roleKey): User
    {
        $user = User::factory()->role($roleKey)->create();
        $this->giveMembership($user, $tenant, [$roleKey]);

        return $user;
    }

    /**
     * @param  list<string>  $modules
     */
    private function activate(Tenant $tenant, array $modules): void
    {
        foreach ($modules as $module) {
            if (! RegisteredModule::query()->where('module_key', $module)->exists()) {
                RegisteredModule::factory()->forKey($module)->enabled()->create();
            }

            ModuleInstallation::factory()
                ->forModule($module)
                ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
                ->enabled()
                ->create();
            ModuleEntitlement::factory()
                ->forModule($module)
                ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
                ->granted()
                ->create();
        }
    }

    private function patient(Tenant $tenant, User $actor, string $name): Patient
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());

        return app(PatientRegistrar::class)->create([
            'full_name' => $name,
            'status' => Patient::STATUS_ACTIVE,
        ], $actor);
    }

    private function consultation(Tenant $tenant, User $actor, Patient $patient, string $branchId, string $channel = Consultation::CHANNEL_IN_PERSON): Consultation
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());

        return app(ConsultationManager::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branchId,
            'channel' => $channel,
            'type' => Consultation::TYPE_CONSULTATION,
            'scheduled_at' => now()->addDay(),
        ], $actor);
    }

    // ============================== 1. Book / open / lifecycle

    public function test_a_consultation_is_booked_opened_and_moved_through_its_lifecycle(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, ['patients', 'consultations']);

        $branch = $this->createBranch($tenant, ['name' => 'Consult Room']);
        $branchId = (string) $branch->getKey();
        $patient = $this->patient($tenant, $owner, 'Booked Consultation Patient');

        $base = '/clinic/workspace/consultations';

        // The create form is first-class TailAdmin (never a Filament page).
        $this->actingAs($owner)->get($base.'/create')->assertOk()
            ->assertSee('Booked Consultation Patient');

        // Booking goes through the normal route.
        $this->actingAs($owner)->post($base, [
            'patient_id' => $patient->getKey(),
            'branch_id' => $branchId,
            'channel' => Consultation::CHANNEL_IN_PERSON,
            'type' => Consultation::TYPE_CONSULTATION,
            'scheduled_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'reason' => 'Synthetic follow-up',
        ])->assertRedirect();

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $consultation = Consultation::query()->where('patient_id', $patient->getKey())->firstOrFail();
        $this->assertSame(Consultation::STATUS_SCHEDULED, $consultation->status);

        $show = $base.'/'.$consultation->getKey();

        // The dossier renders identity, schedule and the status history.
        $this->actingAs($owner)->get($show)->assertOk()
            ->assertSee('Booked Consultation Patient')
            ->assertSee('Consult Room')
            ->assertSee(__('lba.consultations.status_scheduled'));

        // The registry "Open" points at the TailAdmin dossier, not Filament.
        $html = $this->actingAs($owner)->get($base)->assertOk()->getContent();
        $this->assertStringContainsString(route('clinic.tailadmin.consultations.show', $consultation), $html);
        $this->assertStringNotContainsString('/clinic/consultations/', $html);

        // The validated lifecycle: start → complete.
        $this->actingAs($owner)->post($show.'/transition', ['status' => Consultation::STATUS_IN_PROGRESS])
            ->assertRedirect($show)->assertSessionHasNoErrors();

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertSame(Consultation::STATUS_IN_PROGRESS, $consultation->fresh()->status);
        $this->assertNotNull($consultation->fresh()->started_at);

        $this->actingAs($owner)->post($show.'/transition', ['status' => Consultation::STATUS_COMPLETED])
            ->assertRedirect($show)->assertSessionHasNoErrors();

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertSame(Consultation::STATUS_COMPLETED, $consultation->fresh()->status);
        $this->assertNotNull($consultation->fresh()->ended_at);

        // Every move is auditable: created + in_progress + completed.
        $this->assertSame(
            3,
            ConsultationStatusHistory::query()->where('consultation_id', $consultation->getKey())->count(),
        );

        // An illegal move out of a terminal status is refused, never applied.
        $this->actingAs($owner)->post($show.'/transition', ['status' => Consultation::STATUS_IN_PROGRESS])
            ->assertSessionHasErrors('status');

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertSame(Consultation::STATUS_COMPLETED, $consultation->fresh()->status);
    }

    public function test_cancelling_a_consultation_is_explicit_and_terminal(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, ['patients', 'consultations']);

        $branch = $this->createBranch($tenant);
        $patient = $this->patient($tenant, $owner, 'Cancelled Consultation Patient');
        $consultation = $this->consultation($tenant, $owner, $patient, (string) $branch->getKey());

        $show = '/clinic/workspace/consultations/'.$consultation->getKey();

        $this->actingAs($owner)->post($show.'/transition', [
            'status' => Consultation::STATUS_CANCELLED,
            'note' => 'Patient could not attend',
        ])->assertRedirect($show)->assertSessionHasNoErrors();

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertSame(Consultation::STATUS_CANCELLED, $consultation->fresh()->status);

        // The cancellation note is preserved on the status history.
        $this->assertSame(
            'Patient could not attend',
            ConsultationStatusHistory::query()
                ->where('consultation_id', $consultation->getKey())
                ->where('to_status', Consultation::STATUS_CANCELLED)
                ->value('note'),
        );
    }

    // ============================== 2. Access restriction (branch + role)

    public function test_the_consultation_book_is_branch_scoped_and_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $consultant = $this->member($tenant, RoleKey::CONSULTANT);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, ['patients', 'consultations']);

        $allowed = $this->createBranch($tenant, ['name' => 'Assigned Room']);
        $other = $this->createBranch($tenant, ['name' => 'Foreign Room']);

        $allowedPatient = $this->patient($tenant, $owner, 'Assigned Session Patient');
        $otherPatient = $this->patient($tenant, $owner, 'Foreign Session Patient');

        $assigned = $this->consultation($tenant, $owner, $allowedPatient, (string) $allowed->getKey());
        $foreign = $this->consultation($tenant, $owner, $otherPatient, (string) $other->getKey());

        $this->assignStaff($owner, $consultant, (string) $allowed->getKey());

        // The consultant only reaches the session of the assigned branch.
        $this->actingAs($consultant)
            ->get('/clinic/workspace/consultations/'.$assigned->getKey())->assertOk()
            ->assertSee('Assigned Session Patient');

        $this->actingAs($consultant)
            ->get('/clinic/workspace/consultations/'.$foreign->getKey())->assertForbidden();

        // The transition is equally branch-scoped: a foreign session is refused.
        $this->actingAs($consultant)
            ->post('/clinic/workspace/consultations/'.$foreign->getKey().'/transition', [
                'status' => Consultation::STATUS_IN_PROGRESS,
            ])->assertForbidden();
    }

    public function test_an_identity_without_consultation_manage_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $receptionist = $this->member($tenant, RoleKey::RECEPTIONIST);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, ['patients', 'consultations']);

        $branch = $this->createBranch($tenant);
        $patient = $this->patient($tenant, $owner, 'Gated Session Patient');
        $consultation = $this->consultation($tenant, $owner, $patient, (string) $branch->getKey());

        $show = '/clinic/workspace/consultations/'.$consultation->getKey();

        $this->actingAs($receptionist)->get($show)->assertForbidden();
        $this->actingAs($receptionist)->get('/clinic/workspace/consultations/create')->assertForbidden();
        $this->actingAs($receptionist)->post($show.'/transition', [
            'status' => Consultation::STATUS_IN_PROGRESS,
        ])->assertForbidden();

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertSame(Consultation::STATUS_SCHEDULED, $consultation->fresh()->status);
    }

    public function test_a_consultation_of_another_tenant_is_never_reachable(): void
    {
        $tenantA = Tenant::factory()->create();
        $ownerA = $this->member($tenantA, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantA);
        $this->activate($tenantA, ['patients', 'consultations']);
        $this->createBranch($tenantA);

        $tenantB = Tenant::factory()->create();
        $ownerB = $this->member($tenantB, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantB);
        $this->activate($tenantB, ['patients', 'consultations']);
        $branchB = $this->createBranch($tenantB);
        $patientB = $this->patient($tenantB, $ownerB, 'Foreign Consultation Patient');
        $foreign = $this->consultation($tenantB, $ownerB, $patientB, (string) $branchB->getKey());

        // Acting inside tenant A, tenant B's id does not exist in tenant A's
        // database — a 404, never widened.
        $this->actingAs($ownerA)
            ->get('/clinic/workspace/consultations/'.$foreign->getKey())->assertNotFound();
    }

    // ============================== 3. SINA transcript

    public function test_a_sina_session_opens_its_private_transcript(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, ['patients', 'consultations']);

        $branch = $this->createBranch($tenant, ['name' => 'Sina Room']);
        $patient = $this->patient($tenant, $owner, 'Sina Transcript Patient');
        $session = $this->consultation($tenant, $owner, $patient, (string) $branch->getKey(), Consultation::CHANNEL_SINA_CHAT);

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        ConsultationMessage::query()->create([
            'consultation_id' => $session->getKey(),
            'role' => ConsultationMessage::ROLE_PATIENT,
            'content' => 'Synthetic patient question for SINA.',
        ]);
        ConsultationMessage::query()->create([
            'consultation_id' => $session->getKey(),
            'role' => ConsultationMessage::ROLE_ASSISTANT,
            'content' => 'Synthetic SINA reply.',
            'provider' => 'openai_compatible',
            'model' => 'test-sina-model',
            'credits' => 1,
        ]);

        // The SINA list links every session to its dossier.
        $html = $this->actingAs($owner)->get('/clinic/workspace/sina')->assertOk()->getContent();
        $this->assertStringContainsString(route('clinic.tailadmin.consultations.show', $session), $html);

        // The dossier renders the private transcript (both turns + the model tag).
        $this->actingAs($owner)
            ->get('/clinic/workspace/consultations/'.$session->getKey())->assertOk()
            ->assertSee('Synthetic patient question for SINA.')
            ->assertSee('Synthetic SINA reply.')
            ->assertSee('test-sina-model');
    }

    // ============================== 4. Informed tariff / refund disclosure

    public function test_the_dossier_discloses_the_tariff_and_refund_terms(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, ['patients', 'consultations']);

        config()->set('lba.consultations.currency', 'IDR');
        config()->set('lba.consultations.tariff.in_person', 175000);
        config()->set('lba.consultations.cancellable_before_start', true);
        config()->set('lba.consultations.refundable', false);

        $branch = $this->createBranch($tenant);
        $patient = $this->patient($tenant, $owner, 'Tariffed Consultation Patient');
        $consultation = $this->consultation($tenant, $owner, $patient, (string) $branch->getKey());

        $this->actingAs($owner)
            ->get('/clinic/workspace/consultations/'.$consultation->getKey())->assertOk()
            ->assertSee('Rp 175,000')
            ->assertSee(__('lba.consultations.policy.cancellable'))
            ->assertSee(__('lba.consultations.policy.not_refundable'));
    }

    public function test_the_dossier_states_when_no_tariff_is_configured(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, ['patients', 'consultations']);

        config()->set('lba.consultations.tariff.in_person', null);

        $branch = $this->createBranch($tenant);
        $patient = $this->patient($tenant, $owner, 'Untariffed Consultation Patient');
        $consultation = $this->consultation($tenant, $owner, $patient, (string) $branch->getKey());

        $this->actingAs($owner)
            ->get('/clinic/workspace/consultations/'.$consultation->getKey())->assertOk()
            ->assertSee(__('lba.consultations.tariff_unset'));
    }
}
