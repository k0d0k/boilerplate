<?php

namespace App\Filament\Principal\Resources\BrandResource\RelationManagers;

use App\Filament\Principal\Pages\TenantSupport;
use App\Filament\Principal\Resources\BrandResource;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\SupportTenantSession;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 7D — the tenants operating under a brand.
 *
 * Brand ≠ tenant (ARCH-05): this manager edits the central `tenant_brands`
 * pivot only. Attaching a tenant associates the brand without touching the
 * tenant's isolated database, and marking a brand primary for a tenant clears
 * the flag on the tenant's other brands so exactly one default stays valid.
 */
class TenantsRelationManager extends RelationManager
{
    protected static string $relationship = 'tenants';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.brands.tenants');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return BrandResource::canEdit($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('lba.tenants.name'))
                    ->searchable(),
                TextColumn::make('slug')
                    ->label(__('lba.tenants.slug'))
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('lba.tenants.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.tenants.status_{$state}"))
                    ->color(fn (string $state): string => $state === Tenant::STATUS_ACTIVE ? 'success' : 'danger'),
                IconColumn::make('is_primary')
                    ->label(__('lba.brands.is_primary'))
                    ->boolean()
                    ->getStateUsing(fn (Tenant $record): bool => (bool) $record->pivot->is_primary),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label(__('lba.brands.attach_tenant'))
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Toggle::make('is_primary')
                            ->label(__('lba.brands.is_primary')),
                    ]),
            ])
            ->recordActions([
                Action::make('openTenant')
                    ->label(__('lba.support.open'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->visible(fn (Tenant $record): bool => $record->status === Tenant::STATUS_ACTIVE)
                    ->action(function (Tenant $record) {
                        $actor = Auth::user();

                        $opened = $actor instanceof User
                            && app(SupportTenantSession::class)->open($actor, (string) $record->getKey());

                        if (! $opened) {
                            Notification::make()->danger()->title(__('lba.support.open_failed'))->send();

                            return null;
                        }

                        return redirect()->to(TenantSupport::getUrl(panel: 'principal'));
                    }),
                Action::make('makePrimary')
                    ->label(__('lba.brands.make_primary'))
                    ->icon(Heroicon::OutlinedStar)
                    ->color('warning')
                    ->visible(fn (Tenant $record): bool => ! (bool) $record->pivot->is_primary)
                    ->action(function (Tenant $record): void {
                        /** @var Brand $brand */
                        $brand = $this->getOwnerRecord();

                        // Exactly one primary brand per tenant.
                        $brand->tenants()->newPivotStatement()
                            ->where('tenant_id', $record->getKey())
                            ->update(['is_primary' => false]);

                        $brand->tenants()->updateExistingPivot($record->getKey(), ['is_primary' => true]);

                        Notification::make()->success()->title(__('lba.brands.primary_set'))->send();
                    }),
                DetachAction::make()
                    ->label(__('lba.brands.detach_tenant')),
            ])
            ->emptyStateHeading(__('lba.brands.empty_tenants'));
    }
}
