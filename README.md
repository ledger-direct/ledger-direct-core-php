# ledger-direct-core

Platform-agnostic PHP core for LedgerDirect: the XRPL/commerce logic (price conversion, oracle set,
stablecoin registry, transaction sync) shared across every LedgerDirect plugin.

Composer package: `hardcastle/ledger-direct-core`. No framework dependency, no concrete Guzzle, no
`xrpl_php`. Depends only on the PSR interfaces (`psr/http-client`, `psr/http-factory`, `psr/log`)
plus `brick/math` for exact decimal arithmetic — pure PHP, no required extensions.

The contract this package guarantees — metadata field shapes, conversion/rounding rules, the
stablecoin registry, the oracle set, table naming — is defined in [`INVARIANTS.md`](INVARIANTS.md)
and is not to be changed without a semver-major bump.

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

## Distribution

At dev time this is a versioned Composer package. At release time it is bundled into each
platform plugin's `vendor/` — end customers get the bundled module, not a standalone
`composer require`. See `CLAUDE.md` (private handover, not part of this repo) for the full
per-platform packaging plan.
