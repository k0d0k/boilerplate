<?php

namespace App\Filament\Shared\Data;

/**
 * Phase 1A-3 synthetic dashboard data provider (localized in Phase 1B-3).
 *
 * Every value here is FABRICATED demonstration content for the premium dashboard
 * preview. Nothing in this class touches the database, models, tenant context or
 * any clinical record. Principal data is strictly NON-CLINICAL aggregates only.
 *
 * Since Phase 1B-3 every human-readable string is resolved from the `lba.demo.*`
 * translation keys in `lang/{id,ms-MY,en}/lba.php`, so the same synthetic copy
 * renders in the active locale without changing a single layout, colour or
 * spacing token. Numeric values, dates, proper nouns (clinic/brand names) and
 * examination identifiers stay literal: they are data, not prose.
 *
 * In later phases each panel widget is expected to be backed by the real module
 * service (e.g. a TenantAnalyticsService) returning the same array shapes, so the
 * presentation layer can stay unchanged. Keep this class free of business logic.
 *
 * Shapes returned by {@see self::get()}:
 *  - hero:      {eyebrow, title, subtitle, chips[], note}
 *  - list:      {heading, description, items: [{title, subtitle, meta, badge:{label, tone}}]}
 *  - breakdown: {heading, description, items: [{label, value, max, tone, caption}]}
 *  - metric:    {heading, description, primary:{label, value, unit}, secondary:{label, value, unit},
 *                progress, progressLabel, note}
 *  - cards:     {heading, description, items: [{title, subtitle, badge, icon}]}
 */
