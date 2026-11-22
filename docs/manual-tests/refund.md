# Manual test cases — refund

The cases every adapter has to be able to demonstrate for refunds (`INVARIANTS.md`, "Refund").
Platform-neutral, like [`payment-status.md`](payment-status.md): which button creates a refund,
what a state is called and where the evidence shows up is the adapter's business. This file is
the list of *what* must hold.

**How adapters use it.** A pull request that touches refunds lists the applicable case IDs as a
checkbox list under "Manual end-to-end tests" and ticks what was actually run, with order numbers
and transaction hashes. The end-to-end harness runs the same IDs.

**Conventions.** "The merchant pays" means: the payment request of the refund is opened in a
wallet and signed there, from the shop's receiving account — LedgerDirect never sends. "Executed"
is the refund's status `executed`; "booked" is whatever the platform does once (an order state, a
note on the order). A "finding" is an entry in the refund's `findings`, shown to the merchant.
Evidence is always something a third person can check: an order number, a refund id, a
transaction hash, a status payload.

**Preconditions for all cases.** As for the payment cases: a shop on the testnet with its **own**
receiving account, whose key the tester (or the harness) holds, because that account pays the
refunds. A funded customer wallet. Where a case needs an account that requires a destination
tag or lacks a trust line, a throwaway account is used, never a shared one.

---

## Back to the sender

### RF-01 — A whole payment goes back

1. Pay an order in full in XRP from the customer wallet.
2. Create a refund of everything to the sender, confirming that it is the customer's own wallet.
3. The merchant pays the request.

Expected: the request names the sender, the exact amount, an `invoiceid` and **no** `dt`. After
the payment is validated the refund is `executed` with that transaction's hash, `matched_by`
`reference` (or `key` for a wallet that drops the reference), no deviation, and booked once. The
order shows as refunded.

Evidence: order number, refund id, both hashes, the refund's status payload.

### RF-02 — Two partial refunds, and no third beyond the rest

1. As RF-01, but refund 40 % and then 60 % of the payment, paying each.
2. Try to create a third refund of the smallest unit.

Expected: both are executed; after the first the platform shows a partial refund, after the second
a full one. The third is refused with `amount_exceeds_refundable`.

### RF-03 — What was paid in excess goes back

1. Pay more than the order asks.
2. Create a surplus return of everything.

Expected: the amount is exactly the excess; more is refused with `amount_exceeds_surplus`. Once
executed the order is **still paid**: a surplus return does not change its state.

### RF-04 — A payment in the wrong asset goes back as it came

1. Pay an order quoted in a token with XRP (or the other way round).
2. Create a return of that payment.

Expected: the refund is in the delivered asset, purpose `wrong_asset_return`; an order refund of
that payment is refused with `purpose_not_allowed`. The order's state does not change.

### RF-05 — The customer no longer holds the trust line

1. Pay an order in a token; then remove the customer's trust line (balance to zero first).
2. Preview a refund.

Expected: refused with `trustline_missing`, naming currency and issuer. Nothing is created.

### RF-06 — The sender requires a destination tag

1. Pay from an account, then set `RequireDest` on it.
2. Preview a refund to the sender, with the confirmation.

Expected: refused with `sender_may_be_shared`, reason `requires_tag`. The confirmation does not
help.

### RF-10 — The payment carried a source tag

1. Pay with a `SourceTag`.
2. Preview a refund to the sender, with the confirmation.

Expected: refused with `sender_may_be_shared`, reason `source_tag`; the tag is shown as a
question to ask the customer. It is not filled in anywhere.

## To an address the customer named

### RF-11 — Address and tag

1. Create a refund to another address with a tag, typing the tag twice.
2. The merchant pays the request.

Expected: the request carries `dt`. Executed only by a payment with that tag. No confirmation is
asked for.

### RF-12 — The tag is left out when paying

1. As RF-11, but pay the amount to the address **without** the tag.

Expected: the refund stays `awaiting_execution` and gets the finding `tag_mismatch`. Nothing is
booked.

### RF-20 — A named address without a tag

1. Create a refund to another address without a tag, without the confirmation.
2. Create it again with the confirmation and pay it.

Expected: the first is refused with `confirmation_required`. The second is created, its request
has no `dt`, and it is executed.

### RF-21 — The sender's own address, typed in

1. Pay with a `SourceTag`. Create a refund to a *named* address that is the sender's, without a
   tag, with the confirmation.
2. Create it again with the source tag as its tag, typed twice, and pay it.

Expected: the first is refused with `sender_may_be_shared` as in RF-10 — typing the address is
not a way around the rule. The second is created and executed.

## What the merchant does differently

### RF-07 — Another amount is paid

1. Create a refund; pay a little less to the same destination.
2. Name that transaction on the refund.

Expected: before step 2 the refund stays open with the finding `amount_mismatch`. After it the
refund is executed with `matched_by` `attached` and the deviation `amount`; requested and
delivered amount both show.

### RF-08 — The same refund twice

1. Create a refund; create a second one to the same destination, tag, asset and amount.

Expected: the second is refused with `duplicate_open_refund`.

### RF-13 — Paid after cancelling

1. Create a refund, cancel it, then pay its request.
2. Name the transaction on the refund.

Expected: the finding `after_cancel`; after step 2 executed with the deviation `after_cancel`,
and booked.

### RF-14 — Paid twice

1. Create a refund and pay its request twice.

Expected: executed by the first payment; the second is the finding `duplicate`. Booked once.

### RF-16 — Paid by hand before the refund existed

1. Send the amount to the customer by hand. Then preview a refund to that destination.
2. Create the refund and name the earlier transaction instead of paying again.

Expected: the preview shows the earlier payment (`paid_before`). After step 2 executed with
`matched_by` `attached`.

## Around the order

### RF-15 — Nobody is looking

1. Create a refund, close the administration, pay the request.
2. Wait for the scheduled task.

Expected: the refund is executed and booked without any page being open.

### RF-17 — Refunded, and the customer pays again

1. Pay part of an order; refund that payment and execute it.
2. Pay the missing part.

Expected: the order is **not** marked paid by itself. The new payment is shown to the merchant.
(The core does not subtract refunds from what counts as paid; see "Known gaps".)

### RF-18 — A cancelled order that was paid

1. Pay an order, cancel the order, then refund the payment.

Expected: the payment is listed and refundable although the order is cancelled.

### RF-22 — A refund is under way and the customer opens the payment page

1. Pay part of an order; create an order refund and leave it open.
2. Open the payment page.

Expected: the page shows that a refund is in progress instead of asking for the rest. After the
refund is cancelled the page asks for payment again.

## Other assets and chains

### RF-09 — Xahau

RF-01 on the Xahau testnet in XAH: the request ends in `network=XAHAUTESTNET`.

### RF-19 — The customer paid through the ledger's exchange

1. Pay an order quoted in a token by spending XRP (`SendMax` in XRP), from an account without
   a trust line for the token.
2. Preview a refund.

Expected: refused with `trustline_missing`; the notice `paid_across_assets` names the asset
spent and the asset received.
