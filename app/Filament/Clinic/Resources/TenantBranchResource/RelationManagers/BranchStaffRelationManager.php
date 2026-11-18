<?php

namespace App\Filament\Clinic\Resources\TenantBranchResource\RelationManagers;

use App\Filament\Clinic\Resources\TenantBranchResource;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Branches\BranchStaffAssigner;
use App\Platform\Branches\Models\BranchStaffAssignment;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Phase 2B — tenant-scoped staff branch assignment.
 *
 * Lists the assignments of ONE branch and lets an authorized manager grant or
 * revoke access. Every grant/revoke goes through {@see BranchStaffAssigner},
 * which re-verifies the acting identity's branch-administration role and the
 * staff identity's active central membership; the assignment itself is written
 * to the tenant database (branch ids never cross databases, and `user_id` is a
 * plain central-user id with no cross-database foreign key).
 */
class BranchStaffRelationManager extends RelationManager
{
    protected static string $relationship = 'staffAssignments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.branches.staff.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return TenantBranchResource::canEdit($ownerRecord);
    }

    public function table(Table $table): Table
    {
        $names = $this->staffNames();

        return $table
            ->columns([
                TextColumn::make('user_id')
                    ->label(__('lba.branches.staff.staff'))
                    ->formatStateUsing(fn (string $state): string => $names[$state] ?? $state),
                TextColumn::make('status')
                    ->label(__('lba.branches.staff.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.branches.staff.status_{$state}"))
                    ->color(fn (string $state): string => $state === BranchStaffAssignment::STATUS_ACTIVE ? 'success' : 'gray'),
                TextColumn::make('assigned_at')
                    ->label(__('lba.branches.staff.assigned_at'))
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->headerActions([
                Action::make('assign')
                    ->label(__('lba.branches.staff.assign'))
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->schema([
                        Select::make('user_id')
                            ->label(__('lba.branches.staff.staff'))
                            ->options($this->candidateStaff())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $actor = auth()->user();
                        $staff = User::query()->find($data['user_id'] ?? null);

                        abort_unless($actor instanceof User && $staff instanceof User, 403);

                        try {
                            app(BranchStaffAssigner::class)->assign($actor, $staff, (string) $this->getOwnerRecord()->getKey());
                        } catch (RuntimeException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title(__('lba.branches.staff.assigned'))->send();
                    }),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(__('lba.branches.staff.revoke'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (BranchStaffAssignment $record): bool => $record->isActive())
                    ->requiresConfirmation()
                    ->action(function (BranchStaffAssignment $record): void {
                        $actor = auth()->user();
                        $staff = User::query()->find($record->user_id);

                        abort_unless($actor instanceof User && $staff instanceof User, 403);

                        app(BranchStaffAssigner::class)->revoke($actor, $staff, (string) $record->branch_id);

                        Notification::make()->success()->title(__('lba.branches.staff.revoked'))->send();
                    }),
            ])
            ->emptyStateHeading(__('lba.branches.staff.empty'));
    }

    /**
     * Names of the central users referenced by this branch's assignments.
     *
     * @return array<string, string>
     */
    private function staffNames(): array
    {
        $ids = BranchStaffAssignment::query()
            ->where('branch_id', $this->getOwnerRecord()->getKey())
            ->pluck('user_id')
            ->unique()
            ->all();

        if ($ids === []) {
            return [];
        }

        // Resolved from the CENTRAL database (User is a central model).
        return User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * Active central members of the current tenant holding at least one role —
     * the only identities a branch may be assigned to.
     *
     * @return array<string, string>
     */
    private function candidateStaff(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $tenantId = app(ManagesTenantContext::class)->currentTenantId($user);

        if ($tenantId === null) {
            return [];
        }

        $members = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereHas('roles')
            ->select('user_id');

        return User::query()
            ->where('is_active', true)
            ->whereIn('id', $members)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