final class LbaDemoData
{
    /**
     * The localized synthetic-data disclaimer rendered on every hero band.
     */
    public static function syntheticNotice(): string
    {
        return __('lba.demo.synthetic_notice');
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $key): array
    {
        return match ($key) {
            // ---------------------------------------------------------- Principal
            'principal.hero' => [
                'eyebrow' => __('lba.demo.principal.hero_eyebrow'),
                'title' => __('lba.demo.principal.hero_title'),
                'subtitle' => __('lba.demo.principal.hero_subtitle'),
                'chips' => [
                    ['label' => __('lba.demo.principal.hero_chip_period'), 'tone' => 'navy'],
                    ['label' => __('lba.demo.principal.hero_chip_scope'), 'tone' => 'slate'],
                ],
                'note' => self::syntheticNotice(),
            ],
            'principal.activity' => [
                'heading' => __('lba.demo.principal.activity_heading'),
                'description' => __('lba.demo.principal.activity_description'),
                'items' => [
                    ['title' => __('lba.demo.principal.activity_item_1_title'), 'subtitle' => __('lba.demo.principal.activity_item_1_subtitle'), 'meta' => __('lba.demo.principal.activity_item_1_meta'), 'badge' => ['label' => __('lba.demo.principal.activity_item_1_badge'), 'tone' => 'teal']],
                    ['title' => __('lba.demo.principal.activity_item_2_title'), 'subtitle' => __('lba.demo.principal.activity_item_2_subtitle'), 'meta' => __('lba.demo.principal.activity_item_2_meta'), 'badge' => ['label' => __('lba.demo.principal.activity_item_2_badge'), 'tone' => 'navy']],
                    ['title' => __('lba.demo.principal.activity_item_3_title'), 'subtitle' => __('lba.demo.principal.activity_item_3_subtitle'), 'meta' => __('lba.demo.principal.activity_item_3_meta'), 'badge' => ['label' => __('lba.demo.principal.activity_item_3_badge'), 'tone' => 'teal']],
                    ['title' => __('lba.demo.principal.activity_item_4_title'), 'subtitle' => __('lba.demo.principal.activity_item_4_subtitle'), 'meta' => __('lba.demo.principal.activity_item_4_meta'), 'badge' => ['label' => __('lba.demo.principal.activity_item_4_badge'), 'tone' => 'amber']],
                    ['title' => __('lba.demo.principal.activity_item_5_title'), 'subtitle' => __('lba.demo.principal.activity_item_5_subtitle'), 'meta' => __('lba.demo.principal.activity_item_5_meta'), 'badge' => ['label' => __('lba.demo.principal.activity_item_5_badge'), 'tone' => 'slate']],
                ],
            ],
            'principal.health' => [
                'heading' => __('lba.demo.principal.health_heading'),
                'description' => __('lba.demo.principal.health_description'),
                'items' => [
                    ['title' => __('lba.demo.principal.health_item_1_title'), 'subtitle' => __('lba.demo.principal.health_item_1_subtitle'), 'meta' => __('lba.demo.principal.health_item_1_meta'), 'badge' => ['label' => __('lba.demo.principal.health_item_1_badge'), 'tone' => 'teal']],
                    ['title' => __('lba.demo.principal.health_item_2_title'), 'subtitle' => __('lba.demo.principal.health_item_2_subtitle'), 'meta' => __('lba.demo.principal.health_item_2_meta'), 'badge' => ['label' => __('lba.demo.principal.health_item_2_badge'), 'tone' => 'teal']],
                    ['title' => __('lba.demo.principal.health_item_3_title'), 'subtitle' => __('lba.demo.principal.health_item_3_subtitle'), 'meta' => __('lba.demo.principal.health_item_3_meta'), 'badge' => ['label' => __('lba.demo.principal.health_item_3_badge'), 'tone' => 'amber']],
                    ['title' => __('lba.demo.principal.health_item_4_title'), 'subtitle' => __('lba.demo.principal.health_item_4_subtitle'), 'meta' => __('lba.demo.principal.health_item_4_meta'), 'badge' => ['label' => __('lba.demo.principal.health_item_4_badge'), 'tone' => 'amber']],
                    ['title' => __('lba.demo.principal.health_item_5_title'), 'subtitle' => __('lba.demo.principal.health_item_5_subtitle'), 'meta' => __('lba.demo.principal.health_item_5_meta'), 'badge' => ['label' => __('lba.demo.principal.health_item_5_badge'), 'tone' => 'slate']],
                ],
            ],

            // ------------------------------------------------------------- Clinic
            'clinic.hero' => [
                'eyebrow' => __('lba.demo.clinic.hero_eyebrow'),
                'title' => __('lba.demo.clinic.hero_title'),
                'subtitle' => __('lba.demo.clinic.hero_subtitle'),
                'chips' => [
                    ['label' => __('lba.demo.clinic.hero_chip_clinic'), 'tone' => 'navy'],
                    ['label' => __('lba.demo.clinic.hero_chip_today'), 'tone' => 'slate'],
                ],
                'note' => self::syntheticNotice(),
            ],
            'clinic.examinations' => [
                'heading' => __('lba.demo.clinic.examinations_heading'),
                'description' => __('lba.demo.clinic.examinations_description'),
                'items' => [
                    ['title' => 'LBA-2026-00841', 'subtitle' => __('lba.demo.clinic.examinations_item_1_subtitle'), 'meta' => '09:42', 'badge' => ['label' => __('lba.demo.clinic.examinations_item_1_badge'), 'tone' => 'amber']],
                    ['title' => 'LBA-2026-00840', 'subtitle' => __('lba.demo.clinic.examinations_item_2_subtitle'), 'meta' => '09:15', 'badge' => ['label' => __('lba.demo.clinic.examinations_item_2_badge'), 'tone' => 'teal']],
                    ['title' => 'LBA-2026-00839', 'subtitle' => __('lba.demo.clinic.examinations_item_3_subtitle'), 'meta' => '08:58', 'badge' => ['label' => __('lba.demo.clinic.examinations_item_3_badge'), 'tone' => 'teal']],
                    ['title' => 'LBA-2026-00838', 'subtitle' => __('lba.demo.clinic.examinations_item_4_subtitle'), 'meta' => '08:31', 'badge' => ['label' => __('lba.demo.clinic.examinations_item_4_badge'), 'tone' => 'navy']],
                    ['title' => 'LBA-2026-00837', 'subtitle' => __('lba.demo.clinic.examinations_item_5_subtitle'), 'meta' => '08:04', 'badge' => ['label' => __('lba.demo.clinic.examinations_item_5_badge'), 'tone' => 'teal']],
                ],
            ],
            'clinic.schedule' => [
                'heading' => __('lba.demo.clinic.schedule_heading'),
                'description' => __('lba.demo.clinic.schedule_description'),
                'items' => [
                    ['title' => __('lba.demo.clinic.schedule_item_1_title'), 'subtitle' => __('lba.demo.clinic.schedule_item_1_subtitle'), 'meta' => __('lba.demo.clinic.schedule_item_1_meta'), 'badge' => ['label' => __('lba.demo.clinic.schedule_item_1_badge'), 'tone' => 'teal']],
                    ['title' => __('lba.demo.clinic.schedule_item_2_title'), 'subtitle' => __('lba.demo.clinic.schedule_item_2_subtitle'), 'meta' => __('lba.demo.clinic.schedule_item_2_meta'), 'badge' => ['label' => __('lba.demo.clinic.schedule_item_2_badge'), 'tone' => 'navy']],
                    ['title' => __('lba.demo.clinic.schedule_item_3_title'), 'subtitle' => __('lba.demo.clinic.schedule_item_3_subtitle'), 'meta' => __('lba.demo.clinic.schedule_item_3_meta'), 'badge' => ['label' => __('lba.demo.clinic.schedule_item_3_badge'), 'tone' => 'slate']],
                    ['title' => __('lba.demo.clinic.schedule_item_4_title'), 'subtitle' => __('lba.demo.clinic.schedule_item_4_subtitle'), 'meta' => __('lba.demo.clinic.schedule_item_4_meta'), 'badge' => ['label' => __('lba.demo.clinic.schedule_item_4_badge'), 'tone' => 'slate']],
                ],
            ],
            'clinic.status' => [
                'heading' => __('lba.demo.clinic.status_heading'),
                'description' => __('lba.demo.clinic.status_description'),
                'items' => [
                    ['label' => __('lba.demo.clinic.status_item_1_label'), 'value' => 128, 'max' => 180, 'tone' => 'teal', 'caption' => '71%'],
                    ['label' => __('lba.demo.clinic.status_item_2_label'), 'value' => 26, 'max' => 180, 'tone' => 'amber', 'caption' => '14%'],
                    ['label' => __('lba.demo.clinic.status_item_3_label'), 'value' => 18, 'max' => 180, 'tone' => 'navy', 'caption' => '10%'],
                    ['label' => __('lba.demo.clinic.status_item_4_label'), 'value' => 8, 'max' => 180, 'tone' => 'rose', 'caption' => '5%'],
                ],
            ],
            'clinic.workflow' => [
                'heading' => __('lba.demo.clinic.workflow_heading'),
                'description' => __('lba.demo.clinic.workflow_description'),
                'items' => [
                    ['label' => __('lba.examination.mode_ai'), 'value' => 74, 'max' => 180, 'tone' => 'teal', 'caption' => '41%'],
                    ['label' => __('lba.examination.mode_hybrid'), 'value' => 62, 'max' => 180, 'tone' => 'navy', 'caption' => '34%'],
                    ['label' => __('lba.examination.mode_manual'), 'value' => 44, 'max' => 180, 'tone' => 'slate', 'caption' => '25%'],
                ],
            ],
            'clinic.queue' => [
                'heading' => __('lba.demo.clinic.queue_heading'),
                'description' => __('lba.demo.clinic.queue_description'),
                'items' => [
                    ['title' => 'LBA-2026-00841', 'subtitle' => __('lba.demo.clinic.queue_item_1_subtitle'), 'meta' => '09:42', 'badge' => ['label' => __('lba.demo.clinic.queue_item_1_badge'), 'tone' => 'rose']],
                    ['title' => 'LBA-2026-00833', 'subtitle' => __('lba.demo.clinic.queue_item_2_subtitle'), 'meta' => '08:12', 'badge' => ['label' => __('lba.demo.clinic.queue_item_2_badge'), 'tone' => 'amber']],
                    ['title' => 'LBA-2026-00829', 'subtitle' => __('lba.demo.clinic.queue_item_3_subtitle'), 'meta' => __('lba.demo.clinic.queue_item_3_meta'), 'badge' => ['label' => __('lba.demo.clinic.queue_item_3_badge'), 'tone' => 'amber']],
                    ['title' => 'LBA-2026-00822', 'subtitle' => __('lba.demo.clinic.queue_item_4_subtitle'), 'meta' => __('lba.demo.clinic.queue_item_4_meta'), 'badge' => ['label' => __('lba.demo.clinic.queue_item_4_badge'), 'tone' => 'slate']],
                ],
            ],
            'clinic.token' => [
                'heading' => __('lba.demo.clinic.token_heading'),
                'description' => __('lba.demo.clinic.token_description'),
                'primary' => ['label' => __('lba.demo.clinic.token_primary_label'), 'value' => '184.500', 'unit' => 'token'],
                'secondary' => ['label' => __('lba.demo.clinic.token_secondary_label'), 'value' => '68.400', 'unit' => 'token'],
                'progress' => 37,
                'progressLabel' => __('lba.demo.clinic.token_progress_label'),
                'note' => __('lba.demo.clinic.token_note'),
            ],

            // ------------------------------------------------------------ Patient
            'patient.hero' => [
                'eyebrow' => __('lba.demo.patient.hero_eyebrow'),
                'title' => __('lba.demo.patient.hero_title'),
                'subtitle' => __('lba.demo.patient.hero_subtitle'),
                'chips' => [
                    ['label' => __('lba.demo.patient.hero_chip_account'), 'tone' => 'navy'],
                    ['label' => __('lba.demo.patient.hero_chip_portal'), 'tone' => 'slate'],
                ],
                'note' => self::syntheticNotice(),
            ],
            'patient.appointment' => [
                'heading' => __('lba.demo.patient.appointment_heading'),
                'description' => __('lba.demo.patient.appointment_description'),
                'primary' => ['label' => __('lba.demo.patient.appointment_primary_label'), 'value' => __('lba.demo.patient.appointment_primary_value'), 'unit' => '09:30'],
                'secondary' => ['label' => __('lba.demo.patient.appointment_secondary_label'), 'value' => 'NuBiom Diagnostics', 'unit' => __('lba.demo.patient.appointment_secondary_unit')],
                'progress' => 100,
                'progressLabel' => __('lba.demo.patient.appointment_progress_label'),
                'note' => __('lba.demo.patient.appointment_note'),
            ],
            'patient.reports' => [
                'heading' => __('lba.demo.patient.reports_heading'),
                'description' => __('lba.demo.patient.reports_description'),
                'items' => [
                    ['title' => __('lba.demo.patient.reports_item_1_title'), 'subtitle' => __('lba.demo.patient.reports_item_1_subtitle'), 'meta' => __('lba.demo.patient.reports_item_1_meta'), 'badge' => ['label' => __('lba.demo.patient.reports_item_1_badge'), 'tone' => 'teal']],
                    ['title' => __('lba.demo.patient.reports_item_2_title'), 'subtitle' => __('lba.demo.patient.reports_item_2_subtitle'), 'meta' => __('lba.demo.patient.reports_item_2_meta'), 'badge' => ['label' => __('lba.demo.patient.reports_item_2_badge'), 'tone' => 'teal']],
                    ['title' => __('lba.demo.patient.reports_item_3_title'), 'subtitle' => __('lba.demo.patient.reports_item_3_subtitle'), 'meta' => __('lba.demo.patient.reports_item_3_meta'), 'badge' => ['label' => __('lba.demo.patient.reports_item_3_badge'), 'tone' => 'amber']],
                ],
            ],
            'patient.consultation' => [
                'heading' => __('lba.demo.patient.consultation_heading'),
                'description' => __('lba.demo.patient.consultation_description'),
                'items' => [
                    ['title' => __('lba.demo.patient.consultation_item_1_title'), 'subtitle' => __('lba.demo.patient.consultation_item_1_subtitle'), 'badge' => __('lba.demo.patient.consultation_item_1_badge'), 'icon' => 'chat'],
                    ['title' => __('lba.demo.patient.consultation_item_2_title'), 'subtitle' => __('lba.demo.patient.consultation_item_2_subtitle'), 'badge' => __('lba.demo.patient.consultation_item_2_badge'), 'icon' => 'sparkles'],
                    ['title' => __('lba.demo.patient.consultation_item_3_title'), 'subtitle' => __('lba.demo.patient.consultation_item_3_subtitle'), 'badge' => __('lba.demo.patient.consultation_item_3_badge'), 'icon' => 'clock'],
                ],
            ],
            'patient.products' => [
                'heading' => __('lba.demo.patient.products_heading'),
                'description' => __('lba.demo.patient.products_description'),
                'items' => [
                    ['title' => __('lba.demo.patient.products_item_1_title'), 'subtitle' => __('lba.demo.patient.products_item_1_subtitle'), 'badge' => __('lba.demo.patient.products_item_1_badge'), 'icon' => 'pack'],
                    ['title' => __('lba.demo.patient.products_item_2_title'), 'subtitle' => __('lba.demo.patient.products_item_2_subtitle'), 'badge' => __('lba.demo.patient.products_item_2_badge'), 'icon' => 'chat'],
                    ['title' => __('lba.demo.patient.products_item_3_title'), 'subtitle' => __('lba.demo.patient.products_item_3_subtitle'), 'badge' => __('lba.demo.patient.products_item_3_badge'), 'icon' => 'wallet'],
                ],
            ],

            default => [],
        };
    }

