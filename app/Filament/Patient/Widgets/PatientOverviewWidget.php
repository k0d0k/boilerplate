<?php

namespace App\Filament\Patient\Widgets;

use App\Filament\Shared\Widgets\LbaStatsWidget;
use Filament\Support\Icons\Heroicon;

/**
 * Patient quick-summary strip (Phase 1A-3).
 *
 * A deliberately small, consumer-friendly summary that sits under the greeting.
 * All values are synthetic; the patient dashboard never queries a real
 * appointment, report or wallet record in this phase.
 */
class PatientOverviewWidget extends LbaStatsWidget
{
    protected function getStats(): array
    {
        return [
            $this->kpi(
                __('lba.kpi.patient.upcoming_label'),
                '1',
                __('lba.kpi.patient.upcoming_desc'),
                null,
                'primary',
                Heroicon::OutlinedCalendarDays,
            ),
            $this->kpi(
                __('lba.kpi.patient.results_label'),
                '2',
                __('lba.kpi.patient.results_desc'),
                null,
                'primary',
                Heroicon::OutlinedDocumentText,
            ),
            $this->kpi(
                __('lba.kpi.patient.tokens_label'),
                '184.500',
                __('lba.kpi.patient.tokens_desc'),
                null,
                'primary',
                Heroicon::OutlinedWallet,
            ),
        ];
    }
}
