<?php

namespace App\Filament\Principal\Resources\TenantResource\RelationManagers;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Filament\Principal\Resources\TenantResource;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Exceptions\PasswordResetException;
use App\Platform\Access\PasswordReset;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RbacCatalog;
use App\Platform\Access\RoleKey;
use App\Platform\Access\StaffInvitationOutcome;
use App\Platform\Access\StaffInviter;
use App\Platform\Access\UserCategory;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 7D — tenant memberships & OWNER assignment (Platform governance).
 *
 * Binds an identity to the tenant through the EXISTING membership + RBAC
 * tables: an owner is simply an active membership holding the `tenant_owner`
 * role. No parallel permission system is introduced — role keys and the
 * assignable set come straight from {@see RbacCatalog}.
 *
 * WS4 Priority 0 adds the functional staff-invitation lifecycle: a Principal
 * invites a staff member (reusing an existing identity or creating the account)
 * as an `invited` membership, which grants NOTHING until it is activated. The
 * invited state is never eligible for Clinic context, so authentication and
 * tenant isolation are never weakened.
 */
class MembershipsRelationManager extends RelationManager
{
    use AuthorizesPrincipalResources;

    protected static string $relationship = 'memberships';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.tenants.memberships');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return TenantResource::canEdit($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('lba.tenants.member'))
                    ->searchable(),
                TextColumn::make('user.email')
                    ->label(__('lba.tenants.email'))
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('lba.tenants.member_status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.tenants.member_status_{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        TenantMembership::STATUS_ACTIVE => 'success',
                        TenantMembership::STATUS_INVITED => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('roles.key')
                    ->label(__('lba.tenants.roles'))
                    ->badge()
                    ->separator(',')
                    ->formatStateUsing(fn (string $state): string => __("lba.roles.{$state}"))
                    ->placeholder('—'),
                // WS6.E — the five understandable user categories, derived from
                // the membership's granular roles. Display only: the underlying
                // permissions are unchanged.
                TextColumn::make('category')
                    ->label(__('lba.user_categories.column'))
                    ->badge()
                    ->color('info')
                    ->state(fn (TenantMembership $record): string => UserCategory::summaryForRoles(
                        $record->roles->pluck('key')->all(),
                    ))
                    ->placeholder('—'),
            ])
            ->headerActions([
                Action::make('assignOwner')
                    ->label(__('lba.tenants.assign_owner'))
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->schema([
                        Select::make('user_id')
                            ->label(__('lba.tenants.member'))
                            ->options($this->candidateUsers())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $this->assignOwner((string) ($data['user_id'] ?? ''));
                    }),
                Action::make('inviteStaff')
                    ->label(__('lba.tenants.invite_staff'))
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->schema([
                        TextInput::make('email')
                            ->label(__('lba.tenants.invite_email'))
                            ->email()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('name')
                            ->label(__('lba.tenants.invite_name'))
                            ->required()
                            ->maxLength(255),
                        Select::make('role_keys')
                            ->label(__('lba.tenants.invite_roles'))
                            ->multiple()
                            ->options($this->staffRoleOptions())
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $this->inviteStaff($data);
                    }),
            ])
            ->recordActions([
                Action::make('roles')
                    ->label(__('lba.tenants.edit_roles'))
                    ->icon(Heroicon::OutlinedKey)
                    ->schema([
                        Select::make('role_keys')
                            ->label(__('lba.tenants.roles'))
                            ->multiple()
                            ->options($this->assignableRoleOptions())
                            ->default(fn (TenantMembership $record): array => $record->roles->pluck('key')->all())
                            ->required(),
                    ])
                    ->action(function (TenantMembership $record, array $data): void {
                        $keys = array_values(array_filter((array) ($data['role_keys'] ?? [])));
                        $ids = Role::query()->whereIn('key', $keys)->pluck('id')->all();
                        $record->roles()->sync($ids);

                        Notification::make()->success()->title(__('lba.tenants.roles_updated'))->send();
                    }),
                Action::make('suspend')
                    ->label(__('lba.tenants.member_suspend'))
                    ->icon(Heroicon::OutlinedPauseCircle)
                    ->color('warning')
                    ->visible(fn (TenantMembership $record): bool => $record->status === TenantMembership::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->action(function (TenantMembership $record): void {
                        $record->update(['status' => TenantMembership::STATUS_SUSPENDED]);
                    }),
                Action::make('activate')
                    ->label(__('lba.tenants.member_activate'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (TenantMembership $record): bool => $record->status !== TenantMembership::STATUS_ACTIVE)
                    ->action(function (TenantMembership $record): void {
                        $record->update([
                            'status' => TenantMembership::STATUS_ACTIVE,
                            'revoked_at' => null,
                        ]);
                    }),
                Action::make('revoke')
                    ->label(__('lba.tenants.member_revoke'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (TenantMembership $record): bool => $record->status !== TenantMembership::STATUS_REVOKED)
                    ->requiresConfirmation()
                    ->action(function (TenantMembership $record): void {
                        $record->update([
                            'status' => TenantMembership::STATUS_REVOKED,
                            'revoked_at' => now(),
                        ]);
                    }),
                Action::make('cancelInvite')
                    ->label(__('lba.tenants.cancel_invite'))
                    ->icon(Heroicon::OutlinedEnvelopeOpen)
                    ->color('gray')
                    ->visible(fn (TenantMembership $record): bool => $record->status === TenantMembership::STATUS_INVITED)
                    ->requiresConfirmation()
                    ->action(function (TenantMembership $record): void {
                        $record->update([
                            'status' => TenantMembership::STATUS_REVOKED,
                            'revoked_at' => now(),
                        ]);

                        Notification::make()->success()->title(__('lba.tenants.invite_cancelled'))->send();
                    }),
                // WS6.D — a Super Admin may START a one-time password reset for
                // another human identity. They never learn or set the new
                // password: the single-use link is delivered to the account's
                // OWN verified email by the existing Laravel password broker,
                // and both the initiate and the redeem are audited centrally.
                Action::make('sendPasswordReset')
                    ->label(__('lba.password_reset.super_admin_action'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->visible(fn (TenantMembership $record): bool => $record->user instanceof User
                        && static::principalCan(PermissionKey::PLATFORM_RBAC_MANAGE))
                    ->requiresConfirmation()
                    ->modalDescription(__('lba.password_reset.super_admin_confirm'))
                    ->action(function (TenantMembership $record): void {
                        $this->sendPasswordReset($record);
                    }),
            ])
            ->emptyStateHeading(__('lba.tenants.empty_members'));
    }

    /**
     * Start a one-time password reset for the membership's user.
     *
     * The acting identity must hold the platform access-management capability
     * (re-checked here, not only in the UI). The raw token never leaves
     * {@see PasswordReset} — it is emailed to the target directly — so a Super
     * Admin can neither read nor choose the new password, and the target's
     * existing session is untouched.
     */
    private function sendPasswordReset(TenantMembership $record): void
    {
        $target = $record->user;
        $actor = Auth::user();

        if (! $target instanceof User || ! $actor instanceof User) {
            Notification::make()->danger()->title(__('lba.password_reset.super_admin_failed'))->send();

            return;
        }

        try {
            app(PasswordReset::class)->request($target, $actor);
        } catch (PasswordResetException) {
            Notification::make()->danger()->title(__('lba.password_reset.super_admin_failed'))->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('lba.password_reset.super_admin_sent'))
            ->body(__('lba.password_reset.super_admin_sent_body', ['email' => (string) $target->email]))
            ->send();
    }

    /**
     * Create (or activate) an ACTIVE membership holding the tenant_owner role.
     */
    private function assignOwner(string $userId): void
    {
        $user = User::query()->find($userId);
        $tenant = $this->getOwnerRecord();

        if (! $user instanceof User || ! $tenant instanceof Tenant) {
            Notification::make()->danger()->title(__('lba.tenants.owner_failed'))->send();

            return;
        }

        $membership = TenantMembership::query()->firstOrCreate(
            ['user_id' => $user->getKey(), 'tenant_id' => $tenant->getKey()],
            ['status' => TenantMembership::STATUS_ACTIVE],
        );

        if ($membership->status !== TenantMembership::STATUS_ACTIVE) {
            $membership->update([
                'status' => TenantMembership::STATUS_ACTIVE,
                'revoked_at' => null,
            ]);
        }

        $roleId = Role::query()->where('key', RoleKey::TENANT_OWNER)->value('id');

        if ($roleId !== null && ! $membership->roles()->whereKey($roleId)->exists()) {
            $membership->roles()->attach($roleId);
        }

        Notification::make()->success()->title(__('lba.tenants.owner_assigned'))->send();
    }

    /**
     * Invite a staff identity to the tenant as an `invited` membership.
     *
     * Delegates to {@see StaffInviter} — the single write path shared with the
     * one-click clinic setup — then surfaces the outcome. The raw single-use
     * activation link is shown ONCE here, to the inviting Principal, and never
     * persisted.
     *
     * @param  array<string, mixed>  $data
     */
    private function inviteStaff(array $data): void
    {
        $tenant = $this->getOwnerRecord();
        $email = trim((string) ($data['email'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $roleKeys = array_values(array_filter((array) ($data['role_keys'] ?? [])));

        if (! $tenant instanceof Tenant) {
            Notification::make()->danger()->title(__('lba.tenants.invite_failed'))->send();

            return;
        }

        $outcome = app(StaffInviter::class)->invite($tenant, $email, $name, $roleKeys, Auth::user());

        match ($outcome->status) {
            StaffInvitationOutcome::STATUS_INVITED => Notification::make()
                ->success()
                ->title(__('lba.tenants.invited'))
                ->body(__($outcome->newAccount ? 'lba.tenants.invite_link_ready' : 'lba.tenants.invite_existing_ready').' '.$outcome->activationUrl)
                ->persistent()
                ->send(),
            StaffInvitationOutcome::STATUS_ALREADY_MEMBER => Notification::make()
                ->warning()
                ->title(__('lba.tenants.invite_already_member'))
                ->send(),
            StaffInvitationOutcome::STATUS_PRINCIPAL_FORBIDDEN => Notification::make()
                ->danger()
                ->title(__('lba.tenants.invite_principal_forbidden'))
                ->send(),
            default => Notification::make()->danger()->title(__('lba.tenants.invite_failed'))->send(),
        };
    }

    /**
     * Clinic-routable staff roles a Principal may assign when inviting, grouped
     * by the five WS6.E user categories so onboarding is unambiguous.
     *
     * Drawn from {@see RoleKey::TENANT_ROLES} so an invited identity always maps
     * to the Clinic panel; the patient role is deliberately excluded from staff
     * invitations (patients onboard through the patient portal, not a staff
     * invitation).
     *
     * @return array<string, array<string, string>>
     */
    private function staffRoleOptions(): array
    {
        return UserCategory::staffInvitationGroups();
    }

    /**
     * Active central users eligible to be a tenant member.
     *
     * @return array<string, string>
     */
    private function candidateUsers(): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                (string) $user->getKey() => $user->email
                    ? $user->name.' · '.$user->email
                    : (string) $user->name,
            ])
            ->all();
    }

    /**
     * Assignable roles for an existing membership, grouped by the five WS6.E
     * user categories with each subrole's permissions shown explicitly.
     *
     * @return array<string, array<string, string>>
     */
    private function assignableRoleOptions(): array
    {
        return UserCategory::groupedRoleOptions(RbacCatalog::membershipAssignableRoles());
    }
}
