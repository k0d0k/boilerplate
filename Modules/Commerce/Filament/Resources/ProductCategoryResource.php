<?php

namespace Modules\Commerce\Filament\Resources;

use App\Platform\Access\PermissionKey;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Commerce\Filament\Resources\ProductCategoryResource\Pages\CreateProductCategory;
use Modules\Commerce\Filament\Resources\ProductCategoryResource\Pages\EditProductCategory;
use Modules\Commerce\Filament\Resources\ProductCategoryResource\Pages\ListProductCategories;
use Modules\Commerce\Models\ProductCategory;
use UnitEnum;

/**
 * WS3 — Clinic panel product categories.
 *
 * Operates on the verified current tenant's isolated database. Categories are
 * tenant-local and may nest; they are deactivated (never deleted) so a product
 * keeps its historical grouping.
 *
 * @extends resource<ProductCategory>
 */
class ProductCategoryResource extends Resource
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'commerce';

    protected static ?string $model = ProductCategory::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 41;

    protected static ?string $recordTitleAttribute = 'name';

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function getModelLabel(): string
    {
        return __('lba.commerce.categories.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.commerce.categories.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.commerce.categories.nav');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.clinic.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('lba.commerce.categories.section_identity'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('lba.commerce.categories.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->label(__('lba.commerce.categories.slug'))
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText(__('lba.commerce.categories.slug_help')),
                        Select::make('parent_id')
                            ->label(__('lba.commerce.categories.parent'))
                            ->options(fn (?ProductCategory $record): array => static::parentOptions($record))
                            ->searchable()
                            ->placeholder(__('lba.commerce.categories.parent_none')),
                        TextInput::make('position')
                            ->label(__('lba.commerce.categories.position'))
                            ->numeric()
                            ->default(0),
                        Select::make('status')
                            ->label(__('lba.commerce.categories.status'))
                            ->options(static::statusOptions())
                            ->default(ProductCategory::STATUS_ACTIVE)
                            ->required(),
                        Textarea::make('description')
                            ->label(__('lba.commerce.categories.description'))
                            ->rows(3)
                            ->maxLength(1000)
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
                    ->label(__('lba.commerce.categories.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('parent.name')
                    ->label(__('lba.commerce.categories.parent'))
                    ->placeholder('—'),
                TextColumn::make('products_count')
                    ->label(__('lba.commerce.categories.products'))
                    ->counts('products')
                    ->sortable(),
                TextColumn::make('position')
                    ->label(__('lba.commerce.categories.position'))
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('lba.commerce.categories.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.commerce.categories.status_{$state}"))
                    ->color(fn (string $state): string => $state === ProductCategory::STATUS_ACTIVE ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('lba.commerce.categories.status'))
                    ->options(static::statusOptions()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('position')
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedTag)
            ->emptyStateHeading(__('lba.commerce.categories.empty_heading'))
            ->emptyStateDescription(__('lba.commerce.categories.empty_description'));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListProductCategories::route('/'),
            'create' => CreateProductCategory::route('/create'),
            'edit' => EditProductCategory::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::COMMERCE_MANAGE);
    }

    public static function canCreate(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::COMMERCE_MANAGE);
    }

    public static function canEdit(Model $record): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::COMMERCE_MANAGE);
    }

    public static function canDelete(Model $record): bool
    {
        // Categories are deactivated, never deleted.
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
        $options = [];

        foreach (ProductCategory::STATUSES as $status) {
            $options[$status] = __("lba.commerce.categories.status_{$status}");
        }

        return $options;
    }

    /**
     * Parent choices: every other category, excluding the record itself so a
     * category can never become its own parent.
     *
     * @return array<string, string>
     */
    public static function parentOptions(?ProductCategory $record = null): array
    {
        $query = ProductCategory::query()->orderBy('name');

        if ($record !== null && $record->exists) {
            $query->whereKeyNot($record->getKey());
        }

        return $query->pluck('name', 'id')->all();
    }
}
