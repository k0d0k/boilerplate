<?php

namespace Tests\Feature\Filament;

use App\Filament\Clinic\Pages\AccountProfile as ClinicAccountProfile;
use App\Filament\Patient\Pages\AccountProfile as PatientAccountProfile;
use App\Filament\Principal\Pages\AccountProfile as PrincipalAccountProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyAccountEmail;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\DrivesPrincipalPanel;
use Tests\TestCase;

/**
 * WS9.E — self-service ACCOUNT profile across the three panels.
 *
 * The account profile is the LOGIN identity (display name, login email, phone,
 * description) and is distinct from any clinical/patient record. These tests pin
 * the server-enforced boundary: a page only ever saves the SIGNED-IN account, an
 * email change requires the current password and defers until the new address is
 * verified, and the Patient panel keeps its own separate "Profil" page for
 * patient data.
 */
class AccountProfileTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, DrivesPrincipalPanel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /**
     * POST the rendered page snapshot back to Livewire with property updates and
     * a method call — exactly the round-trip a browser performs.
     *
     * @param  array<string, mixed>  $updates
     */
    private function saveProfile(string $html, string $component, array $updates): TestResponse
    {
        return $this->json('POST', app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $this->componentSnapshot($html, $component),
                'calls' => [['method' => 'save', 'params' => [], 'path' => '']],
                'updates' => $updates,
            ]],
        ], ['X-Livewire' => 'true']);
    }

    // ------------------------------------------------------------- Principal

    public function test_the_principal_can_view_and_update_its_own_account_profile(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('principal'));

        $actor = $this->principal();
        $actor->forceFill(['phone' => '0811', 'description' => 'Catatan'])->save();

        $this->actingAs($actor);

        $html = $this->renderPage('/principal/account-profile');
        $this->assertStringContainsString($actor->email, $html);
        $this->assertStringContainsString(__('lba.account.change_password_link'), $html);
        // The page links to the panel's EXISTING change-password flow.
        $this->assertStringContainsString('/principal/change-password', $html);

        $this->saveProfile($html, PrincipalAccountProfile::class, [
            'name' => 'Nama Baru',
            'email' => $actor->email,
            'phone' => '0899',
            'description' => 'Deskripsi baru',
            'address' => 'Jl. Mawar 1',
        ])->assertOk();

        $actor->refresh();

        $this->assertSame('Nama Baru', $actor->name);
        $this->assertSame('0899', $actor->phone);
        $this->assertSame('Deskripsi baru', $actor->description);
        $this->assertSame('Jl. Mawar 1', $actor->address);
    }

    public function test_a_profile_photo_is_stored_privately_and_served_only_to_its_owner(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('principal'));
        Storage::fake('account-photos');

        $actor = $this->principal();
        // The private disk has NO public URL, so `photo_path` is the only handle.
        $path = $actor->getKey().'/avatar.jpg';
        Storage::disk('account-photos')->put($path, 'binary-image', ['visibility' => 'private']);
        $actor->forceFill(['photo_path' => $path])->save();

        // The avatar exposes the authenticated, own-account route — never a
        // public disk URL.
        $this->assertSame(route('account.photo'), $actor->getFilamentAvatarUrl());

        // The owner reads their own photo back through that route.
        $this->actingAs($actor)->get('/account/photo')->assertOk();

        // A different account without a photo never receives someone else's.
        $stranger = User::factory()->create();
        $this->assertNull($stranger->getFilamentAvatarUrl());
        $this->actingAs($stranger)->get('/account/photo')->assertNotFound();
    }

    public function test_a_photoless_account_never_exposes_an_avatar_url(): void
    {
        $this->assertNull(User::factory()->create()->getFilamentAvatarUrl());
    }

    public function test_the_photo_endpoint_requires_an_authenticated_session(): void
    {
        $this->get('/account/photo')->assertRedirect();
    }

    public function test_the_photo_rules_reject_non_images_and_accept_real_images(): void
    {
        $rules = ['photo' => PrincipalAccountProfile::photoValidationRules()];

        // A non-image upload and an unsupported format both fail closed...
        $this->assertTrue(Validator::make(
            ['photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
            $rules,
        )->fails());

        // ...while a genuine image passes.
        $this->assertFalse(Validator::make(
            ['photo' => UploadedFile::fake()->image('avatar.jpg', 32, 32)],
            $rules,
        )->fails());
    }

    public function test_a_save_never_touches_another_account(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('principal'));

        $actor = $this->principal();
        $other = User::factory()->create(['name' => 'Other', 'phone' => '0000']);

        $this->actingAs($actor);

        $html = $this->renderPage('/principal/account-profile');

        $this->saveProfile($html, PrincipalAccountProfile::class, [
            'name' => 'Hanya Saya',
            'email' => $actor->email,
            'phone' => '0777',
            'description' => null,
        ])->assertOk();

        $this->assertSame('Hanya Saya', $actor->refresh()->name);
        $this->assertSame('Other', $other->refresh()->name);
        $this->assertSame('0000', $other->phone);
    }

    public function test_changing_the_login_email_requires_the_current_password_and_defers_until_verified(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('principal'));
        Notification::fake();

        $actor = $this->principal();
        $actor->forceFill(['password' => 'correct-horse-battery'])->save();
        $originalEmail = $actor->email;

        $this->actingAs($actor);

        $html = $this->renderPage('/principal/account-profile');

        $this->saveProfile($html, PrincipalAccountProfile::class, [
            'name' => $actor->name,
            'email' => 'baru@example.test',
            'phone' => null,
            'description' => null,
            'current_password' => 'correct-horse-battery',
        ])->assertOk();

        $actor->refresh();

        // The login email is NOT switched yet; only the pending address is held.
        $this->assertSame($originalEmail, $actor->email);
        $this->assertSame('baru@example.test', $actor->pending_email);

        Notification::assertSentOnDemand(VerifyAccountEmail::class);
    }

    public function test_a_wrong_current_password_keeps_the_login_email_unchanged(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('principal'));
        Notification::fake();

        $actor = $this->principal();
        $actor->forceFill(['password' => 'correct-horse-battery'])->save();
        $originalEmail = $actor->email;

        $this->actingAs($actor);

        $html = $this->renderPage('/principal/account-profile');

        $response = $this->saveProfile($html, PrincipalAccountProfile::class, [
            'name' => $actor->name,
            'email' => 'baru@example.test',
            'phone' => null,
            'description' => null,
            'current_password' => 'salah-sekali',
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            __('lba.account.current_incorrect'),
            (string) $response->json('components.0.effects.html'),
        );

        $actor->refresh();

        $this->assertSame($originalEmail, $actor->email);
        $this->assertNull($actor->pending_email);

        Notification::assertNothingSent();
    }

    // ---------------------------------------------------------------- Clinic

    public function test_clinic_staff_can_update_their_own_account_profile(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('clinic'));

        $actor = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($actor, Tenant::factory()->create(), [RoleKey::TENANT_OWNER]);

        $this->actingAs($actor);

        $html = $this->renderPage('/clinic/account-profile');
        $this->assertStringContainsString($actor->email, $html);
        $this->assertStringContainsString('/clinic/change-password', $html);

        $this->saveProfile($html, ClinicAccountProfile::class, [
            'name' => 'Staf Klinik',
            'email' => $actor->email,
            'phone' => '0812',
            'description' => 'Perawat',
        ])->assertOk();

        $this->assertSame('Staf Klinik', $actor->refresh()->name);
        $this->assertSame('0812', $actor->phone);
    }

    // --------------------------------------------------------------- Patient

    public function test_the_patient_account_profile_is_separate_from_the_patient_data_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('patient'));

        $actor = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($actor, Tenant::factory()->create(), [RoleKey::PATIENT_CUSTOMER]);

        $this->actingAs($actor);

        // The account profile (login identity) renders on its own route...
        $html = $this->renderPage('/patient/profil-akun');
        $this->assertStringContainsString(__('lba.account.title'), $html);
        $this->assertStringContainsString('/patient/ubah-kata-sandi', $html);

        // ...and it is a DIFFERENT page from the patient-data "Profil".
        $this->assertNotSame('profil', PatientAccountProfile::getSlug());

        // Updating the login account never writes patient chart data.
        $this->saveProfile($html, PatientAccountProfile::class, [
            'name' => 'Akun Pasien',
            'email' => $actor->email,
            'phone' => '0813',
            'description' => 'Pasien',
        ])->assertOk();

        $this->assertSame('Akun Pasien', $actor->refresh()->name);
        $this->assertSame('0813', $actor->phone);
    }
}
