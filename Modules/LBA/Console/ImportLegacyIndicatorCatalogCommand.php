<?php

namespace Modules\LBA\Console;

use Illuminate\Console\Command;
use Modules\LBA\Assessment\LegacyIndicatorCatalog;
use Modules\LBA\Services\AssessmentCatalog;

/**
 * Phase 7A — provision the CENTRAL legacy indicator reference catalog.
 *
 * Imports the 30 preserved legacy source labels (in their original order) as a
 * versioned catalog. Idempotent: re-running never rewrites an existing
 * definition, so a classification or a reviewed observation label already
 * recorded is never silently changed. All entries are flagged reference-only /
 * unvalidated until authorized clinical review.
 */
class ImportLegacyIndicatorCatalogCommand extends Command
{
    protected $signature = 'lba:assessment:import-catalog';

    protected $description = 'Provision the central legacy LBA indicator reference catalog (idempotent)';

    public function handle(AssessmentCatalog $catalog): int
    {
        $version = $catalog->ensureLegacyCatalog();

        $this->info("Catalog version [{$version->key}] ready (status: {$version->status}).");
        $this->line('  definitions: '.$version->definitions()->count().' / '.count(LegacyIndicatorCatalog::definitions()));
        $this->line('  source:      '.($version->source ?? '—'));
        $this->line('  note:        legacy labels are reference-only and unvalidated.');

        return self::SUCCESS;
    }
}
