# Invariants

This document is the authoritative contract for `hardcastle/ledger-direct-core`. It is not derived
from the code — the code is derived from this. Every platform adapter (PrestaShop, Magento,
WooCommerce, …) and the later Pro/retrofit branch is measured against it. Ground truth is the
Shopware repo `ledger-direct-shopware6`; this document ports its **fixed** logic (generic Kraken
parsing, no `RippleOracle`, catches logged via PSR-3, `hash`-unique index, `ledger_direct_*` table
names).

Breaking this contract is a semver-major change (see `CLAUDE.md`, section 2).

The services this contract describes are wired through one composition root,
`Core\LedgerDirect::create()`: every port in once, every service out, each built lazily and
memoised. Constructing the services by hand still works; the root is what a platform binds in
its container, and the only wiring the core promises to keep stable across releases. The core
holds no static state and ships no facade — that belongs to a platform bridge.

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
| `base_asset` | Asset identifier, e.g. `XRP`, `RLUSD`, `USDC` — or any other asset issued on `chain`, including ones the core has never heard of (e.g. a merchant-issued token). Drives the `amount_requested` shape below. |
| `quote_currency` | |
| `pairing` | |
| `exchange_rate` | |
| `amount_requested` | `base_asset` is `chain`'s native asset (e.g. `XRP` on `XRPL`) → float. Anything else → full `IssuedCurrencyAmount` object `{currency, value, issuer}` — **not a closed allowlist**: an asset the core doesn't recognize still gets the issued-currency shape, it isn't rejected. Same field name, different shape depending on whether `base_asset` is native — a known **v1 wart**; a later v2 can unify it without breaking v1 readers, which is exactly what the versioning is for. |
| `destination_account` | |
| `destination_tag` | |
| `expiry` | |
| `hash` | See [Tables](#tables) — unique per transaction. |
| `ctid` | XRPL Compact Transaction ID — unlike `hash`, self-describing (encodes ledger index, transaction index, network id), so it disambiguates across networks. Set alongside `hash` once the payment is fulfilled; used to verify the transaction actually went through. **XRPL-specific, not a general blockchain concept** — optional/nullable so a future non-`XRPL` `chain` can fulfill without it. |
| `amount_paid` / `delivered_amount` | A float or a full `IssuedCurrencyAmount` — **whichever the ledger delivered**, not bound to the shape of `amount_requested`: a token sent for an XRP order (or XRP for a token order) is recorded as it arrived, so the page can name it (since 0.8.1; before, such a payment was never recorded). **Decoded**, not the raw ledger encoding: see [Amount encoding](#amount-encoding-on-xrpl). |

## Conversion & rounding

- `amount = total / exchange_rate` for every asset.
- **USD-peg fast path:** `exchange_rate = 1.0` when `quote === 'USD'` and the asset is RLUSD or
  USDC (no oracle call). **XRP never** takes the fast path — it always goes through the oracle set.
- Rounding: stablecoins → 2 decimal places, XRP → 5 decimal places.

## Settlement

Whether a delivered amount pays for a quote is a core decision (`Core\Payment\SettlementPolicy`),
not a per-plugin one: every platform must call an order paid under exactly the same conditions.

**Which** transactions fulfill an intent is a core decision too, not just *whether* it settles.
A destination tag can carry several transactions — a stray payment in the wrong asset, a payment
that predates the order, two partial payments — so the repository port returns them all
(`findTransactions()`, newest first) and `SyncService::findFulfillmentFor()` decides, returning a
`Fulfillment` (the contributing transactions plus their summed amount) that `applyTo()` puts on
the intent:

- **All** candidates in the **quoted asset** fulfill the intent together, and `amount_paid` is
  their **sum** (exact, via `brick/math`; a float for a native asset, a plain decimal string in
  the quoted envelope for an issued currency). Two partial payments add up; a top-up of the
  shortfall settles. "Quoted asset" means: for a native asset every native amount on the tag, for
  an issued currency only those with the quoted `currency` **and** `issuer` — the same rule
  `SettlementPolicy::isWrongAsset()` states as a verdict, used here as a filter.
- A candidate in another asset — the other asset class (a token on a native quote, or the native
  asset on a token quote), another currency code, or another issuer — does **not** count towards
  the sum. It becomes the fulfillment — alone, newest first — only when **nothing** in the quoted
  asset has arrived, so the platform can show `wrong_asset` with the delivered amount. As soon as
  one payment in the quoted asset exists, only those count and the foreign ones are logged.
- **A candidate from another network never counts** — not towards the sum, not as a wrong-asset
  fulfillment, not in the deprecated single-candidate lookup. The same key pair is an account on
  testnet and mainnet, the tag and memo counters are per account, and a refreshed quote keeps account
  and identifier (`PaymentIntentService::resolveDestination()`), so a merchant who switches the
  network would otherwise see a testnet payment settle a mainnet order. Both syncs compare the
  record's `network` with the intent's before anything else and skip with a warning. Test money
  never pays a real order; the rule holds on every chain.
- `hash` and `ctid` on the intent name the **newest contributing** transaction; schema v1 carries
  no list. The full list is on the `Fulfillment` and always recoverable via `findTransactions()`.
- No time filter: the intent has no creation timestamp, and the tag's uniqueness (random counter
  start since 0.4) is the guarantee that everything on it belongs to this order.
- Unchanged: a candidate that delivered nothing measurable (not a Payment) and an unreadable
  delivered amount (the ledger's `"unavailable"`) are skipped and logged — never a reason to abort.
  A class mismatch is **not** skipped any more (0.8.1): until then a customer who sent RLUSD for an
  XRP order saw "waiting" forever while the money sat on the tag, because the candidate was thrown
  away before `SettlementPolicy` could call it the wrong asset.
- `SyncService::findTransactionFor()` — the single newest candidate in the quoted asset class, the
  other class still skipped — is **deprecated since 0.6.0** and keeps its old behaviour until it is
  removed in 1.0. An adapter on it never adds partial payments up.

- A `PaymentIntent` without `amount_paid` is never settled.
- **Native asset** (e.g. XRP): settled when `amount_paid >= amount_requested × (1 − tolerance)`. The
  default tolerance is **0.15 %** (`0.0015`) — the quote is a float rounded to five places and wallets
  may shave rounding on their side. A platform may pass a different tolerance (`0 ≤ t < 1`) from its
  configuration; it must not implement its own comparison.
- **Issued currency** (e.g. RLUSD, USDC, a merchant token): settled only when `currency` **and**
  `issuer` equal the quoted ones **and** `value >= requested value`, compared as exact decimals. There
  is no tolerance: values are decimal strings, not floats. A same-named token from another issuer is
  a different asset and never settles the order, whatever it is called.
- Overpayment settles. What to do with the surplus is the platform's business.
- `SettlementPolicy::shortfall()` reports what is still missing as a decimal string (null once
  settled) — the number a payment page shows as "X of Y received".
- `SettlementPolicy::creditedValue()` reports how much of the request has been credited, as a
  decimal string. A payment in the wrong asset credits `"0"`, however large it was: the delivered
  value must never be shown as progress towards an order it cannot settle. Together with
  `shortfall()` it always adds up to exactly the requested amount.
- `SettlementPolicy::isWrongAsset()` answers *why* nothing was credited — the customer paid, their
  wallet reported success, and it was a different asset. **Adapters must not re-derive this**, by
  comparing issuer and currency themselves or by inferring it from `shortfall()`; that is how one
  rule grew four slightly different definitions across the plugins.
- What "paid" *does* on the platform — invoice, order status, emails — is the adapter's concern.

## Payment status

"Is this order paid?" has one answer shape across every platform: `Core\Payment\PaymentStatus`,
**derived** from a `PaymentIntent`, a `SettlementPolicy` and the clock — never stored, no new
record, no migration. Every platform's status endpoint serializes it and appends only what the
platform alone knows.

Exactly five states, each of which a payment page must be able to show:

| State | Meaning | Extra fields |
|---|---|---|
| `waiting` | Nothing arrived, quote still valid | `seconds_left` |
| `partial` | Arrived in the quoted asset, does not settle — `amount_paid` is the **sum** of every payment in the quoted asset on this tag | `amount_paid`, `shortfall` |
| `wrong_asset` | Arrived, but another asset: the other asset class (a token for an XRP request or XRP for a token request), another currency code, or another issuer | `amount_paid`, `shortfall` |
| `settled` | Paid — the only terminal state | `amount_paid` |
| `expired` | Nothing arrived, quote expired | — |

**Derivation order is part of the contract:**

1. `SettlementPolicy::isSettled()` → `settled`
2. no `amount_paid` **and** `now >= expiry` → `expired`
3. no `amount_paid` → `waiting`
4. `SettlementPolicy::isWrongAsset()` → `wrong_asset`
5. otherwise → `partial`

A partial payment on an expired quote is `partial`, not `expired`: the customer sent real money,
and the page must say so before it talks about validity periods. `expired` means only "nothing
there, and the rate is stale". A quote expires **at** its `expiry` timestamp, so `seconds_left` is
never `0` in `waiting`; a quote without an `expiry` never expires and has no countdown
(`seconds_left` null).

`toArray()` carries exactly these keys, in this order, every key always present:

| Field | Shape |
|---|---|
| `schema_version` | Integer, currently `1`. First field, as in `PaymentIntent`. |
| `state` | One of the five values above. |
| `base_asset` | As in the intent. |
| `amount_requested` | As in the intent, same shape rule. |
| `amount_paid` | What actually arrived, as in the intent — **decoded**, undiminished, and in `wrong_asset` the *delivered* asset, so the page can name the wrong token. Null while nothing arrived. |
| `shortfall` | What is still due, **in the shape of `amount_requested`**: a float for a native asset, an `IssuedCurrencyAmount` carrying the *quoted* currency and issuer for everything else. Null unless `partial` or `wrong_asset`. In `wrong_asset` it is the whole request: nothing was credited. |
| `seconds_left` | Only in `waiting`, else null. |

- **`redirect` is not in the payload.** Building a URL is platform work; the adapter appends it
  to the serialized form. The core knows no HTTP.
- **Amount formatting stays out.** The core returns numbers, the adapter turns them into text.
- **Only `settled` is terminal** (`isTerminal()`). A frontend keeps polling through `partial` and
  `wrong_asset` so a top-up is noticed, and through `expired` so a late payment is.
- **Throttling is mandatory.** A status endpoint syncs with the ledger only when the receiving
  account's last sync is older than `PaymentStatus::MIN_SYNC_INTERVAL_SECONDS` (5 s); otherwise
  it answers from the stored intent, in the same shape. The core ships `Core\Xrpl\SyncThrottle`
  over the same PSR-16 cache as the rate cache, keyed by network and account — the sync is per
  account, so throttling per order would still let every waiting customer trigger a node request.
  An adapter uses it (`syncIfDue()`) or an equivalent; the last-sync mark is never part of
  `PaymentIntent`. A broken cache degrades to "always sync", never to "never sync".
- **Key knowledge, not account membership.** The endpoint authenticates with a per-order secret
  and must not require a login — guest checkout is the rule in crypto payments. It returns the
  status and nothing else, so a guessed key reveals nothing the payment page doesn't show already.

## Payment request — the QR code and the wallet apps

One payment request per order, built by `Core\Payment\PaymentUri::forIntent()` on the server and
identical on every platform; the page script only renders it as a QR code, and the wallet apps
open it. The form is the one Xaman's parser accepts:

```
https://xrplf.org//send?to=<account>&dt=<tag>[&amount=<amount>][&currency=<hex>&issuer=<address>]&network=<key>
```

- `to` and `dt` always; `currency` and `issuer` for an issued currency, exactly as quoted in the
  intent — never typed in, never merchant-editable (see StablecoinRegistry).
- `amount` is **the amount the page shows**, passed in by the adapter: the shortfall while a
  partial payment is in, the request otherwise. The core does not decide it, because only the
  page knows what it displays, and the two must never differ. Verified with Xaman on the testnet
  (2026-09-28, case PW-04): the value is read as the XRP decimal the page shows, not as drops,
  and a token request takes currency and issuer over.
- `AMOUNT_MODE` is `AMOUNT_DISPLAYED`. `AMOUNT_NONE` (address and tag only) stays available for a
  platform whose wallets have not been verified; it is the safe default for an unverified wallet,
  since a wrong unit is a money error.
- Parameters are RFC 3986-encoded; the order is `to`, `dt`, `amount`, `currency`, `issuer`,
  `network`. A refund's request (`forRefund()`, see [Refund](#refund)) comes from the same
  builder: `dt` only when the refund has a tag, and `invoiceid` before `network`.
- `network` always, last: Xaman's key (`MAINNET`, `TESTNET` on the XRP Ledger; `XAHAU`,
  `XAHAUTESTNET` on Xahau). Without it Xaman pays the request on whichever network it is on, and
  the r-address is valid on both chains: scanned while Xaman was on Xahau, an XRP request opened
  an XAH payment of the same amount (2026-11-12). With it Xaman offers the switch. A network
  without a key (devnet) is refused rather than sent without one.

`Core\Presentation\AccentColor` sits next to it as the one design rule every payment page
shares: a merchant's accent colour is used only if it carries white text at WCAG 4.5 : 1;
otherwise the default `#1f5eff`. Both classes are presentation helpers with no I/O — the core
still knows no HTTP and renders no HTML.

## Receiving account — can it take the payment?

A valid address (Security, below) is not yet an account that can be paid. On the testnet the
harness only ever uses ideal accounts — funded, trust lines set — so what a merchant's real account
lacks shows only on mainnet, after the order exists. Reproduced on 2026-11-17 in the Shopware dev
shop (testnet): the shop offered RLUSD to an account without a trust line, created the order, and
the customer's payment failed with `tecPATH_DRY`. Hence one check for every chain,
`Account\ReceivingAccountCheck`, implemented by `Xrpl\AccountInspector` (XRPL, Xahau) and
`Stellar\AccountInspector`, read-only and cached:

- **`offers($account, $asset, $network, $amount)`** — the checkout asks it for every asset it would
  list and leaves out what it denies, so no payment intent exists that nobody can settle.
- **`assess($account, $network, $assets)`** — the configuration screen asks it and shows every
  `Account\Finding` next to the option. The option stays visible and switchable: the merchant sees
  that it exists and what the account still needs. Reads afresh, and refreshes what the checkout
  reads.

| State of the receiving account | Finding | Checkout |
|---|---|---|
| Trust line missing, frozen, full, or not authorized where the issuer requires authorization; issuer globally frozen | `trustline_missing`, `trustline_frozen`, `trustline_full`, `trustline_not_authorized`, `issuer_frozen` | issued asset **not offered** |
| XRPL/Xahau: not activated | `account_missing` (with the base reserve) | issued assets not offered; the native asset offered unless the amount is known and below the base reserve — the first payment from the reserve up activates the account (reproduced: 1.13738 XRP to a fresh address, settled) |
| Stellar: account does not exist | `account_missing` | **nothing** offered: a Stellar payment never creates its destination (`op_no_destination`) |
| XRPL/Xahau: `DepositAuth` set | `deposit_auth` | **offered** — business flows in which pre-authorized customers or agents order through an API are possible; the merchant gets a clear warning only |
| The node cannot be asked | `not_checked` | issued assets not offered (fail closed), the native asset offered |

The base reserve comes from the node (`server_state`, validated ledger), not from a constant: the
validators vote on it and the chains differ. The trust line is the one to the registry's issuer
for the asset's currency (`StablecoinRegistry`); a line to any other issuer does not count.
Background and the decisions: `Documentation/Mainnet-Testing.md` (K3) in the LedgerDirect
documentation.

## Refund

Paying a customer back, on the XRP Ledger and on Xahau. Since 0.13.0; additive — a platform that
passes no refund repository to `LedgerDirect::create()` notices nothing.

**LedgerDirect never sends.** The core holds no key and submits no transaction. A refund is a
payment *request*: the merchant signs it in their own wallet, the core finds the outgoing payment
on the ledger afterwards, and only then is the refund executed. Nothing on a platform is booked
before that. This is the same custody rule as for payments, read in the other direction.

Two things must never happen, and every rule below serves one of them:

1. **Money arrives and belongs to nobody.** An account at an exchange is shared by its customers
   and told apart by the destination tag; a payment without the right tag is there and cannot be
   credited.
2. **Money leaves the merchant's account unnoticed.** Paid twice, paid after cancelling, paid in
   another amount, paid by hand before the refund existed.

What the ledger would refuse anyway (no trust line, an account that requires a tag and gets none,
an account that does not exist) costs a fee and loses nothing. The core checks for it to spare the
merchant the attempt; it is help, not protection.

Services: `Xrpl\RefundService` (preview, create, cancel, attach, booking),
`Xrpl\RefundReconciler` (finds the payments), `Xrpl\ReceivedPayments` (what an order received and
what can still go back). Record: `Refund\Refund`. Port: `Port\RefundRepositoryInterface`. All take
the chain like the other XRPL classes; a shop on both chains holds two roots.

### The record

`Refund\Refund`, schema version 1, immutable; a change is a new instance with `version` raised
by one. Three states: `awaiting_execution` → `executed` or `cancelled`; a cancelled refund can
still become `executed`, because a payment that happened is not undone by what the request said.

A refund always belongs to **one incoming payment** (`source_hash`): it goes back in that
payment's asset, and never exceeds what that payment delivered. `paying_account` is the account
the source payment went to — not whatever the shop is configured with today.

It is stored as a row, one column per field (`Refund::toRow()`, `fromRow()`, `Refund::COLUMNS`),
so the table reads at a glance. Five columns are plain text from the record's constants, never
numbers and never a database ENUM:

| Column | Values |
|---|---|
| `status` | `awaiting_execution`, `executed`, `cancelled` |
| `purpose` | `order_refund`, `surplus_return`, `wrong_asset_return` |
| `destination_origin` | `sender`, `stated` |
| `matched_by` | `key`, `reference`, `attached`; null while not executed |
| `deviation` | empty, or `other_account`, `amount`, `after_cancel`, comma-separated in that order |

`deviation <> ''` finds every execution that differs from its request; `paying_account` next to
`executed_account` and `amount` next to `delivered_amount` show how. Amounts are decimal strings
(`Xrpl\LedgerAmount`), never a float in the record and never a numeric column. A null
`asset_issuer` marks the native asset. `fromRow()` reads strictly: an unknown status, a damaged
`findings` column or a missing column is refused, not half read.

**Purpose** is decided by what the source payment is, not chosen freely
(`Xrpl\ReceivedPayments`):

- a payment in **another asset** than the quoted one can only be returned as it came
  (`wrong_asset_return`);
- a payment in the **quoted asset** can be refunded for the order (`order_refund`), up to what it
  still has to give;
- if more arrived in the quoted asset than was asked, the excess can go back without touching the
  order (`surplus_return`), up to the excess.

Only `order_refund` is meant to change an order's state on a platform. Whether an order is
refunded in part or in full is decided **in tokens** — executed `order_refund`s against what
counted for the order (`PaymentOverview::$orderRefundState`) — not against the order's value in
the shop's currency, which can change after the payment.

### The destination and its tag

A destination has an origin: `sender` (the account the source payment came from) or `stated` (an
address the customer named and the merchant entered).

**No refund without a destination tag unless somebody vouches for the address.** Whether an
address is a shared account cannot be read from the ledger with certainty, so "no tag" is never
the default. `RefundDestination` cannot be built without a tag unless it carries an attestation
(`own_wallet`: the merchant confirmed that the customer called the address their own wallet).

| Destination | Without a tag |
|---|---|
| The sender, and the sender's account requires a destination tag (`lsfRequireDestTag`) | **refused** (`sender_may_be_shared`, reason `requires_tag`) |
| The sender, and the source payment carried a `SourceTag` | **refused** (reason `source_tag`; the tag is offered as a question for the customer) |
| The sender, and the source payment cannot be read from the ledger | **refused** (reason `source_unreadable`): unknown is not "had none" |
| Any account that requires a destination tag | **refused** (`tag_required`) |
| Anything else | only with the merchant's confirmation (`confirmation_required` until then) |

- The rule for the sender holds **whether the sender's address was taken from the payment or
  typed in** as a stated destination. Typing it is not a way around it.
- The sender's address is **never combined with a tag somebody typed**: the account an exchange
  pays out from is often not the one it takes deposits on. Address and tag come together, from
  the customer, as a stated destination.
- A `SourceTag` is, by the ledger's convention, the tag a refund should carry. The core **shows**
  it (`RefundPreview::$sourceTag`) and never uses it on its own: whether the sender follows the
  convention is not on the ledger, and a wrong tag at a shared account credits a stranger.
- A typed tag is typed twice (`destinationTag`, `destinationTagRepeat`) and compared by the core.
  An address carries a checksum; a tag does not.
- **No tag is not tag 0**, anywhere: in the record (`null`), in the row (SQL `NULL`), in the
  open key, in the payment request (no `dt` at all), in matching.
- Refused outright: the paying account itself as destination; the token's issuer as destination;
  an address whose checksum fails.

The account is asked afresh for every preview and every creation (`Xrpl\RefundDestinationCheck`,
no cache): a five-minute-old answer to "does this account require a tag" is not an answer. With
the node unreachable nothing is created (`ledger_unavailable`).

### The amount

A request names its amount in exactly one way: in tokens, in the shop's currency, or "everything".

- **Limit.** Per source payment: delivered, minus what executed refunds *delivered*, minus what
  open requests ask for. A cancelled refund claims nothing.
- **Shop currency**, for a payment in the quoted asset only (the quote's rate says nothing
  about another asset). `refund × requested tokens ÷ order value`, exact, rounded **up** to the
  asset's quote precision (`Refund\RefundAmount`). 80.00 paid as 86.40 USDC, 20.00 back:
  21.60. Not "refund ÷ stored rate" — the rate in the record is a float. The platform supplies
  the order value (the record has none); the core accepts it only if quoting it again at the
  stored rate gives exactly the requested amount, and otherwise asks for tokens
  (`fiat_basis_mismatch`). When rounding up would push the last refund over what is left, the
  limit wins; a share that exceeds it even rounded down is refused.
- **Everything** is the exact remainder, never derived from the shop's currency.
- The refund is in the **received** asset, also when the customer paid through the ledger's
  exchange and spent another one (`paid_across_assets`): whether they can take it back is a
  question for their trust lines, answered afresh.

Two simultaneous requests for one payment cannot both fit under it: each takes the next `serial`
for that payment from the same reading its limit was computed from, `(network, source_hash,
serial)` is unique, and the one that loses reads again. And once a refund is stored the core
looks again: if its payment, or the order's excess, is then asked for more than there is —
another refund executed in a larger amount meanwhile, a surplus return created at the same
moment on another payment of the order — the new refund is withdrawn (stored as cancelled) and
the request refused. Two that race may both be refused; never both stay.

### The payment request

`Core\Payment\PaymentUri::forRefund()`, the same form and the same builder as a customer's
request:

```
https://xrplf.org//send?to=<destination>[&dt=<tag>]&amount=<amount>[&currency=<hex>&issuer=<address>]&invoiceid=<reference>&network=<key>
```

- Only a refund that awaits execution has a request (`RefundService::paymentUri()` is null for a
  cancelled or executed one).
- `dt` **only when the refund has a tag**.
- `amount` always, exact.
- `invoiceid` is the refund's **reference**, 64 hex digits. Xaman copies it into the payment's
  `InvoiceID` field (scanned on both testnets on 2026-11-20, also together with a tag and a
  token). Other wallets may not; a payment without it is matched by its key.
- With an installation key the reference is derived
  (`RefundService::deriveReference()`): HMAC-SHA256 over
  `ledger-direct-refund-v1|<chain>|<network>|<source hash>|<serial>`. It can be computed again
  from the payment a refund pays back, so executed refunds can be found on the ledger even if the
  table were lost — and nobody without the key can compute which refund belongs to which
  payment. Without a key the reference is random. A reference is unique per network.

### Matching

`Xrpl\RefundReconciler` reads the paying account's own transactions and holds every outgoing one
against the account's refunds (`Xrpl\RefundMatcher`).

A payment **executes** a refund only if all of this holds:

- validated, result `tesSUCCESS`;
- to the refund's destination, **with the refund's tag** (none means none);
- in the refund's asset, in **exactly** the refund's amount — compared on `delivered_amount` as
  decimals, never as floats, and never on the `Amount` field (a partial payment's `Amount` 5
  delivered 1);
- in a ledger **after** the one the refund was created in. The bound is the validated ledger
  index at creation (`created_ledger`), not a clock.

A payment that carries a refund's reference names **that refund and no other**: it executes it
if everything above fits, and is a finding on it if not. **The reference never stands in for the
tag or the amount.** Without a reference, the oldest open refund with the exact key is executed.

No second open refund with the same paying account, destination, tag, asset and amount can exist
(`open_key`, unique while the refund is open): a payment could not be told to belong to one or
the other.

Everything else that leaves the paying account for a refund's destination is a **finding** on
the refund (`Refund\RefundFinding`, one per refund and transaction), kept and shown, never
assumed to be the refund:

| Finding | What happened |
|---|---|
| `tag_mismatch` | went to the destination without the refund's tag, or with another one |
| `amount_mismatch` | the tag fits, the amount or the asset does not |
| `destination_mismatch` | carries the refund's reference and went to another account |
| `duplicate` | fits a refund that is executed already |
| `after_cancel` | fits a refund that was cancelled |
| `paid_before` | fits, but is from before the refund was created |
| `unreadable` | a successful transaction whose delivered amount cannot be read (not a `Payment`; Xahau's `Remit`) |
| `failed_attempt` | the ledger refused it; `result` says why (`tecDST_TAG_NEEDED`, `tecPATH_DRY`) |

A refused payment is validated and in the **sender's** `account_tx`, never in the destination's
(recorded on both testnets on 2026-11-20). It delivered nothing and the refund stays open.

Looked after are the refunds awaiting execution and, for seven days
(`RefundReconciler::GRACE_SECONDS`), those executed or cancelled. While an account is read at
all, every outgoing transaction is held against all of its refunds to that destination.

**Reading is strict.** The payment sync skips an entry it cannot read; here that entry could be
the payment that left the account. An entry without hash, without `validated`, or validated
without a result (`Xrpl\LedgerTransaction`), an answer without a transaction list, a node that
does not answer, a page budget that runs out, a transaction that could not be stored because its
refunds kept changing: the pass says `complete: false`, keeps what it found before, and concludes
nothing from the rest. `lgrIdxsInvalid` changes no state; the payment sync's recovery after a
testnet reset is not used.

**"Read to the end" is a statement about ledgers, and only the answer can make it.** A pass is
complete only if the `account_tx` answer says which ledgers it covers (`ledger_index_max`) and
they reach the newest validated ledger, give or take the few ledgers two nodes of a pool differ
by (`MAX_LAG_LEDGERS`, 20). An answer that ends earlier comes from a node that has not seen what
happened since; "nothing found" there is not "nothing happened", and a refund is not cancelled
on it.

On a **test network**, a refund created far beyond the node's newest ledger is from another
epoch of a network that was reset: it is reported (`unreachable`), not looked for, and can be
cancelled. On mainnet nothing is ever written off like that; a node that far behind makes the
pass incomplete.

A page budget never stalls a pass for good: a pass that would end before it has read a single
ledger to its end reads on until it has.

Reading from "the ledger the refund was created in" means `ledger_index_min` equal to that
index: a node serves `ledger_index_min` up to its newest validated ledger and answers
`lgrIdxsInvalid` for the next one (recorded on both testnets).

A pass is idempotent. The cursor it keeps in the cache saves reading and decides nothing, and
it never goes further than the `account_tx` answers themselves say they went
(`ledger_index_max`): the public endpoints are pools of nodes, and the one that answers
`account_tx` may be a few ledgers behind the one that answered `server_state`.

**Attaching.** A merchant can name the transaction that paid a refund
(`RefundService::attach()`): one from another account of theirs, in another amount, from before
the refund existed, or after it was cancelled. It must still be a validated, successful `Payment`
to the refund's destination, with the refund's tag, in the refund's asset; what differs is
written in `deviation`. One transaction executes one refund (`executed_hash` is unique).

**Cancelling** reads the ledger first (`RefundService::cancel()`). A refund paid a moment ago
comes back executed; and if the ledger cannot be read to the end, the refund is **not**
cancelled — cancelling frees its amount for another refund while nobody knows whether the first
was paid.

**Before creating**, the preview shows what the paying account already sent to the destination
since the source payment (`paid_before`), other than executions on record. That is advice, and
the one protection that would still hold without the refund table.

### Booking

What an executed refund does on a platform — a state, a note on the order — is the adapter's
business; that it happens **once** is the core's. `RefundService::claimBooking()` gives the
refund to one caller for a limited time; the platform acts; `markBooked()` records it. A status
request and a scheduled task that find the same execution at the same moment cannot both book
it, and a process that died in between is replaced when its claim runs out.

A claim is recognised by its deadline, not by the refund's version: a finding added to the
refund while the platform acts does not make the booking fail.

A claim is a lease, not a lock on the platform: a holder that is still working when its claim
runs out is overtaken, and its `markBooked()` is then refused — after it may have acted. So what
a platform does when it books must be safe to do twice (a state that is already set stays set,
a note is written under the refund's id).

### Tables

`ledger_direct_xrpl_refund` and `ledger_direct_xahau_refund` (`Xrpl\RefundSchema`), an entry of
its own: `Schema::tables()` still describes exactly the two tables it always did.

- Four **unique indexes** carry the rules that two simultaneous requests could otherwise break,
  and an implementation must hold them as indexes, never as a query followed by a write:
  `(network, source_hash, serial)`, `open_key`, `executed_hash`, `(network, reference)`.
- `update()` is `UPDATE … WHERE id = ? AND version = ?`.
- `Testing\RefundRepositoryContractTestCase` is the contract as a test; an adapter's repository
  extends it.
- **The table is a record, not a cache.** It holds what is nowhere on the ledger: which order a
  payment was for, its purpose, where the destination came from, who confirmed it, whether it
  is booked. The transaction table can be emptied and synced again; this one cannot. Nothing
  empties it — no sync, no testnet reset, no clean-up job — the port has no `truncate()`, and it
  survives uninstall like the destination-tag counter. Lost, the limit of every payment would be
  whole again.

### What stays with the merchant

Not everything can be prevented, and the contract says so:

- An exchange's account that neither requires a tag nor sent a source tag, for which the
  merchant confirms "own wallet" anyway.
- A tag the customer got wrong. Nobody can check it.
- An amount or destination the merchant changes in the wallet before signing. It is noticed (a
  finding), not prevented.
- A payment of **more** than the refund asked for, which the merchant then names as its
  execution: the refund records what arrived (`deviation` `amount`), and the refunds of one
  payment can then add up to more than it delivered. The limit governs what may be requested;
  it cannot take back what was paid.
- A user of the platform who enters their own address as the customer's. Every refund names
  who created it (`created_by`; a request without it is not accepted); who may create one is
  the platform's permission to give.

### Known gaps

- Stellar has no refunds yet.
- A refund is always in the received asset; none in the asset a customer spent through the
  ledger's exchange.
- Refunds are not subtracted from what counts as paid: `SyncService::findFulfillmentFor()` and
  `PaymentStatus` do not know them. A platform must not let a payment that arrives after an
  order refund settle the order by itself.
- No signing in the browser, no confirmation of the destination by the customer.
- A token whose issuer charges a transfer fee: the request names the amount to deliver, and a
  wallet that sets `SendMax` to the same amount cannot pay it (`transfer_fee` warns).

## Rate caching

Optional, and **off entirely** unless a PSR-16 cache is injected into `PriceService` — without one
the service behaves exactly as it did before caching existed. The core stores nothing itself; where
the bytes land is the platform's business, as with `XrplTransactionRepositoryInterface`.

- **Only the exchange rate is cached, never a `PriceQuote`.** The amount depends on the order
  total; the rate does not.
- **Failures are never cached** — a cached failure would block checkout.
- Two tiers, both derived from `freshTtlSeconds` (default **60 s**, constructor argument):
  - **Fresh** (age ≤ TTL): served without an oracle call.
  - **Stale-while-error** (TTL < age ≤ TTL × 5): served *only* when every oracle just failed, with
    a PSR-3 warning. Oracles that answer and are all rejected by the divergence filter have not
    failed: the prices are in doubt, so no stale rate stands in. Beyond that horizon, `PriceUnavailableException` as before. This is the point
    of the feature: a single oracle hiccup must not make the payment method vanish mid-checkout.
- A quote may therefore rest on a rate up to `freshTtl` old — and up to `freshTtl × 5` during an
  oracle outage.
- The entry is written with the **stale horizon** as its PSR-16 TTL and carries its own
  `fetched_at`: PSR-16 cannot return an expired value, so freshness has to live inside the value.
- Key: `ledger-direct.rate.v1.{network}.{BASE}.{QUOTE}` — the network **must** be in the key or
  mainnet and testnet collide; `v1` allows invalidating every entry at once if the oracle set
  changes. Dots are PSR-16-legal; `{}()/\@:` are reserved.
- **A broken cache must never take checkout down.** Read/write failures are caught and logged; an
  unreachable backend degrades to "no cache", not to "no payment". A malformed or foreign value
  under the same key reads as a miss.
- The fulfillment side (`delivered_amount`, matching, `withFulfillment()`) is untouched by any of
  this.

## Stellar

Stellar is the second chain. It gets its own sibling port, client, sync and composition root
under `Core\Stellar\…` and `Core\Port\StellarPaymentRepositoryInterface` — **not** a generalized
multi-chain interface: a port that has to serve both XRPL's ledger-index/marker pagination and
Horizon's operation cursor would serve neither. What is shared is what was already chain-agnostic:
`PaymentIntent`, `SettlementPolicy`, `PaymentStatus`, `ConfigProviderInterface` (with `$chain`),
`SyncThrottle`, the oracle mechanics and the identifier permutation.

The settlement rule above applies unchanged, read for Stellar by
`Stellar\SyncService::findFulfillmentFor()`: every payment in the quoted asset on the memo id adds
up — XLM for a native quote, the quoted code **and** issuer for an issued one. A payment in another
asset, the other class included (XLM on a USDC quote, an issued asset on an XLM quote), is a foreign
candidate like on XRPL: alone it is the fulfillment so the platform shows `wrong_asset` with the
delivered amount; next to a payment in the quoted asset it is put aside and logged. It is never
skipped — the 0.8.1 rule holds on both chains, which is what lets `PaymentStatus` treat them alike.
A payment from another network is skipped before any of this, as on XRPL.

### Names in the record

- `chain` is **`STELLAR`**, `network` is `mainnet` | `testnet` as on XRPL.
- `type` carries a chain prefix: `stellar-xlm-payment`, `stellar-usdc-payment`,
  `stellar-eurc-payment`. XRPL's types stay unprefixed; without the prefix `usdc-payment` on the
  two chains would be indistinguishable, and adapters map handlers by type.
- `base_asset` is `XLM`, `USDC` or `EURC`. XLM is the chain's native asset
  (`NATIVE_ASSET_BY_CHAIN['STELLAR'] = 'XLM'`), so `amount_requested` / `amount_paid` are a
  float for XLM and `{currency, value, issuer}` for everything else — with the asset code in
  **plain text** (`"USDC"`, `"EURC"`), never hex, and without XRPL's `USDC_CODE = 'USD'` quirk.
  Stellar amounts on the wire are decimal strings with seven places; the record carries them as
  plain decimals (`"12.34"`, not `"12.3400000"`).
- `destination_tag` carries the **memo id**. The field name is an XRPL inheritance; the meaning —
  a numeric identifier that ties a payment to an order — is the same, and a second field would
  be a schema step for nothing.
- `hash` is the transaction hash of the newest contributing payment; `ctid` is `null`, it is an
  XRPL concept.

### Identifiers

The merchant's memo ids come from the **same permutation and the same range** as XRPL's
destination tags — `10000`–`4294967295`, one counter per receiving account, random start,
never 0 (`Core\Identity\SequencePermutation`, shared by `DestinationTagService` and
`Stellar\MemoIdService`). Stellar's `MEMO_ID` is a `uint64`; the wider range buys nothing and
would cost PHP's signed 64-bit integer and every adapter's column type.

What an incoming payment is matched by, in this order (CAP-67 precedence):

1. the muxed id, when the destination is a muxed account (`M…`, Horizon's `to_muxed_id`);
2. otherwise the transaction's `MEMO_ID`;
3. otherwise a `MEMO_TEXT` that is **exactly** the canonical decimal form of an id
   (`^(0|[1-9][0-9]*)$`, no spaces, no leading zeros, no sign, at most `18446744073709551615`);
4. otherwise the payment carries no identifier and is stored but never matched.

Identifiers are handled as **decimal strings** end to end: PHP's `int` is signed 64-bit and a
foreign memo or muxed id can reach `2^64 − 1`. The record's `destination_tag` stays an `int`
(the merchant's own ids never exceed `2^32 − 1`); matching compares the canonical string form.
The memo the customer is shown is the `MEMO_ID`; a muxed address is accepted on receipt but is
not the primary instruction, since not every wallet supports it.

### Payment source

**Horizon first**, behind the internal `Stellar\PaymentSource` boundary; Stellar RPC follows as a
second implementation before Horizon goes away. Endpoints are constants, as with
`XrplClient::JSON_RPC_URLS`: `https://horizon.stellar.org` and `https://horizon-testnet.stellar.org`.
SDF's public Horizon allows 3600 requests per hour and IP: the client must honour `429` and
`Retry-After` rather than hammer, and the account inspector's answers are cached.

The sync reads `GET /accounts/{destination}/payments?join=transactions` from the stored cursor
and keeps only **`payment`, `path_payment_strict_send` and `path_payment_strict_receive`
operations whose `to` is the receiving account**. Everything else the endpoint lists —
`create_account` (the account's own funding shows up as the first record), `invoke_host_function`,
`account_merge`, claimable balances — is skipped. For a path payment the credited amount is
`amount`, what arrived, never `source_amount`.

When RPC becomes the source it must read CAP-67 `transfer` **and** `mint` events to the
receiving account: a payment sent by the asset's issuer is a `mint`, not a `transfer`. Both carry
`to_muxed_id`, which is a `u64` for `MEMO_ID` and muxed destinations and a **string** for
`MEMO_TEXT` — the canonical-form rule above applies there too.

### Tables

`ledger_direct_stellar_payment` and `ledger_direct_stellar_memo`, defined by
`Core\Stellar\Schema` (`tables()` as data, `mysql($prefix)` as DDL), prefixed and created by the
platform like the XRPL tables.

- One row per **payment operation**, not per transaction: a Stellar transaction can carry
  several payments, and the sum rule needs each one. The two operations of one transaction are two
  rows with the same `hash`.
- **Unique on `(hash, op_index)`**, not on the operation id. Operation ids (TOIDs) encode
  ledger, transaction and operation position and **repeat after a testnet reset**; a unique
  operation id would make a stale row from the previous epoch swallow a new, real payment as a
  duplicate. The hash includes the network passphrase and the sequence number and does not repeat.
- `payment_id` (`BIGINT UNSIGNED`, the TOID) orders rows and is the cursor: `getLastPaymentId()`
  is `MAX(payment_id)` **per receiving account and network** — the XRPL lesson applied from the
  start — and `findPayments()` returns `payment_id DESC`, tie-broken by primary key descending.
  There is no separate `paging_token` column: on the payments endpoint it equals the operation id.
- `memo_id` is `BIGINT UNSIGNED NULL` and holds the resolved identifier (muxed id, `MEMO_ID` or
  canonical `MEMO_TEXT`), read back as a string. `amount` is a decimal string with up to seven
  places; `asset_code` is the plain code (`XLM` for native) and `asset_issuer` is null for native.
- `ledger_direct_stellar_memo` is the per-account counter, one row per receiving account,
  `INT UNSIGNED`, random start, and it **must survive uninstall** — the same rule and reason as
  the XRPL counter.

### The testnet is not persistent, and Horizon does not say so

The Stellar testnet is reset periodically. Unlike rippled, Horizon answers a cursor from the
previous epoch with **HTTP 200 and no records** — there is no error to react to, and a sync
would silently find nothing forever. The core therefore detects the reset itself: the cursor is a
TOID whose upper 32 bits are the ledger sequence (`payment_id >> 32`). Before a cursor-based
sync the client reads `history_latest_ledger` from Horizon's root document; if the cursor's
ledger is **more than `SyncService::RESET_LEDGER_MARGIN` (1000 ledgers) above** it, the cursor is
from a previous epoch — a PSR-3 warning, then a sync with no cursor. The margin exists because the
root document can lag the ledger Horizon has just served by one or two (observed in the M0 spike);
a reset rewinds by millions, so the two cases never meet. The `(hash, op_index)` key keeps that from duplicating anything. RPC, for the record,
rejects an out-of-range `startLedger` with an explicit error.

### Conversion, oracles, pegs

- XLM is quoted like XRP: Binance + Coingecko (`stellar`) + Kraken read generically, divergence
  filter `0.05`, **5** decimal places, and the same native-asset settlement tolerance (default
  0.15 %) — Stellar charges the fee to the sender, so the tolerance only covers rounding.
- USDC and EURC are quoted to **2** places, Coingecko only (`usd-coin`, `euro-coin`). Fast paths:
  USDC at `1.0` when the quote currency is `USD`, EURC at `1.0` when it is `EUR`. Stablecoin
  rounding stays at two places: cross-chain USDT0-style normalisation to six places is harmless
  at two, and raising the precision has to re-check that.
- The rate cache key (`network`, `BASE`, `QUOTE`) is shared between chains on purpose: XRPL-USDC
  and Stellar-USDC have the same fiat rate.

### Preconditions the adapter checks

A payment to an account without a trust line for the asset fails with `op_no_trust` and never
arrives; one beyond the trust line's limit fails with `op_line_full`; an account that does not
exist cannot receive at all. None of this is a sixth payment state — it happens before the order.
`Stellar\AccountInspector` answers `accountExists()`, `hasTrustline()` (with the limit's headroom
and the `is_authorized` flag) and `issuerFlags()` (`auth_revocable`, `auth_clawback_enabled`),
read-only and cached. The adapter offers an asset only when it is priceable **and** the receiving
account can take it, and shows the merchant the issuer's flags as a one-liner — a token whose
issuer can freeze and claw back is worth knowing about in a product that sells self-custody.
`offers()` and `assess()` put this behind the check every chain shares ("Receiving account — can it
take the payment?").

### Registry — security-critical

`Stellar\StablecoinRegistry` holds USDC and EURC for mainnet and testnet. The issuers were copied
from Circle's developer documentation on 2026-09-30, verified against Horizon the same day (the
asset exists on its network under that issuer, held by thousands of accounts), and both the
documentation excerpt and Horizon's answers are checked in under `tests/Stellar/fixtures/registry/`;
`StablecoinRegistryTest` compares the constants with them. They are never merchant-configurable and
never typed from memory. For control only:

```
USDC  mainnet  GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN
USDC  testnet  GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5
EURC  mainnet  GDHU6WRG4IEQXM5NZ4BMPKOXHW76MZM4Y2IEMFDVXBSDP6SJY4ITNPP2
EURC  testnet  GB3Q6QDZYTHWT7E5PVS3W7FUT5GVAFC5KSZFFLPU25GO7VTC3NM2ZTVO
```

Observed on the same day: Circle's USDC issuers (both networks) and the EURC mainnet issuer are
`auth_revocable` — the issuer can freeze a trust line; none has `auth_clawback_enabled`. That is
what the adapter's issuer-flags one-liner shows. USDT0 is not in the registry: it has no documented
testnet issuer, so it could not pass the case catalogue.

### Known gaps

- **Soroban transfers.** USDC can arrive through the Stellar Asset Contract as an
  `invoke_host_function` operation; Horizon reports it under `asset_balance_changes`. Not matched
  yet. CAP-67 events over RPC cover it, which is the main reason RPC follows as a source.
- **Claimable balances** are not payments to the account and carry no memo; not matched.
- **Testnet-only assets** for the case catalogue are self-issued (`TESTUSD` in the fixtures); the
  registry never contains them.

## Xahau

Xahau is the third chain. It speaks the XRP Ledger's protocol on its own network — the same
`account_tx`/`tx` answers, `delivered_amount`, CTID form, r-addresses and destination tags — so it
gets **no** sibling classes like Stellar. The XRPL classes take the chain as a parameter, and every
such parameter defaults to `XRPL`, which keeps the XRP Ledger's behaviour as it was:
`LedgerDirect::create(…, chain: 'XAHAU')`, `Xrpl\XrplClient`, `Payment\PaymentIntentService`,
`Xrpl\SyncThrottle`, `Xrpl\Schema::tables()`/`mysql()`. The sync, the settlement rule, the tag
service and `PaymentStatus` are unchanged; a shop that takes both chains holds two roots, each with
its own repository and its own configuration under the chain's name. Verified on the Xahau testnet
on 2026-11-06 (fixtures under `tests/Xahau/fixtures/`).

### Names in the record

- `chain` is **`XAHAU`** — not a network of `XRPL`: `base_asset` XAH on `chain: XRPL` would be
  false, and the native asset follows the chain. `network` is `mainnet` | `testnet`.
- `type` is `xahau-xah-payment`, `base_asset` `XAH`, `amount_requested` a number as for XRP.
- No issued assets yet. Euro stablecoins exist on Xahau; none is in a registry.

### Tables

`ledger_direct_xahau_tx` and `ledger_direct_xahau_destination_tag`, with the XRPL tables'
columns; index names carry `xahau`, since PostgreSQL wants an index name unique per schema. **No
row and no tag counter is shared with the XRP Ledger**, not even for an account that exists on
both: the same keys open the same r-address on both chains (Xahau's `Import`), and merchants are
asked to use one account per chain. The adapter warns — it cannot refuse, the formats are
identical — when the same receiving account is configured for both.

A payment sent on the other chain never reaches an order: it is in the other chain's table, or in
none. That is a log case, not a payment state (decided 2026-11-06; revisit if it shows up in
support).

### Endpoints

Fixed in `XrplClient`: `https://xahau.network/` (mainnet, network id 21337) and
`https://xahau-test.net/` (testnet, 21338), run by Xahau's foundation; not merchant-configurable,
like the XRP Ledger's. A node that refuses with HTTP 429 or 503 raises an `XrplRpcException` like
any other failed call, with the node's `Retry-After` in the message; nothing sleeps inside a shop
request, and `SyncThrottle` stays mandatory. The mainnet node sits behind rate limits counted in
units with `RateLimit-*` headers and accepts API keys; a configurable endpoint or a key are the
documented alternatives if the public node stops serving shops.

The testnet keeps about three weeks of history. A cursor older than that gets `lgrIdxsInvalid`
like a cursor from before a reset, and the same recovery applies (sync again without a cursor).

### Conversion, oracles

- XAH is quoted to **5** places like XRP, with the same native-asset tolerance.
- XAH trades on one exchange, Bitrue (XAH/USDT, about 13,500 USDT a day on 2026-11-06). The
  oracle set is Coingecko (`xahau`) and Bitrue, asked directly, read like every other set: the
  prices that pass the divergence filter (`0.05` off their mean) are averaged. With two feeds that
  means both are averaged while they are within about ten percent of each other, and a wider
  disagreement leaves no price — there is no third feed to decide. Both report the same market:
  the set catches a stale or broken aggregator, **not** a manipulated one. Binance and Kraken do
  not list XAH; CoinMarketCap needs an API key and reports the same market. Bitrue quotes USDT
  only and answers USD; an EUR quote rests on Coingecko alone.
- An on-ledger price oracle for XAH exists on mainnet; its own description says it is the median
  of Coingecko and Bitrue, so it adds no source.

### Payment request

The XRPL form plus `network=XAHAU` (mainnet) or `network=XAHAUTESTNET` (testnet), last in the
query. With it Xaman offers the switch when it is set to another network; without it the request
would be paid on whichever network the wallet is on — and the same r-address may exist there. The
request without the parameter was paid on Xahau as a `Payment` when Xaman was already on Xahau;
the parameter was shown to trigger the switch hint. XRP Ledger requests name theirs too, since
2026-11-12; see "Payment request".

### Known gaps

- **`Remit`.** Xahau's multi-amount payment carries no `delivered_amount`; the sync stores it and
  never counts it. A checkout asks for a payment and wallets send a `Payment`; a `Remit` is built
  by hand.
- **Hooks** (logic on the account, e.g. refusing payments without a tag) are not used; a hook on
  the merchant's account can raise the fee or refuse a payment, which the core does not see.
- **`AccountDelete`** is not enabled on Xahau (amendment `DeletableAccounts`); nothing in the core
  needs it.

## Amount encoding on XRPL

The ledger's wire encoding is **not** the record's shape, and the core owns the translation:

| | On the wire (e.g. `meta.delivered_amount`) | In a `PaymentIntent` |
|---|---|---|
| XRP | integer string of **drops**, e.g. `"15063780"` (1 XRP = 1,000,000 drops) | float, e.g. `15.06378` |
| Issued currency | `{currency, value, issuer}` | same object |

- `XrplAmount::dropsToXrp()` / `xrpToDrops()` are exact (via `brick/math`); sub-drop amounts are
  **rejected**, never rounded. `XrplAmount::decode()` takes either wire form and returns the
  record shape.
- `XrplTransaction::getDeliveredAmount()` reads `delivered_amount` (or the raw `DeliveredAmount`)
  and returns it already decoded — feed it straight into `PaymentIntent::withFulfillment()`.
- Credit an order **only** from `delivered_amount`. On a partial payment the transaction's own
  `Amount` is what the sender asked to deliver, not what arrived.
- `null` means the transaction delivered nothing measurable (it isn't a Payment). The ledger's
  literal `"unavailable"` is **not** null and is refused: money arrived, the amount is unknowable.
- **This is ledger encoding, not a platform detail** — no adapter reimplements it.

## StablecoinRegistry — security-critical

> A wrong issuer address sends customer funds to a dead trustline → real, unrecoverable loss.
> **Do not hand-transcribe these values from memory.** They were pulled from the Shopware
> ground-truth `src/Provider/StablecoinProvider.php` by reading the source file directly, once, at
> authoring time, and are then treated as fixed in `Xrpl\StablecoinRegistry`. This repo has no
> dependency on, or reference to, the Shopware repo.
>
> **Checked against the issuers since 2026-11-17**, as the Stellar registry is: Circle's USDC
> contract-address table (rows "XRPL" and "XRPL Testnet") and Ripple's RLUSD documentation (token
> addresses; "RLUSD on the XRPL", mainnet and testnet issuer and the currency hex) are checked in
> verbatim under `tests/Xrpl/fixtures/registry/issuer-excerpt.txt`, and the ledger's
> `gateway_balances` for each issuer from the same day next to it (each has issued exactly that
> currency, in circulation). `StablecoinRegistryTest` compares the constants with both. Touch a
> value only with the source open, and record the source again.

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
- XAH → Coingecko + Bitrue (USD only), averaged through the same filter; one market behind both (see "Xahau").
- Kraken is read **generically** (read the single `result` entry, never key by `XXRPZUSD`).
- All catches are logged via PSR-3.

## Tables

- `ledger_direct_xrpl_tx`, `ledger_direct_xrpl_destination_tag` — naming convention
  `ledger_direct_{chain}_{entity}`. These are **logical/base** names; each platform adapter
  **prepends its own table prefix**: WooCommerce `$wpdb->prefix` (e.g. `wp_`), PrestaShop
  `_DB_PREFIX_` (e.g. `ps_`), Magento its configured `db/table_prefix`; Shopware none. The core
  never references a physical table name — persistence lives entirely behind
  `XrplTransactionRepositoryInterface`, so applying the prefix is 100% the adapter's concern and
  nothing needs to be injected into the core.
- Unique index on `hash`.
- The CTID is stored in a column named **`tx_ctid`** (`VARCHAR(16) NOT NULL`), not `ctid`. The
  core refuses a CTID that is not `C` plus 15 hex digits (`XrplTransaction::CTID_PATTERN`) when it
  hydrates a synced transaction — skipped and logged like any malformed entry — so no adapter's
  database ever gets to truncate one silently (MySQL does under `INSERT IGNORE`, or with the strict
  modes WordPress switches off).
  PostgreSQL gives every table a system column called `ctid`, and no user column may take that
  name, quoted or not — a `ctid` column makes the table impossible to create there. The record
  field in a `PaymentIntent` stays `ctid` (see [Metadata fields](#metadata-fields)); the column
  and the field are named differently on purpose, and must not be "aligned".
- **No column name in any chain's schema may be a PostgreSQL system column name**: `tableoid`,
  `xmin`, `cmin`, `xmax`, `cmax`, `ctid` (`Schema::POSTGRES_SYSTEM_COLUMNS`). The schema tests
  enforce it, for every table the core describes.
- `ledger_direct_xrpl_tx` carries a **`network` column** (`VARCHAR(16) NOT NULL`, `'mainnet'` |
  `'testnet'`) with an index on `(destination, network, ledger_index)`. A ledger index only means
  anything within one network, so the sync cursor
  (`XrplTransactionRepositoryInterface::getLastSyncedLedgerIndex($destinationAccount, $network)`)
  must be scoped by both. A global `MAX(ledger_index)` is not a valid implementation: one mainnet
  row pins the cursor above every testnet ledger and the testnet sync then returns nothing
  forever, and a merchant who switches receiving accounts starts the new account at the old one's
  cursor. The CTID does encode a network id, but parsing it back out is far more work than a column.

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
- The counter **starts at a random `random_int(0, 2^31 - 1)`**, drawn once when the row is created,
  and increments by one from there. **Never 0.** The permutation's multiplier and offset are public
  constants, so a fixed start makes the tag sequence identical in every installation: two shops
  sharing one receiving account hand out the same tags and settle each other's orders, and dropping
  the counter table re-issues tags that the installation's own open orders are waiting on. Starting
  no higher than 2^31-1 still leaves ~2.1 billion sequences before exhaustion, so no wraparound is
  needed and `DestinationTagsExhaustedException` stays reachable.
- `ledger_direct_xrpl_destination_tag` **must survive plugin uninstall.** It is the only record that
  a tag was already issued; a fresh counter re-issues tags belonging to orders that are still open.
- The core defines the **schema**: `Core\Xrpl\Schema::tables()` describes both tables as data
  (columns, types, indexes) for a Laravel migration or a Doctrine table, and `Schema::mysql($prefix)`
  is the ready-made DDL for the MySQL-based platforms. The platform creates the tables through its
  own DB layer; the core never references a physical name.

### The testnet is not persistent

The XRPL testnet is reset periodically, which rewinds its ledger index. Rows from a previous epoch
then sit above the live ledger, and a cursor derived from them asks for a range that does not
exist — rippled answers `lgrIdxsInvalid` on every sync, for every order, indefinitely.

**The core must survive that without anyone touching the database.** On `lgrIdxsInvalid`, and only
when a cursor was actually sent, `SyncService` logs a PSR-3 warning and repeats the sync once with
no `ledger_index_min` at all; `findExistingHashes()` keeps that from duplicating anything. Without
a cursor the same error means something else and is not retried.

Testnet rows surviving a reset are kept, because they prove paid orders, and they are **not**
out-ranked by the resync: the cursor is the highest stored ledger index, and the new epoch's rows
sit below the old ones until the new epoch passes them, which takes about as long as the last
epoch lasted. A ledger number alone cannot tell the epochs apart, so the cursor is not derived
from rows "below the live ledger" either; old rows are spread over the range the new epoch is
going through, and a cursor on one of them would skip every payment below it.

With a PSR-16 cache `SyncService` remembers the reset instead: after the resync it stores, under
`ledger_direct.cursor.v1` with chain, network and an account digest, the stale cursor and the
highest ledger index the resync fetched. While the repository still reports that stale cursor,
the sync continues from the remembered ledger and moves it up; the warning and the failing request
happen once per account and reset. A repository that reports any other cursor makes the note
irrelevant, which also covers a node that trimmed history below the cursor. No cache, an evicted
note or a broken cache mean the behaviour before: a failing request, a warning and a full resync
on every sync — never no sync.

## Security

- The issuer is **never** merchant-configurable.
- SSL is always verified on outbound HTTP calls.
- A receiving account is checked by **format and checksum** before it reaches a quote:
  `ChainCatalog::chain($chain)->isValidAccount()` decodes the address
  (`Identity\AccountEncoding`: XRPL base58check, Stellar StrKey with CRC16) and rejects one whose
  checksum does not match. A mistyped character from the right alphabet passes a pattern; a quote
  naming that address sends customers to a destination no wallet will pay. Adapters validate the
  configured account through this method and nothing of their own. It proves neither that the
  account exists nor that the merchant controls it.
