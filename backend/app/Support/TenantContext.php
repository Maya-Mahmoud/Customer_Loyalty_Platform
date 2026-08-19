<?php

namespace App\Support;

/**
 * Holds the merchant the current request belongs to.
 *
 * BRD FR-ADM-06 requires that no merchant can ever reach another merchant's
 * data "by any means". Relying on every query to remember a where clause is how
 * that requirement gets broken, so the merchant is resolved once per request and
 * every tenant-owned model reads it through a global scope.
 *
 * A null merchant means "no tenant" — the platform supervisor, the console, and
 * the seeders. Those paths are unscoped by design.
 */
class TenantContext
{
    private ?int $merchantId = null;

    public function set(?int $merchantId): void
    {
        $this->merchantId = $merchantId;
    }

    public function id(): ?int
    {
        return $this->merchantId;
    }

    public function isActive(): bool
    {
        return $this->merchantId !== null;
    }

    public function forget(): void
    {
        $this->merchantId = null;
    }

    /**
     * Run a callback scoped to a specific merchant, restoring the previous
     * tenant afterwards. Used when the platform supervisor acts on one merchant
     * for support, and by queued work that has no request behind it.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function for(?int $merchantId, callable $callback): mixed
    {
        $previous = $this->merchantId;
        $this->merchantId = $merchantId;

        try {
            return $callback();
        } finally {
            $this->merchantId = $previous;
        }
    }

    /**
     * Escape hatch for genuinely cross-merchant work, such as platform reports.
     * Deliberately explicit so it stands out in review.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withoutScope(callable $callback): mixed
    {
        return $this->for(null, $callback);
    }
}
