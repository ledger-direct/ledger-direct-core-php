# Manual test cases — payment status

The cases every adapter has to be able to demonstrate for the payment-status contract
(`INVARIANTS.md`, "Settlement" and "Payment status"). They are platform-neutral on purpose:
what a state is called on a platform, which button or command triggers a step, and where the
evidence shows up is the adapter's business and documented there (for example
`tests/Manual/payment-status.md` in the Shopware plugin). This file is the list of *what* must
hold, so four platforms prove the same behaviour and not four slightly different ones.

**How adapters use it.** A pull request that touches the payment page, the status endpoint,
matching or settlement lists the applicable case IDs as a checkbox list under
"Manual end-to-end tests" and ticks what was actually run. Results are not collected here; the
PR is the record. Should a case ever be automated (a browser test, a CI gate on merge), it keeps
its ID.

**Conventions.** "Quoted asset" is the asset the order was quoted in; "receiving account" the
merchant's XRPL account the intent names; "open" is any platform state in which the order still
waits for money on the ledger. Evidence is always something a third person can check: an order
number, a transaction hash (look it up on the testnet explorer), a log line verbatim.

**Preconditions for all cases.** A shop on the XRPL testnet with its **own** receiving account —
never one shared with another shop (shared tag space, see the destination-tag invariant), never
an issuer account — with trust lines to the stablecoins under test, and a funded customer wallet.
The quote validity is the platform's default unless a case says otherwise.

---

## PS-01 — Waiting

*Contract: state `waiting`, `seconds_left`.*

1. Place an order in any quoted asset. Send nothing.
2. Open the payment page. Keep it open for at least two poll intervals.

Expected: the page shows the amount, receiving account and destination tag, and a countdown that
runs down from the quote's validity. The status endpoint answers `state: waiting` with a
positive `seconds_left`, `amount_paid` and `shortfall` null, no `redirect`. The page keeps
polling at its interval. The platform's transaction stays in an open state.

Evidence: order number; two consecutive status payloads with decreasing `seconds_left`.

## PS-02 — Expired, then refreshed

*Contract: state `expired`; "a quote expires at its expiry"; refresh keeps account and tag.*

1. Set the quote validity to its minimum (Shopware: 60 s). Place an order. Send nothing.
2. Wait past the validity with the page open.
3. Use the page's refresh action.

Expected: at expiry the countdown block gives way to the expired notice with the warning not to
send the old amount, and a refresh action; the status endpoint answers `state: expired`,
`seconds_left` null, and keeps polling (a late payment must still be noticed). After the refresh
the page shows a new amount and validity with the **same** receiving account and destination tag.

Evidence: order number; destination tag before and after; both amounts.

## PS-03 — Partial, then topped up

*Contract: state `partial`, `amount_paid` is the **sum**, `shortfall`; `paid_partially`-style
platform state; only a redirect stops polling.*

1. Place an order in the **native** asset (XRP). Send clearly less than the amount minus the
   tolerance (0.15 %): for example half.
2. Wait for the page to notice, without reloading it by hand.
3. Send the shortfall the page names, to the same account and tag.

Expected after step 2: the partial notice appears **without a page reload**, naming what arrived
and what is still due; the status endpoint answers `state: partial` with `amount_paid` and
`shortfall` adding up to `amount_requested`; the platform's transaction moves to its partially
paid state **without the customer leaving the page** (the merchant sees it). After step 3: the
page leaves via `redirect`, the transaction is paid, and the stored intent carries the hash of
the **second** transaction with the summed amount.

Evidence: order number; both transaction hashes; the two status payloads; the merchant-side
state after step 2.

## PS-04 — Wrong asset, then the right one

*Contract: state `wrong_asset`, `amount_paid` is the **delivered** asset, `shortfall` the whole
request; SettlementPolicy "Issued currency exact".*

1. Place an order quoted in one stablecoin (USDC). Pay the full amount in the other (RLUSD),
   same account and tag.
2. Wait for the page to notice.
3. Send the quoted asset in full.

