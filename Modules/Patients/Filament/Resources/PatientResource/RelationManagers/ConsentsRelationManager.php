<?php

namespace Modules\Patients\Filament\Resources\PatientResource\RelationManagers;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Patients\Filament\Resources\PatientResource;
use Modules\Patients\Models\PatientAuditLog;
use Modules\Patients\Models\PatientConsent;

/**
 * Phase 3 — recorded patient consents.
 *
 * A consent row only exists once it was explicitly captured; recording names
 * the type, the document version, the actor and the timestamp. Nothing here
 * implies a medical consent that was not explicitly recorded.
 */
class ConsentsRelationManager extends RelationManager
{
    protected static string $relationship = 'consents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.patients.consents.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PatientResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        $names = $this->recorderNames();

        return $table
            ->columns([
                TextColumn::make('consent_type')
                    ->label(__('lba.patients.consents.type'))
                    ->formatStateUsing(fn (string $state): string => __("lba.patients.consents.type_{$state}")),
                TextColumn::make('version')
                    ->label(__('lba.patients.consents.version')),
                TextColumn::make('status')
                    ->label(__('lba.patients.consents.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.patients.consents.status_{$state}"))
                    ->color(fn (string $state): string => $state === PatientConsent::STATUS_GRANTED ? 'success' : 'gray'),
                TextColumn::make('recorded_by')
                    ->label(__('lba.patients.consents.recorded_by'))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : ($names[$state] ?? $state)),
                TextColumn::make('recorded_at')
                    ->label(__('lba.patients.consents.recorded_at'))
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->headerActions([
                Action::make('record')
                    ->label(__('lba.patients.consents.record'))
                    ->icon(Heroicon::OutlinedDocumentCheck)
                    ->visible(fn (): bool => PatientResource::canEdit($this->getOwnerRecord()))
                    ->schema([
                        Select::make('consent_type')
                            ->label(__('lba.patients.consents.type'))
                            ->options($this->consentTypeOptions())
                            ->required(),
                        TextInput::make('version')
                            ->label(__('lba.patients.consents.version'))
                            ->default('v1')
                            ->required()
                            ->maxLength(32),
                    ])
                    ->action(function (array $data): void {
                        $actor = auth()->user();

                        abort_unless($actor instanceof User, 403);

                        $consent = PatientConsent::query()->create([
                            'patient_id' => $this->getOwnerRecord()->getKey(),
                            'consent_type' => $data['consent_type'],
                            'version' => $data['version'],
                            'status' => PatientConsent::STATUS_GRANTED,
                            'recorded_by' => $actor->getKey(),
                            'recorded_at' => now(),
                        ]);

                        PatientAuditLog::query()->create([
                            'patient_id' => $this->getOwnerRecord()->getKey(),
                            'action' => PatientAuditLog::ACTION_CONSENT_RECORDED,
                            'actor_id' => $actor->getKey(),
                            'changes' => ['consent_id' => $consent->getKey(), 'type' => $consent->consent_type],
                            'occurred_at' => now(),
                        ]);

                        Notification::make()->success()->title(__('lba.patients.consents.recorded'))->send();
                    }),
            ])
            ->recordActions([
                Action::make('withdraw')
                    ->label(__('lba.patients.consents.withdraw'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (PatientConsent $record): bool => $record->isGranted() && PatientResource::canEdit($this->getOwnerRecord()))
                    ->requiresConfirmation()
                    ->action(function (PatientConsent $record): void {
                        $actor = auth()->user();

                        $record->withdraw();

                        if ($actor instanceof User) {
                            PatientAuditLog::query()->create([
                                'patient_id' => $this->getOwnerRecord()->getKey(),
                                'action' => PatientAuditLog::ACTION_CONSENT_WITHDRAWN,
                                'actor_id' => $actor->getKey(),
                                'changes' => ['consent_id' => $record->getKey()],
                                'occurred_at' => now(),
                            ]);
                        }

                        Notification::make()->success()->title(__('lba.patients.consents.withdrawn'))->send();
                    }),
            ])
            ->emptyStateHeading(__('lba.patients.consents.empty'));
    }

    /**
     * @return array<string, string>
     */
    private function consentTypeOptions(): array
    {
        $options = [];

        foreach (PatientConsent::TYPES as $type) {
            $options[$type] = __("lba.patients.consents.type_{$type}");
        }

        return $options;
    }

    /**
     * Names of the central users who recorded this patient's consents.
     *
     * @return array<string, string>
     */
    private function recorderNames(): array
    {
        $ids = PatientConsent::query()
            ->where('patient_id', $this->getOwnerRecord()->getKey())
            ->pluck('recorded_by')
            ->filter()
            ->unique()
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
