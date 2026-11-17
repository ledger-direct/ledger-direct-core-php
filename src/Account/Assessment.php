<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Account;

/**
 * What the configuration screen shows about a receiving account: every finding, for the
 * account and per asset. Empty means nothing stands in a customer's way.
 */
final readonly class Assessment
{
    /**
     * @param list<Finding> $findings
     */
    public function __construct(
        public string $account,
        public string $network,
        public array $findings,
    ) {
    }

    public function isClean(): bool
    {
        return $this->findings === [];
    }

    /**
     * The findings about one asset, plus those about the account as a whole.
     *
     * @return list<Finding>
     */
    public function forAsset(string $asset): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (Finding $finding): bool => $finding->asset === null || $finding->asset === $asset,
        ));
    }

    public function has(string $code, ?string $asset = null): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->code === $code && ($asset === null || $finding->asset === $asset)) {
                return true;
            }
        }

        return false;
    }
}
