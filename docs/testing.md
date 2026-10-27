# How LedgerDirect is tested

One payment flow, five repositories, four shop systems. This page is the map: which layer of
testing lives where, what each layer catches — and, just as important, what it cannot catch — and
where the evidence of a run ends up. The plugin READMEs link here instead of repeating it.

The case catalogue these layers share is [`manual-tests/payment-status.md`](manual-tests/payment-status.md)
(PS-01…PS-11, plus PW-01…PW-04 for the payment page), instantiated per platform in each plugin's
`docs/manual-tests/` or `tests/Manual/`. Same IDs everywhere, so a checklist line in a Shopware PR
means the same as in a WooCommerce PR.

## The layers

| Layer | Where | Runs | Catches | Does not catch |
|---|---|---|---|---|
| **Core unit** | `ledger-direct-core-php`, `vendor/bin/phpunit` | every push; PHP 8.2–8.4 and `--prefer-lowest`; merge gate | the contract itself: `PaymentIntent` schema, settlement and tolerance, the five payment states and their derivation order, plain-decimal amounts, the QR payment request, the sync's choice of transactions | anything a platform does with the result |
| **Core integration** | same repo, `--testsuite integration`, opt-in | by hand after touching an `Oracle` or the Horizon client | the real oracle APIs still answer in the shape the parsers expect; the Stellar testnet spike account still shows its recorded payments and the registry's testnet issuers still exist with Circle's flags | — (not CI-gating: network, rate limits, testnet resets) |
| **Plugin unit** | each plugin | every push; merge gate | presenters and view models (amounts never rounded, QR request, progress share), configuration validation (accent colour, logo path), the plugin's ports against the **real** core services with stubbed edges (HTTP, repository), wiring such as handler identifiers | templates, the database, HTTP |
| **Plugin integration** | each plugin against a real shop in CI (dockware, Flashlight, a built Magento, the WordPress test suite with WooCommerce) | every push; merge gate | persistence of the payment record, order states and their transitions, the status endpoint's payload and refusals, the payment page rendering the markup contract, assets loaded where they should be | the ledger, exchange rates, browsers |
| **Package tests** | `ledger-direct-payment-ui`, `npm test` | every push; `dist/` must be committed | the boundary (no shop system in `src/`), every `data-ld-*` attribute the scripts read is documented, no rounding in the browser, the contract names the harness anchors | that a template actually renders the contract — that is the plugin integration test's job |
| **End-to-end, stage 1** | `ledger-direct-e2e` against the XRPL testnet (and the Stellar testnet with `--chain STELLAR`, since harness PR #10), driven from `e2e.yml` in the plugin repos | nightly and on demand; **not a gate** | drift nothing in a repo caused: testnet resets, node behaviour, faucet, a new core release resolving through `^x.y`, shop images pulled as `latest`; the whole chain shop → core → ledger → shop with real transactions; PS-10's 35-minute wait | browser wallets, layout, accessibility |
| **Manual cases** | PW-01…PW-04 of the catalogue, page weight, accessibility | per PR that touches the payment page; ticked in the PR with order numbers and hashes | Crossmark/GemWallet payments, the network-mismatch hint, the phone layout, the Xaman scan taking over address, tag and amount | everything above, which is automated for a reason |

Two rules hold across all layers. **The core is the reference**: when a plugin and the core
disagree, the core wins, and what the core lacks is a core ticket, not an adapter workaround
(`INVARIANTS.md`). **Nothing rounds in a view**: every amount a customer sees is the core's plain
decimal, and the tests of every plugin assert that the string on the page is the string the core
produced.

## Why end-to-end is not a merge gate

The integration suites gate merges because they are deterministic: a stubbed ledger, a real
database. The end-to-end run pays real testnet money and depends on a faucet and a public node;
a red run can mean the network, not the code. So it runs after the merge — every night, and by
hand before a release — and its job is to notice the world changing under a `main` that has not
changed itself. Stage 2 (a local XRPL network with immediate ledger closes) would make it
deterministic and gate-worthy; it waits on the core (`Handover-E2E-Teststrategie.md`, decision 6).

## Coverage by repository (2026-10-05)

| Repository | Unit | Integration | CI gate | Nightly E2E | Manual cases |
|---|---|---|---|---|---|
| `ledger-direct-core-php` 0.10.0 | 292 (56 of them Stellar) | opt-in (oracles, Horizon testnet) | PHP 8.2–8.4, lowest deps | — | — |
| `ledger-direct-payment-ui` 0.1.1 | 7 (Node) | fixture page | tests, build, `dist` committed | — | fixture in a browser |
| `ledger-direct-shopware6` 1.4.4 | 112 | 10 (dockware 6.7) | shopware-cli validate, PHPUnit | **yes** (`e2e.yml`, dockware) | PS-01…11, PW-01…04 proven on 1.4.0 |
| `ledger-direct-prestashop` 0.5.1 | 47 | 52 (Flashlight 9.0) | syntax 8.2–8.4, CS, PHPStan 9.0/9.1, PHPUnit, release zip | **yes** (`e2e.yml`, Flashlight) | PS-01…11; PW-04 proven |
| `ledger-direct-magento2` 1.1.1 | 104 | smoke | lint, Magento2 CS, PHPUnit on 2.4.7/2.4.8 | no — no running shop in CI yet | PS-01…11; PW-04 proven |
| `ledger-direct-woocommerce` 1.4.0 | with integration: 101 (WP test suite + WooCommerce) | | lint, WPCS, PHPStan, PHPUnit 8.2–8.4, Plugin Check | no — no running shop in CI yet | PS-01…11; PW-04 proven |
| `ledger-direct-e2e` 0.1.0 | 16 (vitest) | — | typecheck, tests | drives the runs | — |

"Proven" means ticked in a PR with order numbers and transaction hashes on the testnet.

**Stellar** (core 0.10.0): 56 unit tests under `tests/Stellar/` run in the
same suite — recorded Horizon answers under `tests/Stellar/fixtures/` stand in for the network, the
registry test compares the issuer constants with the checked-in Circle excerpt *and* Horizon's answers,
`SchemaTest` holds the PostgreSQL system-column rule for the Stellar tables too — plus the opt-in
`tests/Integration/Stellar/HorizonIntegrationTest` against the live testnet. No plugin carries Stellar
yet, so there are no plugin integration rows and no nightly run; the harness is ready for both
(`--chain STELLAR`, memo id, text memo and muxed destinations).

## What the contract is checked against

| Invariant (`INVARIANTS.md`) | Checked by |
|---|---|
| `PaymentIntent` schema v1, plain-decimal accessors | core unit; every plugin's presenter test |
| Settlement: tolerance for XRP, exact currency and issuer for tokens | core unit; plugin integration (short payment, wrong issuer, top-up) |
| Payment status: five states, derivation order, payload keys | core unit; each plugin's status-endpoint test; the harness reads the payload in every case |
| Only `settled` or `redirect` stops polling | package contract test; harness cases PS-03, PS-04, PS-11 |
| Markup contract `data-ld-*` | package tests (documented attributes); plugin integration tests render the page; the harness reads the page through the contract only |
| Payment request behind the QR code | core unit; plugin presenter tests; PW-04 by hand |
| Throttled sync, one node request per receiving account | PS-08 in the harness, platform-specific observable per driver |
| Key knowledge instead of login | PS-06, PS-07 in the harness; status-endpoint tests |
| Stellar identifiers: memo id, muxed destination, canonical text memo all name the order; (hash, op_index) unique | core unit (`Stellar\SyncServiceTest`, `StellarPaymentTest`, `SchemaTest`) against recorded Horizon pages; harness `pay --id` per memo form |
| Stellar settlement: the sum rule and the asset-class rule are the XRPL ones (XLM on a USDC quote is `wrong_asset`, never skipped) | core unit (`Stellar\SyncServiceTest`, cross-class cases mirroring `Xrpl\SyncServiceTest`); PS-04 in the harness once a plugin carries Stellar |
| Stellar registry: issuers never typed, confirmed against Horizon; trust line and issuer flags before a quote | core unit against the checked-in Circle excerpt and Horizon answers; opt-in integration against the live testnet |
| Testnet reset detection from the cursor's ledger bits | core unit (`Stellar\SyncServiceTest`, cursor from the future); harness detects the reset on its side |

## Known gaps

- **Magento and WooCommerce have no nightly run.** Neither CI starts a shop with a web server; the
  plan for both is in `Handover-E2E-Teststrategie.md`.
- **PS-08 is proven by log or marker**, not by counting node requests at a proxy — until stage 2.
- **Browser wallets are manual** (PW-01, PW-02): no test drives a wallet extension.
- **The SEP-7 request behind the Stellar QR code is unverified by a wallet scan.** `Stellar\PaymentUri` is
  pinned by unit tests only; the first adapter proves it the way PW-04 did for XRPL, with `qr-lab` as the tool.
- **The testnet is not persistent.** A reset deletes every account; the harness detects it and asks
  for a new treasury.
- **The Xaman scan (PW-04) is proven on all four platforms** (Shopware 1.4.0, Magento 1.1.0, PrestaShop
  0.5.0 and WooCommerce 1.3.0, XRP and RLUSD each): one `PaymentUri` in the core, every page scanned once.
  PW-01…PW-03 (browser wallets, network hint, phone layout) are proven on Shopware only.
- **PS-04 runs within and across the asset class** since core 0.8.1 and harness PR #5, proven by the
  harness on all four platforms on 2026-10-10; the nightly runs carry it (`--cases all`).
- **A payment on a closed order is invisible until the account syncs again.** The background jobs sync
  the accounts of *open* orders; a shop with no LedgerDirect order waiting shows a stray payment on a
  cancelled order neither in its transaction table nor in the order panel (found with WooCommerce PS-11).
  Follow-up in every plugin: sync the configured receiving account on each run as well.

## Where the evidence lives

- **Pull requests** end with "Manual end-to-end tests": the catalogue IDs as checkboxes, the harness
  writes the lines (`ld-e2e report pr`) with order numbers, hashes and explorer links; manual cases
  are ticked by hand with the same detail. The PR is the record; nothing is collected in a file.
- **Nightly runs** upload `report.json` as an artifact and keep the shop's log on failure.
- **Handover documents** (outside the repositories) carry the implementation notes and learnings of
  each piece of work; this page carries only what stays true.
