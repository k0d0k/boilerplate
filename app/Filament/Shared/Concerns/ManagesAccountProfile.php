<?php

namespace App\Filament\Shared\Concerns;

use App\Models\User;
use App\Platform\Access\AccountEmailChanger;
use App\Platform\Access\Exceptions\AccountEmailChangeException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\WithFileUploads;

/**
 * WS9.E — self-service ACCOUNT profile, shared by the Principal, Clinic and
 * Patient panels.
 *
 * The account profile is the LOGIN identity (display name, login email, phone,
 * description, address and a private photo) and is deliberately distinct from
 * any clinical/patient record: it never reads or writes a patient chart, and
 * the patient panel keeps its own separate "Profil" page for patient data.
 *
 * The acting identity is ALWAYS resolved server-side from the authenticated
 * session (never from a form field or route parameter), so a page can only ever
 * save the signed-in account. The photo is validated server-side (image type
 * and size) and stored on a PRIVATE disk with no public URL. Changing the LOGIN
 * email is deferred and hardened: the current password is required AND the new
 * address must be verified through a signed link before it becomes the login
 * email (see {@see AccountEmailChanger}); a successful save never returns a
 * credential.
 *
 * The page that uses this trait must expose the panel's existing change-password
 * page via {@see changePasswordPageClass()} so the profile links to it.
 */
trait ManagesAccountProfile
{
    use WithFileUploads;

    public ?string $name = null;

    public ?string $email = null;

    public ?string $phone = null;

    public ?string $description = null;

    public ?string $address = null;

    public ?string $current_password = null;

    /** A pending, not-yet-stored profile photo upload. */
    public mixed $photo = null;

    /** When true, the stored photo is removed on save. */
    public bool $remove_photo = false;

    public function mount(): void
    {
        $user = $this->accountUser();

        if ($user === null) {
            return;
        }

        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone = $user->phone;
        $this->description = $user->description;
        $this->address = $user->address;
    }

    /**
     * The signed-in identity, resolved server-side. Read-only page otherwise.
     */
    protected function accountUser(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Whether the signed-in account already has a stored photo.
     */
    public function hasPhoto(): bool
    {
        return $this->accountUser()?->hasPhoto() ?? false;
    }

    /**
     * The private, authenticated URL for the signed-in account's photo.
     */
    public function photoUrl(): ?string
    {
        return $this->hasPhoto() ? route('account.photo') : null;
    }

    /**
     * A requested new login email awaiting verification, or null.
     */
    public function pendingEmail(): ?string
    {
        return $this->accountUser()?->pending_email;
    }

    /**
     * The panel's existing change-password flow.
     */
    public function changePasswordUrl(): string
    {
        return static::changePasswordPageClass()::getUrl();
    }

    public function save(): void
    {
        $user = $this->accountUser();

        if ($user === null) {
            return;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->getKey())],
            'phone' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:1000'],
            'address' => ['nullable', 'string', 'max:255'],
            'photo' => self::photoValidationRules(),
            'remove_photo' => ['boolean'],
        ]);

        // Non-email attributes are always saved for the acting account only.
        $user->name = (string) $validated['name'];
        $user->phone = $validated['phone'] ?? null;
        $user->description = $validated['description'] ?? null;
        $user->address = $validated['address'] ?? null;
        $user->save();

        $this->storePhoto($user);

        // An unchanged email needs nothing further.
        if ($validated['email'] === $user->email) {
            $this->reset('current_password');

            Notification::make()->success()->title(__('lba.account.saved'))->send();

            return;
        }

        // Changing the LOGIN email asks for the current password and defers the
        // switch until the new address is verified.
        $this->validate(['current_password' => ['required', 'string']]);

        try {
            app(AccountEmailChanger::class)->requestChange(
                $user,
                (string) $validated['email'],
                (string) $this->current_password,
            );
        } catch (AccountEmailChangeException) {
            $this->addError('current_password', __('lba.account.current_incorrect'));

            return;
        }

        // The stored email is unchanged until verification; reflect that in the
        // form and surface the pending verification instead.
        $this->email = $user->email;
        $this->reset('current_password');

        Notification::make()->success()->title(__('lba.account.email_change_requested'))->send();
    }

    /**
     * The server-enforced validation for an uploaded profile photo: a real
     * image of an accepted format, within the configured size ceiling.
     *
     * @return list<string>
     */
    public static function photoValidationRules(): array
    {
        return [
            'nullable',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'max:'.(int) config('lba.accounts.photo_max_kilobytes', 2048),
        ];
    }

    /**
     * Persist a newly uploaded photo (or clear the stored one) for the acting
     * account. The file is written to a PRIVATE disk; the previous object is
     * deleted so a replaced photo never lingers.
     */
    private function storePhoto(User $user): void
    {
        $diskName = (string) config('lba.accounts.photo_disk', 'account-photos');
        $disk = Storage::disk($diskName);
        $prefix = trim((string) config('lba.accounts.photo_prefix', 'photos'), '/');
        $previous = $user->photo_path;

        if ($this->remove_photo) {
            if ($previous !== null && $previous !== '') {
                $disk->delete($previous);
            }

            $user->forceFill(['photo_path' => null])->save();
            $this->reset('remove_photo', 'photo');

            return;
        }

        if ($this->photo === null) {
            return;
        }

        $path = $this->photo->store($prefix.'/'.$user->getKey(), ['disk' => $diskName, 'visibility' => 'private']);

        $user->forceFill(['photo_path' => $path])->save();

        if ($previous !== null && $previous !== '' && $previous !== $path) {
            $disk->delete($previous);
        }

        $this->reset('photo');
    }

    /**
     * @return class-string
     */
    abstract protected static function changePasswordPageClass(): string;
}
