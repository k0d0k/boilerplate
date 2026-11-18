<?php

namespace App\Localization\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * Phase 1B-3 — automated missing-key detection.
 *
 * Compares every supported locale catalog against a baseline (default `id`) and
 * reports any key a locale is missing. Shared by the test suite and CI so an
 * untranslated string can never silently ship.
 *
 *   php artisan lba:localization:audit
 *   php artisan lba:localization:audit --baseline=en --strict
 */
class AuditTranslations extends Command
{
    protected $signature = 'lba:localization:audit
        {--baseline=id : Locale whose keys define the expected set}
        {--strict : Also report keys that exist only in a non-baseline locale}';

    protected $description = 'Verify the supported locale catalogs define the same translation keys.';

    public function handle(): int
    {
        $baseline = (string) $this->option('baseline');

        $locales = array_values(array_unique(array_merge(
            [$baseline],
            (array) config('localization.supported', []),
        )));

        $baselineKeys = $this->keysFor($baseline);
        $problems = [];

        foreach ($locales as $locale) {
            if ($locale === $baseline) {
                continue;
            }

            $keys = $this->keysFor($locale);

            foreach (array_diff($baselineKeys, $keys) as $key) {
                $problems[$locale]['missing'][] = $key;
            }

            if ($this->option('strict')) {
                foreach (array_diff($keys, $baselineKeys) as $key) {
                    $problems[$locale]['extra'][] = $key;
                }
            }
        }

        if ($problems === []) {
            $this->info('Localization audit passed: every catalog matches the ['.$baseline.'] baseline.');

            return self::SUCCESS;
        }

        foreach ($problems as $locale => $groups) {
            foreach ($groups as $kind => $keys) {
                $this->error(ucfirst($kind)." keys in [{$locale}]:");
                foreach ($keys as $key) {
                    $this->line("  - {$key}");
                }
            }
        }

        return self::FAILURE;
    }

    /**
     * Flatten every translation key under a locale directory, prefixed with its
     * group (file name).
     *
     * @return list<string>
     */
    private function keysFor(string $locale): array
    {
        $directory = lang_path($locale);

        if (! is_dir($directory)) {
            return [];
        }

        $keys = [];

        foreach (glob($directory.'/*.php') ?: [] as $file) {
            $group = basename($file, '.php');
            $data = require $file;

            if (! is_array($data)) {
                continue;
            }

            foreach (array_keys(Arr::dot($data)) as $key) {
                $keys[] = $group.'.'.$key;
            }
        }

        sort($keys);

        return $keys;
    }
}