Expected after step 2: the wrong-asset notice names the delivered amount and the full requested
amount; `state: wrong_asset`, `amount_paid` carries the delivered currency and issuer,
`shortfall` the quoted currency and issuer with the whole value; nothing is credited; the
transaction moves to the platform's partially paid state (money is there, nothing counts). After
step 3: `redirect`, paid, and the intent's hash is the quoted-asset transaction, not the stray.

Evidence: order number; both hashes; the `wrong_asset` payload verbatim.

## PS-05 — Settled

*Contract: state `settled`, the only terminal state; "the amount the page asks for settles".*

1. Place a **small** order (around 1.00 in shop currency: rounding errors only exceed the
   tolerance on small amounts) in the native asset.
2. Send **exactly** the amount shown on the page, to account and tag.

Expected: the page leaves via `redirect` to the platform's order confirmation; the status
endpoint answers `state: settled` with `amount_paid` and no `shortfall`; the transaction is paid
with **exactly one** transition to that state in its history (the status path and the
platform's return path must not both set it).

Evidence: order number; hash; the state history.

## PS-06 — Guest, key knowledge instead of login

*Contract: "Key knowledge, not account membership."*

1. Place an order as a **guest** (no account). Note the payment link from the confirmation
   mail, or the URL of the payment page.
2. Open that link in a private browser window (no session).
3. Open the status endpoint URL the page polls, in the same window.

Expected: the payment page renders; the status endpoint answers 200 with the contract payload.
No login prompt at any point.

Evidence: order number; the URL used; the HTTP status of the poll.

## PS-07 — Wrong key is refused without a hint

*Contract: "a guessed key reveals nothing the payment page doesn't show already."*

1. Take the status endpoint URL from PS-06 and alter the key (and, separately, the order id).
2. Call both, without a session.

Expected: both are refused with the same status (403) and an answer that does not say whether
the order exists. The platform's own session for another customer does not open it either.

Evidence: the two HTTP responses.

## PS-08 — Throttling

*Contract: "Throttling is mandatory", `MIN_SYNC_INTERVAL_SECONDS` (5 s), per receiving account.*

1. Two orders open on the same receiving account, two payment pages open (two browsers).
2. Observe the node requests over one interval — from the adapter's log, a request counter, or
   the node's access log.

Expected: within one interval, however many pages poll, the receiving account is synced
**once**; every poll still answers with the full payload in the same shape.

Evidence: the request count per interval and how it was measured.

## PS-09 — Safety net without a browser

*Contract: settlement must not depend on the customer watching the page (the adapter's cron,
scheduled task or equivalent).*

1. Place an order. Close the payment page.
2. Send the full amount.
3. Trigger the adapter's safety net (cron URL, scheduled task, worker).

Expected: the transaction is paid without any page being open and without the customer
returning. With several open orders on one receiving account the safety net makes **one** node
request per account and network, not one per order.

Evidence: order number; hash; the safety net's own log line or result payload.

## PS-10 — Late return after the platform's payment session expired

*Contract: settlement is decided on the ledger, not on the platform's return trip.*

1. Place an order. Leave the page open, but do **not** pay until the platform's payment session
   has expired (Shopware: the payment token, 30 minutes — wait 35).
2. Pay the full amount.

Expected: the status endpoint sets the paid state itself; the `redirect` goes somewhere that
works (the order page, not the platform's "session expired" error); no error page, no order left
open on a paid ledger.

Evidence: order number; hash; the `redirect` URL from the payload.

## PS-11 — Closed by the merchant

*Contract: `redirect` "as soon as the order no longer waits", whatever closed it — the contract
has no state for a manual close.*

1. Place an order. Send nothing. Keep the page open.
2. In the back office, cancel the transaction (or mark it paid by hand).

Expected: the next poll carries a `redirect` although the contract state is still `waiting` or
`expired`; the page leaves. A later payment to the tag does **not** reopen or change the
transaction the merchant closed.

Evidence: order number; the payload with `redirect` and its `state`.

---

## Coverage per platform

| Case | PrestaShop | Shopware | WooCommerce | Magento |
|---|---|---|---|---|
| PS-01 … PS-11 | PR #9, #10 | PR #16, #17 (`tests/Manual/payment-status.md`) | — | PR #11 (`docs/manual-tests/payment-status.md`) |

Fill in the PR that demonstrated the cases; a dash means the adapter does not implement the
contract yet.
