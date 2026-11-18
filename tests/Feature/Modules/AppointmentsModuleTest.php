<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Appointments\Exceptions\AppointmentConflictException;
use Modules\Appointments\Filament\Resources\AppointmentResource;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 3 — Appointments module (focused essentials).
 *
 * Exercises appointment booking/CRUD, the validated status lifecycle, tenancy
 * isolation, branch access rules, FeatureGate activation, double-booking
 * prevention and basic page rendering. Only synthetic identities and patients
 * are used.
 */
class AppointmentsModuleTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'appointments';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
        Filament::setCurrentPanel(Filament::getPanel('clinic'));
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    private function member(Tenant $tenant, string $roleKey, string $status = TenantMembership::STATUS_ACTIVE): User
    {
        $user = User::factory()->role($roleKey)->create();
        $this->giveMembership($user, $tenant, [$roleKey], $status);

        return $user;
    }

    private function bindTenant(Tenant $tenant): void
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());
    }

    private function activate(Tenant $tenant): void
    {
        RegisteredModule::factory()->forKey(self::MODULE)->enabled()->create();

        ModuleInstallation::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->enabled()
            ->create();

        ModuleEntitlement::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->granted()
            ->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function book(Tenant $tenant, User $owner, array $overrides = []): Appointment
    {
        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant);

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Booking Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        $starts = now()->addDay()->setTime(9, 0);

        return app(AppointmentScheduler::class)->create(array_merge([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHour(),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ], $overrides), $owner);
    }

    // -------------------------------------------------------------- CRUD

    public function test_a_booking_is_created_as_scheduled_with_a_status_history_entry(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $appointment = $this->book($tenant, $owner);

        $this->assertSame(Appointment::STATUS_SCHEDULED, $appointment->status);
        $this->assertSame($owner->getKey(), $appointment->created_by);

        $this->assertDatabaseHas('appointments', ['id' => $appointment->getKey()], 'tenant');
        $this->assertDatabaseHas('appointment_status_histories', [
            'appointment_id' => $appointment->getKey(),
            'from_status' => null,
            'to_status' => Appointment::STATUS_SCHEDULED,
        ], 'tenant');
    }

    public function test_the_status_lifecycle_moves_through_validated_transitions(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $appointment = $this->book($tenant, $owner);
        $scheduler = app(AppointmentScheduler::class);

        $scheduler->transition($appointment, Appointment::STATUS_CONFIRMED, $owner);
        $scheduler->transition($appointment, Appointment::STATUS_CHECKED_IN, $owner);
        $scheduler->transition($appointment, Appointment::STATUS_COMPLETED, $owner);

        $this->assertSame(Appointment::STATUS_COMPLETED, $appointment->fresh()->status);
        $this->assertSame(4, $appointment->statusHistories()->count());
    }

    public function test_an_illegal_transition_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $appointment = $this->book($tenant, $owner, ['status' => Appointment::STATUS_COMPLETED]);

        try {
            app(AppointmentScheduler::class)->transition($appointment, Appointment::STATUS_CONFIRMED, $owner);
            $this->fail('A completed appointment must not transition further.');
        } catch (AppointmentConflictException $exception) {
            $this->assertStringContainsString('[completed]', $exception->getMessage());
        }
    }

    // ------------------------------------------------- Double-booking

    public function test_an_overlapping_booking_for_the_same_practitioner_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $staff = $this->member($tenant, RoleKey::RECEPTIONIST);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->bindTenant($tenant);
        $branch = $this->createBranch($tenant);
        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Slot Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        $starts = now()->addDay()->setTime(9, 0);
        $scheduler = app(AppointmentScheduler::class);

        $scheduler->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'staff_user_id' => $staff->getKey(),
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHour(),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ], $owner);

        // Overlaps the first booking for the SAME practitioner.
        try {
            $scheduler->create([
                'patient_id' => $patient->getKey(),
                'branch_id' => $branch->getKey(),
                'staff_user_id' => $staff->getKey(),
                'starts_at' => $starts->copy()->addMinutes(30),
                'ends_at' => $starts->copy()->addMinutes(90),
                'service_type' => Appointment::SERVICE_CONSULTATION,
            ], $owner);
            $this->fail('An obvious practitioner double-booking must be rejected.');
        } catch (AppointmentConflictException $exception) {
            $this->assertStringContainsString('already has an appointment', $exception->getMessage());
        }

        // A back-to-back (non-overlapping) booking is allowed.
        $backToBack = $scheduler->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'staff_user_id' => $staff->getKey(),
            'starts_at' => $starts->copy()->addHour(),
            'ends_at' => $starts->copy()->addHours(2),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ], $owner);

        $this->assertSame(Appointment::STATUS_SCHEDULED, $backToBack->status);
    }

    // ------------------------------------------------- Tenant isolation

    public function test_a_foreign_patient_or_branch_is_rejected(): void
    {
        $tenantA = Tenant::factory()->create();
        $ownerA = $this->member($tenantA, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantA);
        $this->activate($tenantA);
        $appointmentA = $this->book($tenantA, $ownerA);

        // Tenant B never sees tenant A's rows.
        $tenantB = Tenant::factory()->create();
        $ownerB = $this->member($tenantB, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantB);
        $this->bindTenant($tenantB);

        $branchB = $this->createBranch($tenantB);
        $patientB = app(PatientRegistrar::class)->create([
            'full_name' => 'Tenant B Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $ownerB);

        $starts = now()->addDay()->setTime(9, 0);
        $scheduler = app(AppointmentScheduler::class);

        // Tenant A's patient id does not exist in tenant B's database.
        try {
            $scheduler->create([
                'patient_id' => $appointmentA->patient_id,
                'branch_id' => $branchB->getKey(),
                'starts_at' => $starts,
                'ends_at' => $starts->copy()->addHour(),
                'service_type' => Appointment::SERVICE_CONSULTATION,
            ], $ownerB);
            $this->fail('A foreign patient must be rejected.');
        } catch (AppointmentConflictException $exception) {
            $this->assertStringContainsString('patient does not belong', $exception->getMessage());
        }

        // Tenant A's branch id does not exist in tenant B's database.
        try {
            $scheduler->create([
                'patient_id' => $patientB->getKey(),
                'branch_id' => $appointmentA->branch_id,
                'starts_at' => $starts,
                'ends_at' => $starts->copy()->addHour(),
                'service_type' => Appointment::SERVICE_CONSULTATION,
            ], $ownerB);
            $this->fail('A foreign branch must be rejected.');
        } catch (AppointmentConflictException $exception) {
            $this->assertStringContainsString('branch does not belong', $exception->getMessage());
        }
    }

    public function test_an_appointment_of_another_tenant_is_never_visible(): void
    {
        $tenantA = Tenant::factory()->create();
        $ownerA = $this->member($tenantA, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantA);
        $this->activate($tenantA);
        $this->book($tenantA, $ownerA);

        $tenantB = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenantB);
        $this->bindTenant($tenantB);

        // Switching the verified tenant connection must release tenant A's
        // rows entirely — nothing leaks into tenant B's database.
        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_staff_from_another_tenant_is_rejected(): void
    {
        $tenantA = Tenant::factory()->create();
        $ownerA = $this->member($tenantA, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenantA);
        $this->activate($tenantA);

        // An identity that is a member of another tenant only.
        $outsider = $this->member(Tenant::factory()->create(), RoleKey::RECEPTIONIST);

        $this->bindTenant($tenantA);
        $branch = $this->createBranch($tenantA);
        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Staff Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $ownerA);

        $starts = now()->addDay()->setTime(9, 0);

        try {
            app(AppointmentScheduler::class)->create([
                'patient_id' => $patient->getKey(),
                'branch_id' => $branch->getKey(),
                'staff_user_id' => $outsider->getKey(),
                'starts_at' => $starts,
                'ends_at' => $starts->copy()->addHour(),
                'service_type' => Appointment::SERVICE_CONSULTATION,
            ], $ownerA);
            $this->fail('An unauthorized staff assignment must be rejected.');
        } catch (AppointmentConflictException $exception) {
            $this->assertStringContainsString('not an active member', $exception->getMessage());
        }
    }

    // ------------------------------------------------- Access rules

    public function test_the_appointments_resource_requires_an_active_entitlement(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);

        // Module discovered but never activated: deny by default.
        $this->actingAs($owner)->get('/clinic/appointments')->assertForbidden();
    }

    public function test_a_role_without_booking_permission_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = $this->member($tenant, RoleKey::LBA_OPERATOR);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->actingAs($operator)->get('/clinic/appointments')->assertForbidden();
    }

    public function test_a_read_only_role_can_view_but_not_manage_bookings(): void
    {
        $tenant = Tenant::factory()->create();
        $finance = $this->member($tenant, RoleKey::TENANT_FINANCE);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->actingAs($finance);

        $this->assertTrue(AppointmentResource::canViewAny());
        $this->assertFalse(AppointmentResource::canCreate());
    }

    public function test_a_revoked_membership_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $revoked = $this->member($tenant, RoleKey::TENANT_OWNER, TenantMembership::STATUS_REVOKED);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->actingAs($revoked)->get('/clinic/appointments')->assertForbidden();
    }

    // ------------------------------------------------- Branch access

    public function test_a_branch_restricted_identity_only_sees_its_own_branches(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $receptionist = $this->member($tenant, RoleKey::RECEPTIONIST);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $this->bindTenant($tenant);
        $branchA = $this->createBranch($tenant, ['name' => 'Branch A']);
        $branchB = $this->createBranch($tenant, ['name' => 'Branch B']);

        // The receptionist may operate only in branch A.
        $this->assignStaff($owner, $receptionist, (string) $branchA->getKey());

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Branch Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        $starts = now()->addDay()->setTime(9, 0);
        $scheduler = app(AppointmentScheduler::class);

        foreach ([$branchA, $branchB] as $index => $branch) {
            $scheduler->create([
                'patient_id' => $patient->getKey(),
                'branch_id' => $branch->getKey(),
                'starts_at' => $starts->copy()->addHours($index * 3),
                'ends_at' => $starts->copy()->addHours($index * 3 + 1),
                'service_type' => Appointment::SERVICE_CONSULTATION,
            ], $owner);
        }

        $this->actingAs($receptionist);
        $this->bindTenant($tenant);

        $visible = AppointmentResource::getEloquentQuery()->pluck('branch_id')->all();

        $this->assertSame([(string) $branchA->getKey()], $visible);
    }

    // ------------------------------------------------- Page rendering

    public function test_the_appointments_pages_render_and_the_sidebar_exposes_the_link(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $appointment = $this->book($tenant, $owner);

        $this->actingAs($owner)
            ->get('/clinic')
            ->assertOk()
            // The Clinic sidebar exposes a real, routed Appointments link.
            ->assertSee('clinic/appointments', false);

        $this->actingAs($owner)
            ->get('/clinic/appointments')
            ->assertOk();

        $this->actingAs($owner)->get('/clinic/appointments/create')->assertOk();

        $this->actingAs($owner)
            ->get('/clinic/appointments/'.$appointment->getKey())
            ->assertOk();
    }

    public function test_a_valid_ulid_is_used_for_the_record_key(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $appointment = $this->book($tenant, $owner);

        $this->assertTrue(Str::isUlid((string) $appointment->getKey()));
    }
}
