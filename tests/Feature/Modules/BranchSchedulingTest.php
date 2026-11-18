<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Branches\Models\TenantBranch;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Appointments\Exceptions\AppointmentConflictException;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\Appointments\Services\BranchAvailability;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 10 (WS1) — branch / location scheduling and availability.
 *
 * Focused essentials for the scheduling rules added in Workstream 1: the
 * read-only availability projection, the temporary / event operating window
 * that the {@see AppointmentScheduler} enforces, and the branch-level capacity
 * guard. Only synthetic identities and patients are used (DECISIONS.md PRV-01).
 */
class BranchSchedulingTest extends TestCase
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

    private function owner(Tenant $tenant): User
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        return $user;
    }

    private function patient(Tenant $tenant, User $actor, string $name = 'Synthetic Schedule Patient'): Patient
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());

        return app(PatientRegistrar::class)->create([
            'full_name' => $name,
            'status' => Patient::STATUS_ACTIVE,
        ], $actor);
    }

    private function book(TenantBranch $branch, Patient $patient, User $actor, Carbon $starts, int $minutes = 30): Appointment
    {
        return app(AppointmentScheduler::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addMinutes($minutes),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ], $actor);
    }

    // ------------------------------------------------- Availability projection

    public function test_availability_returns_the_bookable_slots_of_an_active_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant, [
            'timezone' => config('app.timezone'),
            'opens_at' => '09:00',
            'closes_at' => '11:00',
            'slot_minutes' => 30,
        ]);

        $slots = app(BranchAvailability::class)
            ->slotsForDate($branch, now()->addDays(3)->startOfDay());

        $this->assertCount(4, $slots);
        $this->assertTrue($slots[0]['available']);
        $this->assertNull($slots[0]['capacity']);
    }

    public function test_availability_is_empty_for_an_inactive_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant, [
            'status' => TenantBranch::STATUS_INACTIVE,
            'opens_at' => '09:00',
            'closes_at' => '11:00',
        ]);

        $this->assertSame(
            [],
            app(BranchAvailability::class)->slotsForDate($branch, now()->addDay()->startOfDay()),
        );
    }

    public function test_availability_reports_remaining_capacity_for_an_occupied_slot(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant, [
            'timezone' => config('app.timezone'),
            'opens_at' => '09:00',
            'closes_at' => '11:00',
            'slot_minutes' => 30,
            'capacity' => 1,
        ]);

        $patient = $this->patient($tenant, $owner);
        $day = now()->addDays(3)->startOfDay();

        $this->book($branch, $patient, $owner, $day->copy()->setTime(9, 0));

        $slots = app(BranchAvailability::class)->slotsForDate($branch, $day);

        // The 09:00 slot is full (capacity 1); the rest remain open.
        $this->assertSame(0, $slots[0]['remaining']);
        $this->assertFalse($slots[0]['available']);
        $this->assertSame(1, $slots[1]['remaining']);
        $this->assertTrue($slots[1]['available']);
    }

    // ------------------------------------------------- Operating window

    public function test_an_event_location_is_bookable_only_inside_its_window(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant, [
            'location_type' => TenantBranch::LOCATION_EVENT,
            'operates_from' => now()->addDays(2),
            'operates_until' => now()->addDays(4),
        ]);

        $patient = $this->patient($tenant, $owner);

        // Inside the window: accepted.
        $inside = $this->book($branch, $patient, $owner, now()->addDays(3)->setTime(9, 0));
        $this->assertSame(Appointment::STATUS_SCHEDULED, $inside->status);

        // Before the window: rejected by the scheduler guard.
        try {
            $this->book($branch, $patient, $owner, now()->addDay()->setTime(9, 0));
            $this->fail('A booking before the event window must be rejected.');
        } catch (AppointmentConflictException $exception) {
            $this->assertStringContainsString('not open', $exception->getMessage());
        }
    }

    // ------------------------------------------------- Capacity guard

    public function test_the_branch_capacity_blocks_an_overlapping_booking(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant, [
            'opens_at' => '09:00',
            'closes_at' => '17:00',
            'capacity' => 1,
        ]);

        $patient = $this->patient($tenant, $owner);
        $starts = now()->addDays(3)->setTime(10, 0);

        $this->book($branch, $patient, $owner, $starts);

        // A second, overlapping booking at the same capped location is refused.
        try {
            $this->book($branch, $patient, $owner, $starts->copy()->addMinutes(15));
            $this->fail('A booking beyond the branch capacity must be rejected.');
        } catch (AppointmentConflictException $exception) {
            $this->assertStringContainsString('capacity', $exception->getMessage());
        }

        // A non-overlapping booking is still accepted.
        $later = $this->book($branch, $patient, $owner, $starts->copy()->addHours(2));
        $this->assertSame(Appointment::STATUS_SCHEDULED, $later->status);
    }

    public function test_a_branch_without_capacity_is_unlimited(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant);
        $patient = $this->patient($tenant, $owner);
        $starts = now()->addDays(3)->setTime(10, 0);

        $this->book($branch, $patient, $owner, $starts);
        $second = $this->book($branch, $patient, $owner, $starts->copy()->addMinutes(5));

        $this->assertSame(Appointment::STATUS_SCHEDULED, $second->status);
    }
}
