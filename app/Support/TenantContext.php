<?php

namespace App\Support;

/**
 * Holds the active complex id for the current request/console run.
 * Bound as a singleton so the multi-tenant query scope always has a
 * concrete object to read (null id simply means "no scoping").
 */
class TenantContext
{
    public function __construct(public ?int $complexId = null) {}

    public function set(?int $complexId): void
    {
        $this->complexId = $complexId;
    }

    public function get(): ?int
    {
        return $this->complexId;
    }
}
