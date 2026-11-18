<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Branches\Models\BranchStaffAssignment;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Services\ConsultationManager;
use Modules\LBA\Models\Examination;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;
use Modules\Practitioners\Models\Practitioner;
use Modules\Practitioners\Models\PractitionerCredential;
use Modules\Practitioners\Services\PractitionerRegistry;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 11 — Clinic TailAdmin module surfaces.
 *
 * Covers the TailAdmin counterparts of the remaining Clinic roadmap surfaces:
 *
 *  - the Pemeriksaan LBA registry carries the Manual / AI / Hybrid queues as a
 *    validated `?mode=` filter (a bad mode is rejected, never ignored);
 *  - Lokasi Pemeriksaan and Tim & Cabang are branch-scoped and fail closed when
 *    the identity holds no eligible branch;
 *  - Acara & Pemesanan lists bookings for the eligible branches only, and the
 *    `location` filter can only narrow that set (a foreign location is refused);
 *  - SINA AI surfaces only the SINA-channel sessions the identity may manage;
 *  - Pengaturan Klinik requires tenant.profile.manage.
 *
 * Every identity, patient, examination and booking here is synthetic
 * (DECISIONS.md PRV-01).
 */
class ClinicModuleSurfacesTest extends TestCase
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
     * An active clinical member (Doctor / Medical Staff) of the tenant — the
     * only kind of identity that may be registered as a practitioner.
     */
    private function clinical(Tenant $tenant, string $roleKey = RoleKey::CONSULTANT): User
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

    private function examination(Tenant $tenant, User $actor, string $branchId, string $mode, string $patientName): Examination
    {
        $patient = $this->patient($tenant, $actor, $patientName);

        return app(ExaminationRegistrar::class)->create([
            'patient_id' => $patient->getKey(),
            'mode' => $mode,
            'record_consent' => true,
            'branch_id' => $branchId,
        ], $actor);
    }

    private function appointment(Tenant $tenant, User $actor, Patient $patient, string $branchId, int $hour): Appointment
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $starts = now()->addDay()->setTime($hour, 0);

        return app(AppointmentScheduler::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branchId,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHour(),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ], $actor);
    }

    private function consultation(Tenant $tenant, User $actor, Patient $patient, string $branchId, string $channel): Consultation
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

    // ============================== 1. Manual / AI / Hybrid mode queues

    public function test_the_examination_registry_exposes_manual_ai_and_hybrid_mode_queues(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, ['patients', 'lba']);

        $branch = $this->createBranch($tenant);
        $branchId = (string) $branch->getKey();

        $this->examination($tenant, $owner, $branchId, Examination::MODE_MANUAL, 'Manual Queue Patient');
        $this->examination($tenant, $owner, $branchId, Examination::MODE_AI, 'Ai Queue Patient');
        $this->examination($tenant, $owner, $branchId, Examination::MODE_HYBRID, 'Hybrid Queue Patient');

        $this->actingAs($owner);
        $base = '/clinic/workspace/examinations';

        // The unfiltered registry is the "Semua Pemeriksaan" queue.
        $this->get($base)->assertOk()
            ->assertSee('Manual Queue Patient')
            ->assertSee('Ai Queue Patient')
            ->assertSee('Hybrid Queue Patient');

        // Each mode queue narrows the registry to its own mode only.
        $this->get($base.'?mode=manual')->assertOk()
            ->assertSee('Manual Queue Patient')
            ->assertDontSee('Ai Queue Patient')
            ->assertDontSee('Hybrid Queue Patient');

        $this->get($base.'?mode=ai')->assertOk()
            ->assertDontSee('Manual Queue Patient')
            ->assertSee('Ai Queue Patient')
            ->assertDontSee('Hybrid Queue Patient');

        $this->get($base.'?mode=hybrid')->assertOk()
            ->assertDontSee('Manual Queue Patient')
            ->assertDontSee('Ai Queue Patient')
            ->assertSee('Hybrid Queue Patient');

        // An unknown mode is rejected by server validation, never silently ignored.
        $this->getJson($base.'?mode=bogus')->assertUnprocessable()->assertJsonValidationErrors('mode');
    }

    // ============================== 2. Lokasi Pemeriksaan & Tim & Cabang

    public function test_locations_and_team_are_branch_scoped_and_fail_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);

        $allowed = $this->createBranch($tenant, ['name' => 'Eligible Location']);
        $other = $this->createBranch($tenant, ['name' => 'Foreign Location']);

        // Without any eligible branch the operational surfaces are closed.
        $this->actingAs($operator)->get('/clinic/workspace/locations')->assertForbidden();
        $this->get('/clinic/workspace/team')->assertForbidden();

        // Once assigned, only the eligible branch is ever listed.
        $this->assignStaff($owner, $operator, (string) $allowed->getKey());

        $this->actingAs($operator)
            ->get('/clinic/workspace/locations')->assertOk()
            ->assertSee('Eligible Location')
            ->assertDontSee('Foreign Location');

        $this->get('/clinic/workspace/team')->assertOk()
            ->assertSee('Eligible Location')
            ->assertDontSee('Foreign Location');
    }

    public function test_a_branch_manager_can_create_edit_activate_and_deactivate_a_location(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);

        $index = '/clinic/workspace/locations';

        // A permanent location is created in the tenant database.
        $this->actingAs($owner)->post($index, [
            'name' => 'Downtown Clinic',
            'code' => 'BR-DOWNTOWN',
            'status' => TenantBranch::STATUS_ACTIVE,
            'location_type' => TenantBranch::LOCATION_PERMANENT,
        ])->assertRedirect($index);

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $location = TenantBranch::query()->where('code', 'BR-DOWNTOWN')->firstOrFail();
        $this->assertTrue($location->isPermanent());
        $this->assertTrue($location->isActive());

        // A duplicate code is rejected against the tenant database, never ignored.
        $this->actingAs($owner)->post($index, [
            'name' => 'Duplicate',
            'code' => 'BR-DOWNTOWN',
            'status' => TenantBranch::STATUS_ACTIVE,
            'location_type' => TenantBranch::LOCATION_PERMANENT,
        ])->assertSessionHasErrors('code');

        // A temporary / event location must declare the window it is bookable in.
        $this->actingAs($owner)->post($index, [
            'name' => 'Pop-up Site',
            'code' => 'BR-POPUP',
            'status' => TenantBranch::STATUS_ACTIVE,
            'location_type' => TenantBranch::LOCATION_TEMPORARY,
        ])->assertSessionHasErrors('operates_from');

        // Editing keeps exactly one row in the tenant database.
        $this->actingAs($owner)->put($index.'/'.$location->getKey(), [
            'name' => 'Downtown Clinic (Renamed)',
            'code' => 'BR-DOWNTOWN',
            'status' => TenantBranch::STATUS_ACTIVE,
            'location_type' => TenantBranch::LOCATION_PERMANENT,
        ])->assertRedirect($index);

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertSame('Downtown Clinic (Renamed)', $location->fresh()->name);

        // Deactivation is non-destructive and reversible.
        $this->actingAs($owner)->post($index.'/'.$location->getKey().'/deactivate')
            ->assertRedirect($index);
        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertFalse($location->fresh()->isActive());

        $this->actingAs($owner)->post($index.'/'.$location->getKey().'/activate')
            ->assertRedirect($index);
        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertTrue($location->fresh()->isActive());

        // An identity without branch.operations.manage is denied outright.
        $this->actingAs($operator)->get($index.'/create')->assertForbidden();
        $this->actingAs($operator)->post($index, [
            'name' => 'Forbidden Site',
            'code' => 'BR-FORBIDDEN',
            'status' => TenantBranch::STATUS_ACTIVE,
            'location_type' => TenantBranch::LOCATION_PERMANENT,
        ])->assertForbidden();
    }

    // ============================== 3. Tim & Cabang staff assignment

    public function test_a_branch_manager_can_assign_and_revoke_branch_staff(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $staff = $this->member($tenant, RoleKey::RECEPTIONIST);
        $outsider = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant, ['name' => 'Roster Branch']);
        $branchId = (string) $branch->getKey();
        $base = '/clinic/workspace/team/'.$branchId;

        // The branch page lists the branch and offers the eligible staff.
        $this->actingAs($owner)->get($base)->assertOk()
            ->assertSee('Roster Branch')
            ->assertSee($staff->name);

        // A manager assigns a staff member; the row lands in the tenant database.
        $this->actingAs($owner)->post($base.'/staff', ['user_id' => $staff->getKey()])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $assignment = BranchStaffAssignment::query()
            ->where('branch_id', $branchId)
            ->where('user_id', $staff->getKey())
            ->first();
        $this->assertNotNull($assignment);
        $this->assertTrue($assignment->isActive());

        // Revocation is non-destructive.
        $this->actingAs($owner)->post($base.'/staff/revoke', ['user_id' => $staff->getKey()])
            ->assertRedirect();

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertFalse($assignment->fresh()->isActive());

        // Revoking a non-assignment is reported, never silently accepted.
        $this->actingAs($owner)->post($base.'/staff/revoke', ['user_id' => $outsider->getKey()])
            ->assertSessionHasErrors('user_id');

        // A user without an active membership in this tenant cannot be assigned.
        $noMembership = User::factory()->create();
        $this->actingAs($owner)->post($base.'/staff', ['user_id' => $noMembership->getKey()])
            ->assertSessionHasErrors('user_id');

        // A staff identity without branch.operations.manage is denied outright.
        $this->actingAs($staff)->get($base)->assertForbidden();
        $this->actingAs($staff)->post($base.'/staff', ['user_id' => $outsider->getKey()])
            ->assertForbidden();

        // A branch outside the eligible set (here: another tenant) is refused —
        // the id simply does not exist in this tenant's database (404).
        $foreignTenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($foreignTenant);
        $foreignBranch = $this->createBranch($foreignTenant);
        $this->actingAs($owner)->get('/clinic/workspace/team/'.$foreignBranch->getKey())->assertNotFound();
    }

    // ============================== 4. Acara & Pemesanan

    public function test_events_are_filtered_per_location_within_the_eligible_branches(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $receptionist = $this->member($tenant, RoleKey::RECEPTIONIST);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, ['patients', 'appointments']);

        $allowed = $this->createBranch($tenant, ['name' => 'Event Hall']);
        $other = $this->createBranch($tenant, ['name' => 'Restricted Hall']);

        $allowedPatient = $this->patient($tenant, $owner, 'Event Booking Patient');
        $otherPatient = $this->patient($tenant, $owner, 'Restricted Booking Patient');
        $this->appointment($tenant, $owner, $allowedPatient, (string) $allowed->getKey(), 9);
        $this->appointment($tenant, $owner, $otherPatient, (string) $other->getKey(), 11);

        $this->assignStaff($owner, $receptionist, (string) $allowed->getKey());

        $this->actingAs($receptionist);
        $events = '/clinic/workspace/events';

        // The book is limited to the eligible branches.
        $this->get($events)->assertOk()
            ->assertSee('Event Booking Patient')
            ->assertDontSee('Restricted Booking Patient');

        // The location filter narrows to one eligible branch.
        $this->get($events.'?location='.$allowed->getKey())->assertOk()
            ->assertSee('Event Booking Patient')
            ->assertDontSee('Restricted Booking Patient');

        // A location outside the eligible set is refused outright.
        $this->get($events.'?location='.$other->getKey())->assertForbidden();

        // A cross-tenant location id is equally refused, never widened.
        $foreignTenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($foreignTenant);
        $foreignBranch = $this->createBranch($foreignTenant);
        $this->get($events.'?location='.$foreignBranch->getKey())->assertForbidden();
    }

    public function test_the_event_surface_publishes_slots_and_books_within_capacity(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, ['patients', 'appointments']);

        $event = $this->createBranch($tenant, [
            'name' => 'Capacity Event',
            'location_type' => TenantBranch::LOCATION_EVENT,
            'timezone' => config('app.timezone'),
            'opens_at' => '09:00',
            'closes_at' => '11:00',
            'slot_minutes' => 30,
            'capacity' => 1,
            'operates_from' => now()->addDays(2)->startOfDay(),
            'operates_until' => now()->addDays(5)->endOfDay(),
        ]);
        $eventId = (string) $event->getKey();
        $patient = $this->patient($tenant, $owner, 'Capacity Patient');
        $base = '/clinic/workspace/events/'.$eventId;

        // The confirmed event publishes its day's slots with the capacity
        // projection (defaulting to the event's own first day).
        $this->actingAs($owner)->get($base)->assertOk()
            ->assertSee('Capacity Event')
            ->assertSee(__('lba.events.capacity'))
            ->assertSee('09:00');

        $starts = now()->addDays(3)->setTime(9, 0);
        $this->actingAs($owner)->post($base.'/bookings', [
            'patient_id' => $patient->getKey(),
            'starts_at' => $starts->format('Y-m-d\TH:i'),
            'ends_at' => $starts->copy()->addMinutes(30)->format('Y-m-d\TH:i'),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ])->assertRedirect($base);

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertSame(1, Appointment::query()->where('branch_id', $eventId)->count());

        // A second, overlapping booking exceeds the location capacity and is
        // refused rather than silently confirmed.
        $this->actingAs($owner)->post($base.'/bookings', [
            'patient_id' => $patient->getKey(),
            'starts_at' => $starts->copy()->addMinutes(15)->format('Y-m-d\TH:i'),
            'ends_at' => $starts->copy()->addMinutes(45)->format('Y-m-d\TH:i'),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ])->assertSessionHasErrors('starts_at');

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertSame(1, Appointment::query()->where('branch_id', $eventId)->count());
    }

    public function test_a_tentative_event_is_preregistration_only_and_refuses_booking(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $receptionist = $this->member($tenant, RoleKey::RECEPTIONIST);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, ['patients', 'appointments']);

        $event = $this->createBranch($tenant, [
            'name' => 'Tentative Expo',
            'location_type' => TenantBranch::LOCATION_EVENT,
            'status' => TenantBranch::STATUS_INACTIVE,
            'operates_from' => now()->addDays(2)->startOfDay(),
            'operates_until' => now()->addDays(5)->endOfDay(),
        ]);
        $eventId = (string) $event->getKey();
        $patient = $this->patient($tenant, $owner, 'Tentative Patient');
        $base = '/clinic/workspace/events/'.$eventId;

        // The manager sees it clearly marked tentative; the detail page is
        // preregistration only and publishes no slot.
        $this->actingAs($owner)->get('/clinic/workspace/events')->assertOk()
            ->assertSee('Tentative Expo')
            ->assertSee(__('lba.events.tentative'));

        $this->actingAs($owner)->get($base)->assertOk()
            ->assertSee(__('lba.events.preregistration'));

        $payload = [
            'patient_id' => $patient->getKey(),
            'starts_at' => now()->addDays(3)->setTime(9, 0)->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDays(3)->setTime(9, 30)->format('Y-m-d\TH:i'),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ];

        // A booking against a tentative event is refused, never confirmed.
        $this->actingAs($owner)->post($base.'/bookings', $payload)->assertForbidden();

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertSame(0, Appointment::query()->where('branch_id', $eventId)->count());

        // A non-manager assigned to an active branch cannot open or book the
        // tentative site at all (fail closed).
        $active = $this->createBranch($tenant, ['name' => 'Active Branch']);
        $this->assignStaff($owner, $receptionist, (string) $active->getKey());

        $this->actingAs($receptionist)->get($base)->assertForbidden();
        $this->actingAs($receptionist)->post($base.'/bookings', $payload)->assertForbidden();
    }

    // ============================== 4. SINA AI

    public function test_sina_surfaces_only_permitted_sina_channel_sessions(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $consultant = $this->member($tenant, RoleKey::CONSULTANT);
        $receptionist = $this->member($tenant, RoleKey::RECEPTIONIST);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, ['patients', 'consultations']);

        $allowed = $this->createBranch($tenant, ['name' => 'Sina Hall']);
        $other = $this->createBranch($tenant, ['name' => 'Other Sina Hall']);

        $allowedPatient = $this->patient($tenant, $owner, 'Sina Session Patient');
        $inPersonPatient = $this->patient($tenant, $owner, 'In Person Patient');
        $foreignPatient = $this->patient($tenant, $owner, 'Foreign Sina Patient');

        $this->consultation($tenant, $owner, $allowedPatient, (string) $allowed->getKey(), Consultation::CHANNEL_SINA_CHAT);
        $this->consultation($tenant, $owner, $inPersonPatient, (string) $allowed->getKey(), Consultation::CHANNEL_IN_PERSON);
        $this->consultation($tenant, $owner, $foreignPatient, (string) $other->getKey(), Consultation::CHANNEL_SINA_CHAT);

        $this->assignStaff($owner, $consultant, (string) $allowed->getKey());

        // The consultant sees only the SINA sessions of the eligible branch.
        $this->actingAs($consultant)->get('/clinic/workspace/sina')->assertOk()
            ->assertSee('Sina Session Patient')
            ->assertDontSee('In Person Patient')
            ->assertDontSee('Foreign Sina Patient');

        // An identity without consultation.manage is denied outright.
        $this->actingAs($receptionist)->get('/clinic/workspace/sina')->assertForbidden();
    }

    // ============================== 5. Pengaturan Klinik

    public function test_clinic_settings_require_tenant_profile_management(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);

        $this->actingAs($owner)->get('/clinic/workspace/settings')->assertOk()->assertSee($tenant->name);
        $this->actingAs($operator)->get('/clinic/workspace/settings')->assertForbidden();
    }

    public function test_clinic_settings_link_to_the_first_class_operational_surfaces(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->createBranch($tenant);

        $html = $this->actingAs($owner)->get('/clinic/workspace/settings')->assertOk()->getContent();

        // The settings hub links to the TailAdmin Lokasi Pemeriksaan and Tim &
        // Cabang surfaces (never back into Filament).
        $this->assertStringContainsString(route('clinic.tailadmin.locations'), $html);
        $this->assertStringContainsString(route('clinic.tailadmin.team'), $html);
    }

    // ============================== 6. Pasien registry

    public function test_a_patient_registry_surface_handles_the_full_lifecycle(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $reader = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, ['patients']);

        $branch = $this->createBranch($tenant, ['name' => 'Patient Branch']);
        $branchId = (string) $branch->getKey();
        $base = '/clinic/workspace/patients';

        // Registration happens through the normal route and lands on the
        // profile; the row is written into the tenant's own database.
        $this->actingAs($owner)->post($base, [
            'tenant_id' => (string) $tenant->getKey(),
            'full_name' => 'Synthetic Patient',
            'phone' => '+6200000000',
            'registered_branch_id' => $branchId,
            'status' => Patient::STATUS_ACTIVE,
        ])->assertRedirect();

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $patient = Patient::query()->where('full_name', 'Synthetic Patient')->firstOrFail();
        $this->assertTrue($patient->isActive());
        $this->assertSame($branchId, (string) $patient->registered_branch_id);

        $show = $base.'/'.$patient->getKey();

        // The profile renders the identity and its registration branch.
        $this->actingAs($owner)->get($show)->assertOk()
            ->assertSee('Synthetic Patient')
            ->assertSee('Patient Branch');

        // Editing keeps exactly one row.
        $this->actingAs($owner)->get($show.'/edit')->assertOk();
        $this->actingAs($owner)->put($show, [
            'tenant_id' => (string) $tenant->getKey(),
            'full_name' => 'Synthetic Patient Renamed',
            'registered_branch_id' => $branchId,
            'status' => Patient::STATUS_ACTIVE,
        ])->assertRedirect($show);

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertSame('Synthetic Patient Renamed', $patient->fresh()->full_name);

        // Archiving is non-destructive and reversible.
        $this->actingAs($owner)->post($show.'/archive')->assertRedirect($show);
        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertTrue($patient->fresh()->isArchived());

        $this->actingAs($owner)->post($show.'/restore')->assertRedirect($show);
        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertTrue($patient->fresh()->isActive());

        // A read-only clinical identity reaches the profile but can never
        // register, edit or archive a patient.
        $this->actingAs($reader)->get($show)->assertOk();
        $this->actingAs($reader)->get($base.'/create')->assertForbidden();
        $this->actingAs($reader)->get($show.'/edit')->assertForbidden();
        $this->actingAs($reader)->post($show.'/archive')->assertForbidden();
    }

    public function test_a_patient_of_another_tenant_is_never_reachable(): void
    {
        $tenantA = Tenant::factory()->create();
        $ownerA = $this->member($tenantA, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantA);
        $this->activate($tenantA, ['patients']);
        $this->createBranch($tenantA);

        $tenantB = Tenant::factory()->create();
        $ownerB = $this->member($tenantB, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantB);
        $this->activate($tenantB, ['patients']);
        $this->createBranch($tenantB);

        $foreign = $this->patient($tenantB, $ownerB, 'Foreign Patient');

        // Acting inside tenant A, tenant B's patient id simply does not exist
        // in tenant A's database — a 404, never widened.
        $this->actingAs($ownerA)->get('/clinic/workspace/patients/'.$foreign->getKey())
            ->assertNotFound();
    }

    // ============================== 7. Tenaga Ahli registry

    public function test_the_practitioner_surface_registers_verifies_and_authorizes(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, ['practitioners']);

        $branch = $this->createBranch($tenant, ['name' => 'Practice Branch']);
        $branchId = (string) $branch->getKey();
        $base = '/clinic/workspace/practitioners';

        // A clinical member is registered as a pending practitioner.
        $this->actingAs($owner)->post($base, [
            'user_id' => $clinical->getKey(),
            'display_name' => 'Dr Synthetic',
            'title' => 'dr.',
            'specialities' => 'Fisioterapi, Akupunktur',
        ])->assertRedirect(route('clinic.tailadmin.index', 'practitioners'));

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $practitioner = Practitioner::query()->where('user_id', $clinical->getKey())->firstOrFail();
        $this->assertTrue($practitioner->isPending());
        $this->assertSame(['Fisioterapi', 'Akupunktur'], $practitioner->specialities);

        $show = $base.'/'.$practitioner->getKey();
        $this->actingAs($owner)->get($show)->assertOk()->assertSee('Dr Synthetic');

        // Verification is explicit (never automatic).
        $this->actingAs($owner)->post($show.'/verify')
            ->assertRedirect($show)->assertSessionHasNoErrors();
        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertTrue($practitioner->fresh()->isVerified());

        // Branch authorization is limited to an eligible branch.
        $this->actingAs($owner)->post($show.'/branches', ['branch_id' => $branchId])
            ->assertRedirect($show)->assertSessionHasNoErrors();
        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertTrue(app(PractitionerRegistry::class)
            ->isAuthorizedForBranch($practitioner->fresh(), $branchId));

        // A credential is added (pending) and verified through the route.
        $this->actingAs($owner)->post($show.'/credentials', [
            'credential_type' => PractitionerCredential::TYPE_LICENSE,
            'reference_number' => 'MOH-123456',
        ])->assertRedirect($show)->assertSessionHasNoErrors();

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $credential = PractitionerCredential::query()
            ->where('practitioner_id', $practitioner->getKey())->firstOrFail();
        $this->assertTrue($credential->isPending());

        $this->actingAs($owner)->post($show.'/credentials/'.$credential->getKey().'/verify')
            ->assertRedirect($show)->assertSessionHasNoErrors();
        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertTrue($credential->fresh()->isVerified());
    }

    public function test_a_practitioner_may_not_verify_their_own_record(): void
    {
        $tenant = Tenant::factory()->create();
        // One identity that holds the clinic-admin capability AND is itself a
        // clinical member — the only way self-verification becomes reachable.
        $dual = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($dual, $tenant, [RoleKey::TENANT_OWNER, RoleKey::CONSULTANT]);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, ['practitioners']);
        $this->createBranch($tenant);

        $base = '/clinic/workspace/practitioners';

        $this->actingAs($dual)->post($base, [
            'user_id' => $dual->getKey(),
            'display_name' => 'Dr Dual Role',
        ])->assertRedirect(route('clinic.tailadmin.index', 'practitioners'));

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $practitioner = Practitioner::query()->where('user_id', $dual->getKey())->firstOrFail();
        $show = $base.'/'.$practitioner->getKey();

        // Separation of duties: the refusal is surfaced as a form error, never
        // a 500 and never silently accepted.
        $this->actingAs($dual)->post($show.'/verify')->assertSessionHasErrors('practitioner');

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertTrue($practitioner->fresh()->isPending());
    }

    public function test_the_practitioner_surface_is_gated_by_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $reader = $this->member($tenant, RoleKey::BRANCH_MANAGER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, ['practitioners']);
        $this->createBranch($tenant);

        $registry = app(PractitionerRegistry::class);
        $practitioner = $registry->register($owner, (string) $clinical->getKey(), [
            'display_name' => 'Dr Gated',
        ]);

        $base = '/clinic/workspace/practitioners';
        $show = $base.'/'.$practitioner->getKey();

        // A branch manager reads the roster but may neither manage the profile
        // nor verify it.
        $this->actingAs($reader)->get($show)->assertOk();
        $this->actingAs($reader)->get($base.'/create')->assertForbidden();
        $this->actingAs($reader)->post($base, [
            'user_id' => $clinical->getKey(),
            'display_name' => 'Forbidden',
        ])->assertForbidden();
        $this->actingAs($reader)->post($show.'/verify')->assertForbidden();

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $this->assertTrue($practitioner->fresh()->isPending());
    }

    // ============================== 8. Sidebar gating

    public function test_the_clinic_sidebar_only_lists_the_module_surfaces_the_identity_may_use(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant, ['patients', 'appointments', 'lba', 'consultations']);
        $this->createBranch($tenant);

        $this->actingAs($owner)->get('/clinic/workspace')->assertOk()
            ->assertSee(__('lba.locations.nav'))
            ->assertSee(__('lba.events.nav'))
            ->assertSee(__('lba.sina.nav'))
            ->assertSee(__('lba.team.nav'))
            ->assertSee(__('lba.nav.clinic.settings'));
    }
}
