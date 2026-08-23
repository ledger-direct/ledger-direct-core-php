# CustomToken

Reserved namespace (`Hardcastle\LedgerDirect\Core\CustomToken`) for merchant-issued **fungible**
XRPL tokens — the general case `CLAUDE.md` section 7 originally named "Loyalty / Bonuspunkte", but
that's one use case among several: bonus points, a memecoin, mileage/rewards, or any other
merchant-controlled fungible asset are all the same underlying mechanism. LedgerDirect's original
starting point ("Wave-4"), before the project became primarily an XRPL payment gateway. See
`CLAUDE.md` section 7: platform-agnostic, belongs in the core, but **not built yet** — this
directory exists only to claim the namespace, not to pre-build anything speculative.

**Scope is deliberately fungible-only.** NFTs (e.g. XLS-20 NFTokens) are a structurally different
primitive — a unique token transfer, not a currency amount — and don't fit `PaymentIntent`'s
`amount_requested: float|array` shape at all. That's a `schema_version` v2 concern in its own
right, not a variant of this namespace; if/when NFT support becomes concrete it likely gets its own
separate reservation, decided then with real requirements, not folded in here speculatively now.

## What's actually decided so far (from the 2026-08-24 architecture audit)

- **Spending a custom token and issuing one are different problems, not one feature.** A customer
  *paying with* a merchant's token is signed by the customer — same trust model as any other
  payment, and once the asset gets accepted (see below), it can go through the existing
  `PaymentIntent`/`SyncService` machinery like any other asset. *Issuing* the token to a customer
  needs the merchant's own signing key — **not a core concern**. The core never touches private key
  material anywhere today (`StablecoinRegistry` only holds public issuer addresses; `XrplClient` is
  read-only JSON-RPC). If issuance ever gets a core-side piece, it should be a port that *requests*
  a signature from an isolated signer, never a class that holds a seed.
- **`PaymentIntent` no longer blocks this.** `base_asset` isn't a closed allowlist — any asset that
  isn't a chain's registered native asset is accepted as an `IssuedCurrencyAmount` by default (see
  `PaymentIntent::NATIVE_ASSET_BY_CHAIN`). A merchant-specific token needs no core change to be
  representable in a payment.
- **Pricing has no oracle counterpart.** A custom token doesn't trade on Binance/Coingecko/Kraken.
  Two options, both unbuilt: a merchant-configured fixed rate (`PriceProviderInterface` is already
  source-agnostic — `getCurrentExchangeRate()` never required an HTTP oracle, so a fixed-rate
  provider fits without any interface change), or products priced directly in the token, which
  bypasses `PriceService` entirely — `PaymentIntentService::quoteForOrder()` currently always calls
  `PriceService`, with no bypass.

Full reasoning trail: the repo's own (gitignored) `MUSINGS.md`.