    /**
     * Synthetic KPI sparkline series (small int arrays) keyed by metric name.
     *
     * @return array<int, int>
     */
    public static function sparkline(string $key): array
    {
        return match ($key) {
            'partners' => [4, 5, 5, 6, 7, 8, 9, 12],
            'branches' => [8, 9, 10, 12, 14, 16, 18, 21],
            'subscriptions' => [6, 7, 9, 11, 13, 15, 18, 22],
            'ai_tokens' => [42, 48, 55, 61, 70, 78, 86, 94],
            'clinic_patients' => [18, 22, 26, 24, 30, 32, 35, 38],
            'clinic_exams_today' => [6, 9, 12, 10, 14, 16, 15, 18],
            'clinic_appointments' => [8, 11, 13, 12, 16, 18, 17, 21],
            'clinic_reviews' => [3, 4, 6, 5, 7, 6, 8, 7],
            default => [1, 2, 3, 4, 5, 6, 7, 8],
        };
    }

    /**
     * Synthetic monthly series for the Principal analytics charts.
     *
     * Month labels are localized (`lba.demo.months`); the series values are not
     * prose and stay literal.
     *
     * @return array{labels: array<int, string>, revenue: array<int, int>, credits: array<int, int>}
     */
    public static function principalTrend(): array
    {
        /** @var array<int, string> $labels */
        $labels = __('lba.demo.months');

        return [
            'labels' => $labels,
            'revenue' => [186, 204, 231, 268, 302, 341],
            'credits' => [52, 61, 74, 88, 103, 121],
        ];
    }
}
