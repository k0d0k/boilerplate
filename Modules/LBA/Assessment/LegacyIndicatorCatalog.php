<?php

namespace Modules\LBA\Assessment;

/**
 * Phase 7A — the LEGACY reference catalog transcribed from the supplied paper
 * LBA form (Modules/LBA).
 *
 * These 30 entries are LEGACY SOURCE LABELS, preserved verbatim and in their
 * original order (left column 1–15, right column 16–30). They are NOT medically
 * validated findings: the source labels are exactly as printed, and no reviewed
 * `observation_label` is supplied yet — a mapping from a legacy label to an
 * evidence-based observation label requires authorized clinical review.
 *
 * The keys are STABLE internal identifiers (they are never the visible label),
 * so a later correction to a source label or a reviewed observation label never
 * changes an indicator's identity. Every imported definition is flagged
 * `reference_only` / unvalidated until a clinical reviewer approves it for an
 * appropriate use; the system must never let AI score these labels from
 * live-blood microscopy.
 */
final class LegacyIndicatorCatalog
{
    /** The stable key of the single legacy reference catalog version. */
    public const CATALOG_KEY = 'lba-legacy-form-v1';

    /** @return list<array{key: string, source_label: string, source_column: string, position: int}> */
    public static function definitions(): array
    {
        $left = [
            'asthma_paru_tbc' => 'ASTHMA, PARU PARU, TBC',
            'asam_urat_gout' => 'ASAM URAT (GOUT)',
            'ambeien_wasir' => 'AMBEIEN (WASIR)',
            'alergi_bateri_basofil' => 'ALERGI BATERI (BASOFIL)',
            'darah_rendah_tinggi' => 'DARAH RENDAH / DARAH TINGGI',
            'agregasi_degeneratif' => 'AGREGRASI (DEGENERATIF)',
            'diabetes_bibit_gula' => 'DIABETES (GULA) BIBIT GULA',
            'agregasi_trombosit' => 'AGREGRASI TROMBOSIT',
            'racun_darah_hemolisis' => 'RACUN DARAH (HEMOLISIS)',
            'infeksi_radang_sinusitis' => 'INFEKSI / RADANG / SINUSITIS',
            'jantung_pembengkakan' => 'JANTUNG / PEMBENGKAKAN',
            'kolesterol_ldl' => 'KOLESTROL (LDL)',
            'keputihan_kewanitaan_prostat' => 'KEPUTIHAN / KEWANITAAN / PROSTAT',
            'trigliserida' => 'TRIGLISERIDA',
            'leukimia_lupus_leukosit' => 'LEUKIMIA / LUPUS / LEUKOSIT',
        ];

        $right = [
            'regenerasi_sel' => 'REGENERASI SEL',
            'parasit_herpes_exim' => 'PARASIT / HERPES / EXIM',
            'pencernaan_limpa_lambung' => 'PENCERNAAN, LIMPA, LAMBUNG',
            'usus_liver' => 'USUS 12 JARI, USUS BESAR, LIVER',
            'stress_ginjal_dehidrasi' => 'STRESS GINJAL (DEHIDRASI)',
            'rematik_kram_kesemutan' => 'REMATIK (KRAM, KESEMUTAN)',
            'cancer_tumor_lipoma' => 'CANCER, TUMOR, LIPOMA',
            'radikal_bebas_poikilotosite' => 'RADIKAL BEBAS (POIKILOTOSITE)',
            'keracunan_darah_kotor' => 'KERACUNAN, DARAH KOTOR',
            'migrain_vertigo' => 'MIGRAIN / VERTIGO',
            'kurang_zat_besi_mikrosite' => 'KURANG ZAT BESI (MIKROSITE)',
            'plak_penyumbatan_stroke' => 'PLAK PENYUMBATAN (STROKE)',
            'pelengketan_darah_kental' => 'PELENGKETAN (DARAH) KENTAL',
            'red_crystal_tenggorokan' => 'RED CRYSTAL (TENGGOROKAN)',
            'getah_benih_kelenjar_yeast' => 'GETAH BENIH KELENJAR (YEAST)',
        ];

        $definitions = [];
        $position = 0;

        foreach ($left as $key => $label) {
            $position++;

            $definitions[] = [
                'key' => $key,
                'source_label' => $label,
                'source_column' => 'left',
                'position' => $position,
            ];
        }

        foreach ($right as $key => $label) {
            $position++;

            $definitions[] = [
                'key' => $key,
                'source_label' => $label,
                'source_column' => 'right',
                'position' => $position,
            ];
        }

        return $definitions;
    }
}
