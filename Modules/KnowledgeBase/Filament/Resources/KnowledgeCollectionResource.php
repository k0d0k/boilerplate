<?php

namespace Modules\KnowledgeBase\Filament\Resources;

use App\Platform\Access\PermissionKey;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Filament\Actions\EditAction;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeCollectionResource\Pages\CreateKnowledgeCollection;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeCollectionResource\Pages\EditKnowledgeCollection;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeCollectionResource\Pages\ListKnowledgeCollections;
use Modules\KnowledgeBase\Models\KnowledgeCollection;
use Modules\KnowledgeBase\Support\KnowledgeScope;
use UnitEnum;

/**
 * WS9.A — Clinic panel knowledge collections.
 *
 * Operates ONLY on the verified current tenant's TENANT-scope collections. A
 * Clinic Admin can create and curate their own clinic's reference collections;
 * the resource can never see or create a platform-global collection (that is a
 * Principal act), so cross-scope promotion is impossible from this surface.
 *
 * @extends resource<KnowledgeCollection>
 */
class KnowledgeCollectionResource extends Resource
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'knowledge_base';

    protected static ?string $model = KnowledgeCollection::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function getModelLabel(): string
    {
        return __('lba.knowledge.collection_single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.knowledge.collection_plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.knowledge.collection_nav');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.clinic.knowledge');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('lba.knowledge.collection_section'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('lba.knowledge.name'))
                            ->required()
                            ->maxLength(160),
                        Select::make('language')
                            ->label(__('lba.knowledge.language'))
                            ->options(static::languageOptions())
                            ->default('id')
                            ->required(),
                        Textarea::make('description')
                            ->label(__('lba.knowledge.description'))
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label(__('lba.knowledge.is_active'))
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('lba.knowledge.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('language')
                    ->label(__('lba.knowledge.language'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::languageLabel($state)),
                TextColumn::make('documents_count')
                    ->label(__('lba.knowledge.collection_documents'))
                    ->counts('documents'),
                IconColumn::make('is_active')
                    ->label(__('lba.knowledge.is_active'))
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label(__('lba.knowledge.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name')
            ->emptyStateIcon(Heroicon::OutlinedRectangleStack)
            ->emptyStateHeading(__('lba.knowledge.collection_empty_heading'))
            ->emptyStateDescription(__('lba.knowledge.collection_empty_description'));
    }

    public static function getEloquentQuery(): Builder
    {
        $tenantId = app(ResolvesTenantDatabase::class)->current();

        // Fail closed: with no verified tenant context NO collection is listed,
        // and only the caller's own tenant-scope collections are ever visible.
        if ($tenantId === null || $tenantId === '') {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->where('scope', KnowledgeScope::TENANT)
            ->where('scope_key', $tenantId);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeCollections::route('/'),
            'create' => CreateKnowledgeCollection::route('/create'),
            'edit' => EditKnowledgeCollection::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::KNOWLEDGE_MANAGE);
    }

    public static function canCreate(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::KNOWLEDGE_MANAGE);
    }

    public static function canEdit(Model $record): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::KNOWLEDGE_MANAGE);
    }

    public static function canDelete(Model $record): bool
    {
        // Collections are deactivated, never deleted — their documents and audit
        // trail must survive.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Current tenant id (server-derived), for the create page.
     */
    public static function currentTenantId(): ?string
    {
        $tenantId = app(ResolvesTenantDatabase::class)->current();

        return $tenantId === '' ? null : $tenantId;
    }

    /**
     * @return array<string, string>
     */
    public static function languageOptions(): array
    {
        $options = [];

        foreach ((array) config('lba.knowledge.locales', ['id', 'ms-MY', 'en']) as $locale) {
            $options[$locale] = static::languageLabel($locale);
        }

        return $options;
    }

    protected static function languageLabel(string $locale): string
    {
        return match ($locale) {
            'id' => __('lba.knowledge.language_id'),
            'ms-MY' => __('lba.knowledge.language_ms'),
            'en' => __('lba.knowledge.language_en'),
            default => strtoupper($locale),
        };
    }
}
