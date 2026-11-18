<?php

namespace Modules\LBA\Filament\Resources\ExaminationResource\RelationManagers;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Modules\LBA\AI\Exceptions\AiAnalysisException;
use Modules\LBA\AI\Services\AiAnalysisService;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;
use Modules\LBA\Services\MicroscopyImageStore;

/**
 * Phase 4 — private microscopy images of an examination.
 *
 * Uploads are validated by {@see MicroscopyImageStore} (real MIME, decodability,
 * configured size/pixel limits, explicit image-processing consent) and stored
 * ORIGINAL and IMMUTABLE on the private disk under an opaque key. The table
 * deliberately shows NO original filename and NO storage path: an image is only
 * ever read back through the authenticated streaming endpoint.
 */
class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    protected static ?string $title = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.exams.images.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ExaminationResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('lba.exams.images.image'))
                    ->formatStateUsing(fn (string $state): string => __('lba.exams.images.image_label', [
                        'ref' => strtoupper(substr($state, -6)),
                    ])),
                TextColumn::make('mime_type')
                    ->label(__('lba.exams.images.format'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('dimensions')
                    ->label(__('lba.exams.images.dimensions'))
                    ->state(fn (ExaminationImage $record): string => $record->hasDimensions()
                        ? "{$record->width} × {$record->height}"
                        : '—'),
                TextColumn::make('size_bytes')
                    ->label(__('lba.exams.images.size'))
                    ->formatStateUsing(fn (int $state): string => number_format($state / 1024, 1).' KB'),
                TextColumn::make('created_at')
                    ->label(__('lba.exams.images.uploaded_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('uploadImages')
                    ->label(__('lba.exams.upload_images'))
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->visible(fn (): bool => ExaminationResource::canEdit($this->getOwnerRecord()))
                    ->schema([
                        FileUpload::make('images')
                            ->label(__('lba.exams.upload_images'))
                            ->multiple()
                            ->storeFiles(false)
                            ->acceptedFileTypes((array) config('lba.images.allowed_mimes'))
                            ->maxFiles((int) config('lba.images.max_per_upload'))
                            ->maxSize((int) config('lba.images.max_kilobytes'))
                            ->helperText(__('lba.exams.upload_hint'))
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $examination = $this->getOwnerRecord();

                        if (! $examination instanceof Examination) {
                            return;
                        }

                        $actor = auth()->user();
                        $store = app(MicroscopyImageStore::class);
                        $stored = 0;

                        try {
                            foreach ((array) ($data['images'] ?? []) as $file) {
                                if (! $file instanceof UploadedFile) {
                                    continue;
                                }

                                $store->store($examination, $file, $actor instanceof User ? $actor : null, [
                                    'source' => ExaminationImage::SOURCE_MANUAL_IMPORT,
                                ]);
                                $stored++;
                            }
                        } catch (ExaminationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title($exception->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('lba.exams.images_uploaded', ['count' => $stored]))
                            ->send();
                    }),
            ])
            ->recordActions([
                // Phase 5 — start an asynchronous AI vision analysis for this
                // image. It is gated by the server-side AI-start permission, a
                // compatible mode, an editable examination, a granted
                // image-processing consent and an enabled AI environment; the
                // job itself is idempotent, so re-running never duplicates work.
                Action::make('startAiAnalysis')
                    ->label(__('lba.exams.ai.start'))
                    ->icon(Heroicon::OutlinedCpuChip)
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription(__('lba.exams.ai.start_hint'))
                    ->visible(fn (): bool => ExaminationResource::canStartAiAnalysis($this->getOwnerRecord()))
                    ->action(function (ExaminationImage $record): void {
                        $examination = $this->getOwnerRecord();

                        if (! $examination instanceof Examination) {
                            return;
                        }

                        try {
                            app(AiAnalysisService::class)->startAnalysis(
                                $examination,
                                $record,
                                ExaminationResource::currentActor(),
                            );

                            Notification::make()
                                ->success()
                                ->title(__('lba.exams.ai.queued'))
                                ->send();
                        } catch (AiAnalysisException $exception) {
                            Notification::make()
                                ->danger()
                                ->title($exception->getMessage())
                                ->send();
                        }
                    }),
                // Original microscopy images are retained and immutable; physical
                // deletion stays disabled until an approved retention/archive
                // policy exists, and no image removal may cascade away existing
                // annotations.
                Action::make('retainedImage')
                    ->label(__('lba.exams.images.retention_locked'))
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->color('gray')
                    ->disabled()
                    ->tooltip(__('lba.exams.images.retention_locked')),
            ])
            ->defaultSort('created_at')
            ->emptyStateHeading(__('lba.exams.images.empty'));
    }
}
