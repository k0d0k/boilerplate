<?php

namespace App\Platform\Branches;

/**
 * Phase 2B — a branch a staff identity may switch into.
 *
 * A *presentation* projection of one eligible branch, not an authorization
 * decision: `id` is the verified branch id inside the current tenant database.
 * Branch and tenant are never collapsed — the tenant is implied by which
 * database the branch lives in.
 */
final class BranchOption
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $code = null,
    ) {}

    /**
     * @return array{id: string, name: string, code: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
        ];
    }
}
