<?php

namespace Modules\KnowledgeBase\Filament\Resources;

use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
use Modules\KnowledgeBase\Exceptions\KnowledgeBaseException;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeDocumentResource\Pages\CreateKnowledgeDocument;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeDocumentResource\Pages\ListKnowledgeDocuments;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeDocumentResource\Pages\ViewKnowledgeDocument;
use Modules\KnowledgeBase\Models\KnowledgeCollection;
use Modules\KnowledgeBase\Models\KnowledgeDocument;
use Modules\KnowledgeBase\Services\KnowledgeDocumentManager;
use Modules\KnowledgeBase\Services\KnowledgeIndexer;
use Modules\KnowledgeBase\Services\KnowledgeIngestionService;
use Modules\KnowledgeBase\Support\KnowledgeScope;
use UnitEnum;

/**
 * WS9.A — Clinic panel knowledge documents (tenant-scope only).
 *
 * A Clinic Admin uploads, verifies, publishes, archives and revokes their OWN
 * clinic's reference PDFs. No document is retrievable until a human publishes
 * it, and the raw PDF is only ever served through the private authenticated
 * stream — never a public URL.
 *
 * @extends resource<KnowledgeDocument>
 */
class KnowledgeDocumentResource extends Resource
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'knowledge_base';

    protected static ?string $model = KnowledgeDocument::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 31;

    protected static ?string $recordTitleAttribute = 'title';

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function getModelLabel(): string
    {
        return __('lba.knowledge.document_single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.knowledge.document_plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.knowledge.document_nav');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.clinic.knowledge');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('lba.knowledge.document_section_source'))
                    ->schema([
                        Select::make('collection_id')
                            ->label(__('lba.knowledge.document_collection'))
                            ->options(static::collectionOptions())
                            ->required()
                            ->searchable(),
                        TextInput::make('title')
                            ->label(__('lba.knowledge.title'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('author')
                            ->label(__('lba.knowledge.author'))
                            ->maxLength(255),
                        TextInput::make('publication')
                            ->label(__('lba.knowledge.publication'))
                            ->maxLength(255),
                        DatePicker::make('published_on')
                            ->label(__('lba.knowledge.published_on')),
                        Select::make('language')
                            ->label(__('lba.knowledge.language'))
                            ->options(KnowledgeCollectionResource::languageOptions())
                            ->default('id')
                            ->required(),
                        TextInput::make('license')
                            ->label(__('lba.knowledge.license'))
                            ->maxLength(255),
                        TextInput::make('source_url')
                            ->label(__('lba.knowledge.source_url'))
                            ->url()
                            ->maxLength(255),
                    ])
                    ->columns(2),
                Section::make(__('lba.knowledge.document_section_file'))
                    ->schema([
                        FileUpload::make('pdf')
                            ->label(__('lba.knowledge.document_pdf'))
                            ->storeFiles(false)
                            ->acceptedFileTypes(['application/pdf'])
                            ->maxSize((int) config('lba.knowledge.max_kilobytes', 51200))
                            ->helperText(__('lba.knowledge.document_pdf_hint'))
                            ->required(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('lba.knowledge.title'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('collection.name')
                    ->label(__('lba.knowledge.document_collection'))
                    ->placeholder('—'),
                TextColumn::make('publication')
                    ->label(__('lba.knowledge.publication'))
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('language')
                    ->label(__('lba.knowledge.language'))
                    ->badge(),
                TextColumn::make('current_version')
                    ->label(__('lba.knowledge.document_version'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('pages')
                    ->label(__('lba.knowledge.document_pages'))
                    ->state(fn (KnowledgeDocument $record): string => (string) ($record->currentVersionRecord()?->page_count ?? '—')),
                TextColumn::make('status')
                    ->label(__('lba.knowledge.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.knowledge.status_{$state}"))
                    ->color(fn (string $state): string => KnowledgeDocument::statusColor($state)),
                TextColumn::make('created_at')
                    ->label(__('lba.knowledge.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('lba.knowledge.filter_status'))
                    ->options(static::statusOptions()),
                SelectFilter::make('language')
                    ->label(__('lba.knowledge.language'))
                    ->options(KnowledgeCollectionResource::languageOptions()),
                SelectFilter::make('collection_id')
                    ->label(__('lba.knowledge.document_collection'))
                    ->options(static::collectionOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                static::downloadAction(),
                static::verifyAction(),
                static::publishAction(),
                static::archiveAction(),
                static::revokeAction(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->emptyStateHeading(__('lba.knowledge.document_empty_heading'))
            ->emptyStateDescription(__('lba.knowledge.document_empty_description'));
    }

    public static function getEloquentQuery(): Builder
    {
        $tenantId = app(ResolvesTenantDatabase::class)->current();

        if ($tenantId === null || $tenantId === '') {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        // Tenant-scope only: a Clinic Admin never sees platform-global sources
        // or another tenant's documents.
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
            'index' => ListKnowledgeDocuments::route('/'),
            'create' => CreateKnowledgeDocument::route('/create'),
            'view' => ViewKnowledgeDocument::route('/{record}'),
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

    public static function canView(Model $record): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::KNOWLEDGE_MANAGE);
    }

    public static function canEdit(Model $record): bool
    {
        // Documents are immutable revisions; they are never edited in place.
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Open the private, authenticated PDF stream (a new tab).
     */
    protected static function downloadAction(): Action
    {
        return Action::make('download')
            ->label(__('lba.knowledge.document_open'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->url(fn (KnowledgeDocument $record): string => route('lba.knowledge.documents.download', ['document' => $record->getKey()]))
            ->openUrlInNewTab()
            ->visible(fn (KnowledgeDocument $record): bool => $record->isPublished());
    }

    protected static function verifyAction(): Action
    {
        return Action::make('verify')
            ->label(__('lba.knowledge.action_verify'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (KnowledgeDocument $record): bool => static::moduleActive()
                && static::allows(PermissionKey::KNOWLEDGE_MANAGE)
                && $record->status === KnowledgeDocument::STATUS_REVIEW)
            ->action(function (KnowledgeDocument $record): void {
                static::run($record, fn (User $actor) => app(KnowledgeDocumentManager::class)->verify($record, $actor));
            });
    }

    protected static function publishAction(): Action
    {
        return Action::make('publish')
            ->label(__('lba.knowledge.action_publish'))
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('lba.knowledge.publish_hint'))
            ->visible(fn (KnowledgeDocument $record): bool => static::moduleActive()
                && static::allows(PermissionKey::KNOWLEDGE_MANAGE)
                && $record->status === KnowledgeDocument::STATUS_REVIEW)
            ->action(function (KnowledgeDocument $record): void {
                static::run($record, function (User $actor) use ($record): void {
                    app(KnowledgeDocumentManager::class)->publish($record, $actor);
                    // (Re)build the page-aware retrieval index for the now-approved
                    // current version. Idempotent and safe to repeat.
                    app(KnowledgeIndexer::class)->reindex($record->refresh());
                });
            });
    }

    protected static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label(__('lba.knowledge.action_archive'))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (KnowledgeDocument $record): bool => static::moduleActive()
                && static::allows(PermissionKey::KNOWLEDGE_MANAGE)
                && $record->status === KnowledgeDocument::STATUS_PUBLISHED)
            ->action(function (KnowledgeDocument $record): void {
                static::run($record, fn (User $actor) => app(KnowledgeDocumentManager::class)->archive($record, $actor));
            });
    }

    protected static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('lba.knowledge.action_revoke'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('lba.knowledge.revoke_hint'))
            ->visible(fn (KnowledgeDocument $record): bool => static::moduleActive()
                && static::allows(PermissionKey::KNOWLEDGE_MANAGE)
                && $record->status !== KnowledgeDocument::STATUS_REVOKED)
            ->action(function (KnowledgeDocument $record): void {
                static::run($record, function (User $actor) use ($record): void {
                    app(KnowledgeDocumentManager::class)->revoke($record, $actor);
                    // Revocation purges the indexed chunks so a revoked source can
                    // never be retrieved again.
                    app(KnowledgeIndexer::class)->purge($record);
                });
            });
    }

    /**
     * Run a lifecycle mutation on behalf of the acting human, surfacing any
     * domain error as a safe notification.
     */
    protected static function run(KnowledgeDocument $record, callable $callback): void
    {
        $actor = static::currentUser();

        if ($actor === null) {
            Notification::make()->danger()->title(__('lba.knowledge.error_not_authorized'))->send();

            return;
        }

        try {
            $callback($actor);

            Notification::make()->success()->title(__('lba.knowledge.action_done'))->send();
        } catch (KnowledgeBaseException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        $options = [];

        foreach (KnowledgeDocument::STATUSES as $status) {
            $options[$status] = __("lba.knowledge.status_{$status}");
        }

        return $options;
    }

    /**
     * Tenant-scope collections of the verified tenant.
     *
     * @return array<string, string>
     */
    public static function collectionOptions(): array
    {
        $tenantId = app(ResolvesTenantDatabase::class)->current();

        if ($tenantId === null || $tenantId === '') {
            return [];
        }

        return KnowledgeCollection::query()
            ->where('scope', KnowledgeScope::TENANT)
            ->where('scope_key', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The ingest service, used by the create page.
     */
    public static function ingestion(): KnowledgeIngestionService
    {
        return app(KnowledgeIngestionService::class);
    }
}
