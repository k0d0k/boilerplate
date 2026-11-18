<?php

namespace Modules\Commerce\Filament\Resources;

use App\Models\Brand;
use App\Platform\Access\PermissionKey;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Commerce\Filament\Resources\ProductResource\Pages\CreateProduct;
use Modules\Commerce\Filament\Resources\ProductResource\Pages\EditProduct;
use Modules\Commerce\Filament\Resources\ProductResource\Pages\ListProducts;
use Modules\Commerce\Filament\Resources\ProductResource\Pages\ViewProduct;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Models\ProductCategory;
use Modules\Commerce\Services\ProductCatalog;
use Modules\Commerce\Support\Currency;
use UnitEnum;

/**
 * WS3 — Clinic panel multi-brand product catalog.
 *
 * Operates on the verified current tenant's isolated database. A product may be
 * labelled with a central brand (a commercial identity, never a tenant) and is
 * priced in an explicit currency (IDR / MYR) with optional per-currency prices.
 *
 * Creation and editing go through {@see ProductCatalog} so SKU/slug integrity
 * and currency validation always apply. Products are archived, never deleted, so
 * historical orders keep resolving them.
 *
 * @extends resource<Product>
 */
class ProductResource extends Resource
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'commerce';

    protected static ?string $model = Product::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function getModelLabel(): string
    {
        return __('lba.commerce.products.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.commerce.products.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.commerce.products.nav');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.clinic.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('lba.commerce.products.section_identity'))
                    ->schema([
                        Select::make('brand_id')
                            ->label(__('lba.commerce.products.brand'))
                            ->options(static::brandOptions())
                            ->searchable()
                            ->placeholder(__('lba.commerce.products.brand_none')),
                        Select::make('category_id')
                            ->label(__('lba.commerce.products.category'))
                            ->options(static::categoryOptions())
                            ->searchable()
                            ->placeholder(__('lba.commerce.products.category_none')),
                        TextInput::make('name')
                            ->label(__('lba.commerce.products.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('sku')
                            ->label(__('lba.commerce.products.sku'))
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText(__('lba.commerce.products.sku_help')),
                        TextInput::make('slug')
                            ->label(__('lba.commerce.products.slug'))
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText(__('lba.commerce.products.slug_help')),
                        Select::make('kind')
                            ->label(__('lba.commerce.products.kind'))
                            ->options(static::kindOptions())
                            ->default(Product::KIND_PRODUCT)
                            ->required(),
                        TextInput::make('unit')
                            ->label(__('lba.commerce.products.unit'))
                            ->default('pcs')
                            ->maxLength(20),
                        Select::make('status')
                            ->label(__('lba.commerce.products.status'))
                            ->options(static::statusOptions())
                            ->default(Product::STATUS_ACTIVE)
                            ->required(),
                        Textarea::make('description')
                            ->label(__('lba.commerce.products.description'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('lba.commerce.products.section_pricing'))
                    ->description(__('lba.commerce.products.pricing_notice'))
                    ->schema([
                        Select::make('base_currency')
                            ->label(__('lba.commerce.products.base_currency'))
                            ->options(static::currencyOptions())
                            ->default(Currency::IDR)
                            ->required(),
                        TextInput::make('base_price')
                            ->label(__('lba.commerce.products.base_price'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Repeater::make('prices')
                            ->label(__('lba.commerce.products.currency_prices'))
                            ->relationship()
                            ->schema([
                                Select::make('currency')
                                    ->label(__('lba.commerce.products.currency'))
                                    ->options(static::currencyOptions())
                                    ->required(),
                                TextInput::make('amount')
                                    ->label(__('lba.commerce.products.amount'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->addActionLabel(__('lba.commerce.products.add_price'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('lba.commerce.products.section_inventory'))
                    ->schema([
                        Toggle::make('track_inventory')
                            ->label(__('lba.commerce.products.track_inventory'))
                            ->helperText(__('lba.commerce.products.track_inventory_help'))
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('lba.commerce.products.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sku')
                    ->label(__('lba.commerce.products.sku'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('category.name')
                    ->label(__('lba.commerce.products.category'))
                    ->placeholder('—'),
                TextColumn::make('base_currency')
                    ->label(__('lba.commerce.products.base_currency'))
                    ->badge()
                    ->toggleable(),
                TextColumn::make('base_price')
                    ->label(__('lba.commerce.products.base_price'))
                    ->formatStateUsing(fn (Product $record): string => Currency::format((float) $record->base_price, (string) $record->base_currency))
                    ->sortable(),
                IconColumn::make('track_inventory')
                    ->label(__('lba.commerce.products.track_inventory'))
                    ->boolean(),
                TextColumn::make('status')
                    ->label(__('lba.commerce.products.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.commerce.products.status_{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        Product::STATUS_ACTIVE => 'success',
                        Product::STATUS_INACTIVE => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('lba.commerce.products.status'))
                    ->options(static::statusOptions()),
                SelectFilter::make('category_id')
                    ->label(__('lba.commerce.products.category'))
                    ->options(static::categoryOptions()),
                SelectFilter::make('base_currency')
                    ->label(__('lba.commerce.products.base_currency'))
                    ->options(static::currencyOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('archive')
                    ->label(__('lba.commerce.products.archive'))
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Product $record): bool => static::moduleActive()
                        && static::allows(PermissionKey::COMMERCE_MANAGE)
                        && ! $record->isArchived())
                    ->action(fn (Product $record) => app(ProductCatalog::class)->archive($record)),
            ])
            ->defaultSort('name')
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedShoppingBag)
            ->emptyStateHeading(__('lba.commerce.products.empty_heading'))
            ->emptyStateDescription(__('lba.commerce.products.empty_description'));
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
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

    public static function canView(Model $record): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::COMMERCE_MANAGE);
    }

    public static function canDelete(Model $record): bool
    {
        // Products are archived, never deleted.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Central brand labels. A brand is a commercial identity, so its catalogue
     * lives in the central database.
     *
     * @return array<string, string>
     */
    protected static function brandOptions(): array
    {
        return Brand::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    protected static function categoryOptions(): array
    {
        return ProductCategory::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    protected static function statusOptions(): array
    {
        $options = [];

        foreach (Product::STATUSES as $status) {
            $options[$status] = __("lba.commerce.products.status_{$status}");
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected static function kindOptions(): array
    {
        $options = [];

        foreach (Product::KINDS as $kind) {
            $options[$kind] = __("lba.commerce.products.kind_{$kind}");
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected static function currencyOptions(): array
    {
        $options = [];

        foreach (Currency::SUPPORTED as $currency) {
            $options[$currency] = $currency;
        }

        return $options;
    }
}
