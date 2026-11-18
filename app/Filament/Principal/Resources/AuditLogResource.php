<?php

namespace App\Filament\Principal\Resources;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Filament\Principal\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Platform\Access\PermissionKey;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * WS4.4 — Principal audit log and security review.
 *
 * A READ-ONLY, redacted view of the central module/authorization audit trail
 * (`module_audit_logs`). Each record carries only a module key, an action, an
 * outcome, an optional reason and opaque scoped references — never a patient,
 * an examination, an image or a clinical finding. No record may be created,
 * edited or deleted through this surface.
 *
 * Viewing requires `platform.audit.view`; the security posture summary is
 * rendered as a header widget so denial trends are visible at a glance.
 *
 * @extends resource<ModuleAuditLog>
 */
class AuditLogResource extends Resource
{
    use AuthorizesPrincipalResources;

    protected static ?string $model = ModuleAuditLog::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 8;

    public static function getModelLabel(): string
    {
        return __('lba.audit.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.audit.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.principal.audit');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.principal.group_governance');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('lba.audit.column_time'))
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),
                TextColumn::make('module_key')
                    ->label(__('lba.audit.column_module'))
                    ->searchable()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('action')
                    ->label(__('lba.audit.column_action'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('result')
                    ->label(__('lba.audit.column_result'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.audit.result_{$state}"))
                    ->color(fn (string $state): string => $state === ModuleAuditLog::RESULT_ALLOWED ? 'success' : 'danger'),
                TextColumn::make('actor.name')
                    ->label(__('lba.audit.column_actor'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('reason')
                    ->label(__('lba.audit.column_reason'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('scope')
                    ->label(__('lba.audit.column_scope'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('scope_key')
                    ->label(__('lba.audit.column_scope_key'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('result')
                    ->label(__('lba.audit.filter_result'))
                    ->options([
                        ModuleAuditLog::RESULT_ALLOWED => __('lba.audit.result_allowed'),
                        ModuleAuditLog::RESULT_DENIED => __('lba.audit.result_denied'),
                    ]),
                SelectFilter::make('action')
                    ->label(__('lba.audit.filter_action'))
                    ->options(fn (): array => self::distinctValues('action')),
                SelectFilter::make('module_key')
                    ->label(__('lba.audit.filter_module'))
                    ->options(fn (): array => self::distinctValues('module_key')),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading(__('lba.audit.empty_heading'))
            ->emptyStateDescription(__('lba.audit.empty_description'));
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_AUDIT_VIEW);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
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
     * Distinct, non-null values of a filterable column, as select options.
     *
     * @return array<string, string>
     */
    private static function distinctValues(string $column): array
    {
        return ModuleAuditLog::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all();
    }

    /**
     * @return Builder<ModuleAuditLog>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('actor');
    }
}
