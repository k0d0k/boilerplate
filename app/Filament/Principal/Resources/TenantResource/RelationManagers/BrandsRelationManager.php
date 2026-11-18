<?php

namespace App\Filament\Principal\Resources\TenantResource\RelationManagers;

use App\Filament\Principal\Resources\TenantResource;
use App\Models\Brand;
use App\Models\Tenant;
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

/**
 * Phase 7D — the brands a tenant operates under.
 *
 * Brand ≠ tenant (ARCH-05): this manager edits the central `tenant_brands`
 * pivot only. Marking a brand primary for the tenant clears the flag on the
 * tenant's other brands, so exactly one default stays valid.
 */
class BrandsRelationManager extends RelationManager
{
    protected static string $relationship = 'brands';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.tenants.brands');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return TenantResource::canEdit($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('lba.brands.name'))
                    ->searchable(),
                TextColumn::make('slug')
                    ->label(__('lba.brands.slug'))
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('lba.brands.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.brands.status_{$state}"))
                    ->color(fn (string $state): string => $state === Brand::STATUS_ACTIVE ? 'success' : 'danger'),
                IconColumn::make('is_primary')
                    ->label(__('lba.brands.is_primary'))
                    ->boolean()
                    ->getStateUsing(fn (Brand $record): bool => (bool) $record->pivot->is_primary),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label(__('lba.tenants.attach_brand'))
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Toggle::make('is_primary')
                            ->label(__('lba.brands.is_primary')),
                    ]),
            ])
            ->recordActions([
                Action::make('makePrimary')
                    ->label(__('lba.brands.make_primary'))
                    ->icon(Heroicon::OutlinedStar)
                    ->color('warning')
                    ->visible(fn (Brand $record): bool => ! (bool) $record->pivot->is_primary)
                    ->action(function (Brand $record): void {
                        /** @var Tenant $tenant */
                        $tenant = $this->getOwnerRecord();

                        $tenant->brands()->newPivotStatement()
                            ->where('tenant_id', $tenant->getKey())
                            ->update(['is_primary' => false]);

                        $tenant->brands()->updateExistingPivot($record->getKey(), ['is_primary' => true]);

                        Notification::make()->success()->title(__('lba.brands.primary_set'))->send();
                    }),
                DetachAction::make()
                    ->label(__('lba.brands.detach_tenant')),
            ])
            ->emptyStateHeading(__('lba.brands.empty_tenants'));
    }
}
