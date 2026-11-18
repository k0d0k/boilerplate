<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Appointments\Exceptions\AppointmentConflictException;
use Modules\Appointments\Models\Appointment;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;
use Modules\Practitioners\Exceptions\PractitionerException;
use Modules\Practitioners\Models\Practitioner;
use Modules\Practitioners\Models\PractitionerAuditLog;
use Modules\Practitioners\Models\PractitionerSchedule;
use Modules\Practitioners\Models\PractitionerScheduleException;
use Modules\Practitioners\Services\PractitionerAvailability;
use Modules\Practitioners\Services\PractitionerBooking;
use Modules\Practitioners\Services\PractitionerRegistry;
use Modules\Practitioners\Services\PractitionerScheduleRegistry;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * WS7.B — practice schedules, availability, leave and concurrency-safe booking.
 *
 * Exercises the schedule chokepoint (RBAC, branch authorization, overlap and
 * effective-range validation, audit), the read-only availability computation
 * (weekly windows minus leave plus extra sessions, clipped to the branch window)
 * and the reservation service (schedule gate + double-booking refusal). Only
 * synthetic identities, patients and schedules are used.
 */
class PractitionersSchedulingTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'practitioners';

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

    // --------------------------------------------------------------- Helpers

    private function member(Tenant $tenant, string $roleKey): User
    {
        $user = User::factory()->role($roleKey)->create();
        $this->giveMembership($user, $tenant, [$roleKey]);

        return $user;
    }

    /**
     * @param  list<string>  $roleKeys
     */
    private function clinical(Tenant $tenant, array $roleKeys = [RoleKey::CONSULTANT]): User
    {
        $user = User::factory()->role($roleKeys[0])->create();
        $this->giveMembership($user, $tenant, $roleKeys);

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

    private function schedules(): PractitionerScheduleRegistry
    {
        return app(PractitionerScheduleRegistry::class);
    }

    private function availability(): PractitionerAvailability
    {
        return app(PractitionerAvailability::class);
    }

    /**
     * A verified, branch-authorized practitioner plus their branch.
     *
     * @return array{0: Practitioner, 1: TenantBranch}
     */
    private function readyPractitioner(Tenant $tenant): array
    {
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);

        $branch = $this->createBranch($tenant);

        $practitioner = app(PractitionerRegistry::class)->register($owner, (string) $clinical->getKey(), [
            'display_name' => 'Dr Schedule',
        ]);
        app(PractitionerRegistry::class)->verify($owner, $practitioner);
        app(PractitionerRegistry::class)->authorizeBranch($owner, $practitioner, (string) $branch->getKey());

        return [$practitioner->fresh(), $branch];
    }

    private function futureMonday(): Carbon
    {
        return Carbon::now('UTC')->addWeek()->startOfWeek(Carbon::MONDAY)->setTime(12, 0);
    }

    // -------------------------------------------------------- Schedule writes

    public function test_a_window_requires_the_manage_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);
        $reviewer = $this->member($tenant, RoleKey::REVIEWER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant);

        $this->expectException(PractitionerException::class);

        $this->schedules()->addWindow($reviewer, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '12:00',
        ]);
    }

    public function test_a_window_requires_an_active_branch_authorization(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant);
        $practitioner = app(PractitionerRegistry::class)->register($owner, (string) $clinical->getKey(), [
            'display_name' => 'Dr Unassigned',
        ]);
        app(PractitionerRegistry::class)->verify($owner, $practitioner);

        $this->expectException(PractitionerException::class);

        $this->schedules()->addWindow($owner, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '12:00',
        ]);
    }

    public function test_a_window_creates_an_audited_active_row(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant);

        $window = $this->schedules()->addWindow($owner, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '12:00',
        ]);

        $this->assertTrue($window->isActive());
        $this->assertSame(1, $window->weekday);
        $this->assertDatabaseHas('practitioner_schedules', [
            'id' => $window->getKey(),
            'status' => PractitionerSchedule::STATUS_ACTIVE,
        ], 'tenant');
        $this->assertDatabaseHas('practitioner_audit_logs', [
            'practitioner_id' => $practitioner->getKey(),
            'action' => PractitionerAuditLog::ACTION_SCHEDULE_CREATED,
        ], 'tenant');
    }

    public function test_overlapping_windows_on_the_same_weekday_are_refused(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant);

        $this->schedules()->addWindow($owner, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '12:00',
        ]);

        $this->expectException(PractitionerException::class);

        $this->schedules()->addWindow($owner, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => 1,
            'starts_at' => '11:00',
            'ends_at' => '13:00',
        ]);
    }

    public function test_an_invalid_effective_range_is_refused(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant);

        $this->expectException(PractitionerException::class);

        $this->schedules()->addWindow($owner, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '12:00',
            'effective_from' => '2027-06-01',
            'effective_until' => '2027-05-01',
        ]);
    }

    public function test_removing_a_window_is_non_destructive(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant);

        $window = $this->schedules()->addWindow($owner, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '12:00',
        ]);

        $this->schedules()->removeWindow($owner, $window);

        $this->assertDatabaseHas('practitioner_schedules', [
            'id' => $window->getKey(),
            'status' => PractitionerSchedule::STATUS_INACTIVE,
        ], 'tenant');
    }

    // ------------------------------------------------------------ Availability

    public function test_availability_returns_the_scheduled_slots(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant);

        $this->schedules()->addWindow($owner, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '12:00',
        ]);

        $slots = $this->availability()->slotsForDate($practitioner, $branch, $this->futureMonday());

        // 09:00–12:00 at the branch default 30-minute slots.
        $this->assertCount(6, $slots);
        $this->assertTrue($slots[0]['available']);
        $this->assertSame((string) $practitioner->getKey(), $slots[0]['practitioner_id']);
    }

    public function test_a_leave_exception_subtracts_availability(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant);

        $this->schedules()->addWindow($owner, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '12:00',
        ]);

        $monday = $this->futureMonday()->startOfDay();

        $this->schedules()->recordException($owner, $practitioner, [
            'type' => PractitionerScheduleException::TYPE_LEAVE,
            'starts_at' => $monday->copy()->setTime(9, 30)->toIso8601String(),
            'ends_at' => $monday->copy()->setTime(10, 30)->toIso8601String(),
        ]);

        $slots = $this->availability()->slotsForDate($practitioner, $branch, $this->futureMonday());

        // 09:30 and 10:00 are removed.
        $this->assertCount(4, $slots);
    }

    public function test_an_extra_session_adds_availability(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant);

        $this->schedules()->addWindow($owner, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '12:00',
        ]);

        $monday = $this->futureMonday()->startOfDay();

        $this->schedules()->recordException($owner, $practitioner, [
            'type' => PractitionerScheduleException::TYPE_EXTRA_SESSION,
            'branch_id' => (string) $branch->getKey(),
            'starts_at' => $monday->copy()->setTime(14, 0)->toIso8601String(),
            'ends_at' => $monday->copy()->setTime(15, 0)->toIso8601String(),
        ]);

        $slots = $this->availability()->slotsForDate($practitioner, $branch, $this->futureMonday());

        // 6 weekly + 2 extra.
        $this->assertCount(8, $slots);
    }

    public function test_a_suspended_practitioner_has_no_availability(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $clinical = $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        $branch = $this->createBranch($tenant);
        $practitioner = app(PractitionerRegistry::class)->register($owner, (string) $clinical->getKey(), [
            'display_name' => 'Dr Suspended',
        ]);
        app(PractitionerRegistry::class)->verify($owner, $practitioner);
        app(PractitionerRegistry::class)->authorizeBranch($owner, $practitioner, (string) $branch->getKey());

        $this->schedules()->addWindow($owner, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '12:00',
        ]);

        app(PractitionerRegistry::class)->suspend($owner, $practitioner);

        $slots = $this->availability()->slotsForDate($practitioner->fresh(), $branch, $this->futureMonday());

        $this->assertSame([], $slots);
    }

    // -------------------------------------------------------------- Booking

    public function test_reserving_a_scheduled_slot_creates_an_appointment(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant);

        $this->schedules()->addWindow($owner, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '12:00',
        ]);

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Booking Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        $monday = $this->futureMonday()->startOfDay()->setTime(9, 0);

        $appointment = app(PractitionerBooking::class)->reserve(
            $practitioner,
            $branch,
            $patient,
            $monday,
            Appointment::SERVICE_CONSULTATION,
            actor: $owner,
        );

        $this->assertSame((string) $practitioner->user_id, (string) $appointment->staff_user_id);
        $this->assertSame((string) $branch->getKey(), (string) $appointment->branch_id);
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->getKey(),
            'staff_user_id' => (string) $practitioner->user_id,
        ], 'tenant');
    }

    public function test_a_second_reservation_of_the_same_slot_is_refused(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant);

        $this->schedules()->addWindow($owner, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '12:00',
        ]);

        $first = app(PatientRegistrar::class)->create(['full_name' => 'Synthetic First', 'status' => Patient::STATUS_ACTIVE], $owner);
        $second = app(PatientRegistrar::class)->create(['full_name' => 'Synthetic Second', 'status' => Patient::STATUS_ACTIVE], $owner);

        $monday = $this->futureMonday()->startOfDay()->setTime(9, 0);

        app(PractitionerBooking::class)->reserve($practitioner, $branch, $first, $monday, Appointment::SERVICE_CONSULTATION, actor: $owner);

        $this->expectException(AppointmentConflictException::class);

        app(PractitionerBooking::class)->reserve($practitioner, $branch, $second, $monday, Appointment::SERVICE_CONSULTATION, actor: $owner);
    }

    public function test_reserving_outside_the_schedule_is_refused(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        [$practitioner, $branch] = $this->readyPractitioner($tenant);

        $this->schedules()->addWindow($owner, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '12:00',
        ]);

        $patient = app(PatientRegistrar::class)->create(['full_name' => 'Synthetic Off Schedule', 'status' => Patient::STATUS_ACTIVE], $owner);

        // 15:00 falls outside the 09:00–12:00 window.
        $monday = $this->futureMonday()->startOfDay()->setTime(15, 0);

        $this->expectException(AppointmentConflictException::class);

        app(PractitionerBooking::class)->reserve($practitioner, $branch, $patient, $monday, Appointment::SERVICE_CONSULTATION, actor: $owner);
    }

    public function test_schedules_are_isolated_between_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $ownerA = $this->member($tenantA, RoleKey::TENANT_OWNER);
        $this->clinical($tenantA);
        $this->provisionTenantDatabase($tenantA);
        $this->tenantDatabases()->configure((string) $tenantA->getKey());

        [$practitioner, $branch] = $this->readyPractitioner($tenantA);

        $this->schedules()->addWindow($ownerA, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '12:00',
        ]);

        $tenantB = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenantB);
        $this->tenantDatabases()->configure((string) $tenantB->getKey());

        $this->assertSame(0, PractitionerSchedule::query()->count());
    }

    // ----------------------------------------------------------- Clinic UI

    public function test_the_practitioner_view_page_renders_the_scheduling_relations(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->clinical($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);
        $this->bindTenant($tenant);

        [$practitioner] = $this->readyPractitioner($tenant);

        $this->actingAs($owner)
            ->get("/clinic/practitioners/{$practitioner->getKey()}")
            ->assertOk()
            ->assertSee(__('lba.practitioners.schedules.title'))
            ->assertSee(__('lba.practitioners.exceptions.title'));
    }
}
