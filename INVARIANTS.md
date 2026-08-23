# Invariants

This document is the authoritative contract for `hardcastle/ledger-direct-core`. It is not derived
from the code — the code is derived from this. Every platform adapter (PrestaShop, Magento,
WooCommerce, …) and the later Pro/retrofit branch is measured against it. Ground truth is the
Shopware repo `ledger-direct-shopware6`; this document ports its **fixed** logic (generic Kraken
parsing, no `RippleOracle`, catches logged via PSR-3, `hash`-unique index, `ledger_direct_*` table
names).

Breaking this contract is a semver-major change (see `CLAUDE.md`, section 2).

## PaymentIntent (schema v1) — the versioned payment record

The core models payment metadata as a domain object, `Core\Payment\PaymentIntent` — named
chain-agnostically because further chains are expected to join later. Its serialized shape **is**
the cross-plugin contract.

Required field **`schema_version`** (integer, currently `1`) is **embedded in every serialized
record as the first field**, so the record describes itself and is readable without external
context. `PaymentIntent::fromArray()` reads `schema_version` first and selects the parser accordingly;
`toArray()` writes it. **Not to be confused** with the DB migration / plugin version — that
describes the database structure or the release and lives **outside** the record.

The storage key a platform wraps the record in (e.g. an order-metadata key) is that platform's
concern, not the core's — the domain object is not the same thing as the storage key.

## Metadata fields

Field names are literal and identical across all plugins:

| Field | Shape |
|---|---|
| `schema_version` | Integer, currently `1`. Always the first field in a serialized record. |
| `type` | Payment-method discriminator, e.g. `xrp-payment`, `rlusd-payment`, `usdc-payment`. Distinct from `base_asset` — see below. |
| `chain` | e.g. `XRPL` — reserved so a later non-XRPL chain doesn't require a new record type. |
| `network` | `mainnet` \| `testnet`. |
| `base_asset` | Asset identifier, e.g. `XRP`, `RLUSD`, `USDC`. Drives the `amount_requested` shape below. |
| `quote_currency` | |
| `pairing` | |
| `exchange_rate` | |
| `amount_requested` | `base_asset === XRP` → float. `base_asset === RLUSD \| USDC` → full XRPL `IssuedCurrencyAmount` object `{currency, value, issuer}`. Same field name, different shape depending on `base_asset` — a known **v1 wart**; a later v2 can unify it without breaking v1 readers, which is exactly what the versioning is for. |
| `destination_account` | |
| `destination_tag` | |
| `expiry` | |
| `hash` | See [Tables](#tables) — unique per transaction. |
| `ctid` | XRPL Compact Transaction ID — unlike `hash`, self-describing (encodes ledger index, transaction index, network id), so it disambiguates across networks. Set alongside `hash` once the payment is fulfilled; used to verify the transaction actually went through. **XRPL-specific, not a general blockchain concept** — optional/nullable so a future non-`XRPL` `chain` can fulfill without it. |
| `amount_paid` / `delivered_amount` | |

## Conversion & rounding

- `amount = total / exchange_rate` for every asset.
- **USD-peg fast path:** `exchange_rate = 1.0` when `quote === 'USD'` and the asset is RLUSD or
  USDC (no oracle call). **XRP never** takes the fast path — it always goes through the oracle set.
- Rounding: stablecoins → 2 decimal places, XRP → 5 decimal places.

## StablecoinRegistry — security-critical

> A wrong issuer address sends customer funds to a dead trustline → real, unrecoverable loss.
> **Do not hand-transcribe these values from memory.** They were pulled from the Shopware
> ground-truth `src/Provider/StablecoinProvider.php` by reading the source file directly, once, at
> authoring time, and are then treated as fixed in `Xrpl\StablecoinRegistry`. This repo has no
> dependency on, or reference to, the Shopware repo — not even a test — so there is no standing
> equality check to keep passing; verification happens once, deliberately, whenever these values
> are touched.

```
RLUSD  mainnet  issuer rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De  currency 524C555344000000000000000000000000000000
RLUSD  testnet  issuer rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV  currency 524C555344000000000000000000000000000000
USDC   mainnet  issuer rGm7WCVp9gb4jZHWTEtGUr4dd74z2XuWhE  currency 5553444300000000000000000000000000000000
USDC   testnet  issuer rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt  currency 5553444300000000000000000000000000000000
```

**Quirk that is copied, not "corrected":** `USDC_CODE = 'USD'` (not `'USDC'`), while the hex
currency code is still the 40-character USDC representation.

## Oracle set

- XRP → Binance + Coingecko + Kraken, divergence filter `0.05`.
- RLUSD / USDC → Coingecko only.
- Kraken is read **generically** (read the single `result` entry, never key by `XXRPZUSD`).
- All catches are logged via PSR-3.

## Tables

- `ledger_direct_xrpl_tx`, `ledger_direct_xrpl_destination_tag` — naming convention
  `ledger_direct_{chain}_{entity}`.
- Unique index on `hash`.
- `destination_tag` values are **unsigned 32-bit integers** — XRPL's DestinationTag field's true
  range is `0`–`4294967295`. Ground truth's MySQL schema uses a *signed* `INT`, capping usable
  values at `2147483647`; the core's `DestinationTagService` generates across the full unsigned
  range. Not an XRPL protocol constraint — a schema choice the core corrects.
- `ledger_direct_xrpl_destination_tag` holds **one row per destination account**, not one row per
  issued tag: `destination_account` (primary key) + a `sequence` counter (**unsigned 32-bit**,
  atomically incremented — see `XrplTransactionRepositoryInterface::nextDestinationTagSequence()`).
  `DestinationTagService` derives the actual tag from that counter via a fixed permutation rather
  than storing every issued tag, so this table stays a small, constant-size-per-account row instead
  of growing by one row per order.
- The core defines the **schema** (SQL/DDL as a constant or migration template); the platform
  creates it through its own DB layer.

## Security

- The issuer is **never** merchant-configurable.
- SSL is always verified on outbound HTTP calls.
