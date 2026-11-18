<?php

namespace App\Localization\Contracts;

use App\Models\User;

/**
 * Phase 1B-3 locale resolution contract.
 *
 * Resolves the single active display locale for a request from the approved
 * priority order (docs/LOCALIZATION.md):
 *
 *  1. Authenticated user's saved language preference
 *  2. Active permitted brand default language
 *  3. Verified tenant default language
 *  4. Platform default (`id`)
 *
 * Language is a presentation concern only: it never changes authorization,
 * membership, panel access, currency, timezone, payment merchant, legal
 * jurisdiction, tenant identity or the clinical database connection.
 */
interface ResolvesLocale
{
    /**
     * Canonical supported locale codes (id, ms-MY, en).
     *
     * @return list<string>
     */
    public function supported(): array;

    /**
     * Whether the given value is one of the canonical supported locales.
     */
    public function isSupported(?string $locale): bool;

    /**
     * The platform default locale (last step of resolution).
     */
    public function default(): string;

    /**
     * Resolve the locale for the given identity (null = guest).
     */
    public function resolve(?User $user): string;
}
