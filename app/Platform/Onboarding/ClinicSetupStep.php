<?php

namespace App\Platform\Onboarding;

/**
 * Outcome of ONE step in the one-click clinic setup (Phase 3).
 *
 * The orchestration reports progress and errors granularly — one immutable
 * value per step — so a caller (a console command or a Filament action) can
 * render exactly how far a run got and why it stopped, and a retry can be
 * attempted without ambiguity.
 */
final class ClinicSetupStep
{
    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    private function __construct(
        public readonly string $key,
        public readonly string $status,
        public readonly string $detail,
    ) {}

    public static function ok(string $key, string $detail): self
    {
        return new self($key, self::STATUS_OK, $detail);
    }

    public static function failed(string $key, string $detail): self
    {
        return new self($key, self::STATUS_FAILED, $detail);
    }

    public static function skipped(string $key, string $detail): self
    {
        return new self($key, self::STATUS_SKIPPED, $detail);
    }

    public function isOk(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
