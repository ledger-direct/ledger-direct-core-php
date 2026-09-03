# ledger-direct-core

Platform-agnostic PHP core for LedgerDirect: the XRPL/commerce logic (price conversion, oracle set,
stablecoin registry, transaction sync) shared across every LedgerDirect plugin.

Composer package: `hardcastle/ledger-direct-core`. No framework dependency, no concrete Guzzle, no
`xrpl_php`. Depends only on the PSR interfaces (`psr/http-client`, `psr/http-factory`, `psr/log`,
`psr/simple-cache`) plus `brick/math` for exact decimal arithmetic — pure PHP, no required
extensions.

The contract this package guarantees — metadata field shapes, conversion/rounding rules, the
settlement decision, the stablecoin registry, the oracle set, table naming — is defined in
[`INVARIANTS.md`](INVARIANTS.md) and is not to be changed without a semver-major bump.

## Naming across languages

| Language | Repo | Package |
|---|---|---|
| PHP | `ledger-direct-core-php` | `hardcastle/ledger-direct-core` (Composer) |
| JS (later) | `ledger-direct-core-js` | `@ledger-direct/core` (npm scope) |
| Ruby (later) | `ledger-direct-core-rb` | `ledger-direct-core` (gem) |

Namespace: `Hardcastle\LedgerDirect\Core\…`.

## Local setup

The git repo root is the project root — `docker-compose.yml`, `src/`, `tests/` all live directly in
the repo, nothing wraps it. `src/` is the PSR-4 source directory.

```
docker compose build
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
XDEBUG_MODE=coverage docker compose run --rm php vendor/bin/phpunit --coverage-text
```

Requires PHP `^8.2`; CI runs the matrix `8.2` / `8.3` / `8.4`.

## Running tests

`vendor/bin/phpunit` (no flags — this is what CI runs) executes only the **unit** suite: no network
access, all HTTP is against a fake PSR-18 client. There's also a separate, opt-in **integration**
suite that hits the real Binance/Coingecko/Kraken APIs to check the oracle parsing still matches
what those services actually return — deliberately excluded from the default run and from CI, since
it depends on network access and third-party rate limits, not something to gate merges on.

```
docker compose run --rm php vendor/bin/phpunit                        # unit suite (default)
docker compose run --rm php vendor/bin/phpunit --testsuite integration # real oracle APIs, needs network
```

Run the integration suite deliberately (e.g. after touching an `Oracle` class) — it's not
CI-gating, and it can be flaky against live, rate-limited third-party APIs.

## Distribution

At dev time this is a versioned Composer package. At release time it is bundled into each
platform plugin's `vendor/` — end customers get the bundled module, not a standalone
`composer require`. See `CLAUDE.md` (private handover, not part of this repo) for the full
per-platform packaging plan.
