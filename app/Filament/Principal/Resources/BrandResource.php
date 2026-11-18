<?php

namespace App\Filament\Principal\Resources;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Filament\Principal\Resources\BrandResource\Pages\CreateBrand;
use App\Filament\Principal\Resources\BrandResource\Pages\EditBrand;
use App\Filament\Principal\Resources\BrandResource\Pages\ListBrands;
use App\Filament\Principal\Resources\BrandResource\RelationManagers\TenantsRelationManager;
use App\Models\Brand;
use App\Platform\Access\PermissionKey;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Phase 7D — Principal Brand Management.
 *
 * A brand is a COMMERCIAL identity only: it is never a tenant and never a branch
 * (ARCH-05) and it holds no clinical data. This resource administers the central
 * brand catalog — lifecycle status, the tenant↔brand relationship and the
 * presentation "report identity" used on patient-facing PDFs.
 *
 * Authorization is delegated to {@see AuthorizesPrincipalResources}: only an
 * active principal holding `platform.brand.manage` may view or change brands.
 * Brands are never destroyed here — suspension is the non-destructive lifecycle
 * action, so historical reports keep resolving their brand identity.
 *
 * @extends resource<Brand>
 */
class BrandResource extends Resource
{
    use AuthorizesPrincipalResources;

    protected static ?string $model = Brand::class;

    // The canonical "Brand & Mitra" entry is the TailAdmin governance page
    // (brand + partner tabs); this legacy resource stays routable but must not
    // duplicate that sidebar entry.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('lba.brands.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.nav.principal.brands');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.principal.group_governance');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('lba.brands.section_identity'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('lba.brands.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->label(__('lba.brands.slug'))
                            ->required()
                            ->maxLength(255)
                            ->unique(table: 'brands', column: 'slug', ignoreRecord: true),
                        Select::make('status')
                            ->label(__('lba.brands.status'))
                            ->options(static::statusOptions())
                            ->default(Brand::STATUS_ACTIVE)
                            ->required(),
                    ])
                    ->columns(2),
                Section::make(__('lba.brands.section_report'))
                    ->description(__('lba.brands.report_notice'))
                    ->schema([
                        TextInput::make('report_display_name')
                            ->label(__('lba.brands.report_display_name'))
                            ->maxLength(255),
                        Select::make('report_language')
                            ->label(__('lba.brands.report_language'))
                            ->options(static::languageOptions())
                            ->default(Brand::REPORT_LANGUAGE_DEFAULT)
                            ->required(),
                        TextInput::make('report_website')
                            ->label(__('lba.brands.report_website'))
                            ->maxLength(255)
                            ->url(),
                        TextInput::make('report_contact')
                            ->label(__('lba.brands.report_contact'))
                            ->maxLength(255),
                        Textarea::make('report_address')
                            ->label(__('lba.brands.report_address'))
                            ->rows(2)
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('report_header')
                            ->label(__('lba.brands.report_header'))
                            ->rows(2)
                            ->columnSpanFull(),
                        Textarea::make('report_footer')
                            ->label(__('lba.brands.report_footer'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('lba.brands.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(__('lba.brands.slug'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('lba.brands.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.brands.status_{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        Brand::STATUS_ACTIVE => 'success',
                        Brand::STATUS_SUSPENDED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('report_language')
                    ->label(__('lba.brands.report_language'))
                    ->badge()
                    ->toggleable(),
                TextColumn::make('tenants_count')
                    ->label(__('lba.brands.tenants'))
                    ->counts('tenants')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('lba.brands.filter_status'))
                    ->options(static::statusOptions()),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('activate')
                    ->label(__('lba.brands.activate'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Brand $record): bool => $record->status !== Brand::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->action(function (Brand $record): void {
                        $record->update(['status' => Brand::STATUS_ACTIVE]);
                    }),
                Action::make('suspend')
                    ->label(__('lba.brands.suspend'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (Brand $record): bool => $record->status === Brand::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->action(function (Brand $record): void {
                        $record->update(['status' => Brand::STATUS_SUSPENDED]);
                    }),
            ])
            ->defaultSort('name')
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedBuildingOffice2)
            ->emptyStateHeading(__('lba.brands.empty_heading'))
            ->emptyStateDescription(__('lba.brands.empty_description'));
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            TenantsRelationManager::class,
        ];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListBrands::route('/'),
            'create' => CreateBrand::route('/create'),
            'edit' => EditBrand::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_BRAND_MANAGE);
    }

    public static function canCreate(): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_BRAND_MANAGE);
    }

    public static function canEdit(Model $record): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_BRAND_MANAGE);
    }

    public static function canDelete(Model $record): bool
    {
        // Suspension is non-destructive; brands are never deleted.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    protected static function statusOptions(): array
    {
        return [
            Brand::STATUS_ACTIVE => __('lba.brands.status_active'),
            Brand::STATUS_SUSPENDED => __('lba.brands.status_suspended'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function languageOptions(): array
    {
        $options = [];

        foreach ((array) config('lba.reports.locales', [Brand::REPORT_LANGUAGE_DEFAULT]) as $locale) {
            $options[(string) $locale] = (string) $locale;
        }

        return $options;
    }
}
