# ledger-direct-core — Follow-ups aus dem WooCommerce-Retrofit (Übergabe)

> **Zweck:** Drei Befunde aus dem WooCommerce-Retrofit (Plugin 1.0.0, live seit 2026-09-07), die **im Core** gehören, weil sie jeden Adapter gleichermaßen treffen. Der WooCommerce-Adapter hat sie lokal umschifft; die Umgehungen stehen unten als Referenz, sind aber Adapter-Workarounds, kein Vertrag. Alles unten ist **am echten Code und an echten Testnet-Daten verifiziert**, nichts geraten.
>
> **Stand des Core:** `master` @ `da5b0bf` = Tag `0.2.0` (Packagist). Der lokale Klon war beim Schreiben aktuell (`git fetch` gemacht). Fundstellen beziehen sich auf 0.2.0.
>
> **Warum die Operation so ist, wie sie ist** — die kurze Version für jeden Punkt steht unter „Warum es heute so ist". Bitte nicht als Bug im Design lesen: Die Entscheidungen waren im Kontext eines einzelnen Adapters auf einem einzelnen Konto korrekt. Sie brechen erst, sobald **mehrere Datenbanken dasselbe Empfangskonto** nutzen oder **das Testnet zurückgesetzt** wird — beides ist in der Praxis passiert.

---

## F1 — Destination-Tags sind über Installationen hinweg identisch

### Was passiert ist (Bestellungen #133 und #135 im WooCommerce-Dev-Shop)

- `DestinationTagService::generateDestinationTag()` (`src/Xrpl/DestinationTagService.php:59`) bildet den Tag **deterministisch** aus der Sequenz: `RANGE_MIN + ((seq * MULTIPLIER + OFFSET) % RANGE_SIZE)` mit `MULTIPLIER = 1836311903`, `OFFSET = 104729`, `RANGE_MIN = 10000`, `RANGE_SIZE = 4294957296`.
- Der Port-Vertrag `XrplTransactionRepositoryInterface::nextDestinationTagSequence()` (`src/Port/XrplTransactionRepositoryInterface.php:29`) schreibt vor: **„starting at 0 the first time this is called for a given account"**. Alle vier Adapter (PrestaShop, Shopware, Magento, WooCommerce vor dem Fix) tun genau das: `INSERT … VALUES (:account, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE sequence = LAST_INSERT_ID(sequence + 1)`.
- Folge: Jede Installation, die dasselbe Empfangskonto nutzt, erzeugt **dieselbe Tag-Folge**: Sequenz 0 → Tag **114729**, Sequenz 1 → **1836426632**, … Auf dem gemeinsamen Testnet-Konto `rJdfC6X2L6tTURK7h214Q3MW3a4RrbzCa8` hatten Shopware/Magento/PrestaShop Tage vorher schon Zahlungen mit genau diesen Tags erhalten.
- Konkret: WooCommerce-Bestellung #133 (Sequenz 0, Tag 114729, 0,82744 XRP angefordert) wurde mit einer **fremden** 0,84-XRP-Zahlung vom Ledger 20451380 „bezahlt" (`SyncService::findTransaction()` liefert die erste Transaktion zum Tag). Bestellung #135 (Sequenz 1, Tag 1836426632, XRP) traf auf eine alte **RLUSD**-Zahlung auf demselben Tag → `PaymentIntent::withFulfillment()` warf `amount_paid must be a float for native asset XRP` (siehe F3).
- Dasselbe passiert bei **Deinstallation + Neuinstallation** eines Adapters, sobald dessen Zähler-Tabelle gedroppt wird: Der Zähler beginnt wieder bei 0 und vergibt Tags, die alte, ggf. noch offene Bestellungen nutzen.

### Warum es heute so ist

Der Zähler-Ansatz (Commit `221a634` „Replace random+retry destination tag generation with counter+permutation") wurde eingeführt, um die Race Condition und den Retry-Loop der zufälligen Tags loszuwerden: Ein atomarer Zähler plus Bijektion ist **innerhalb einer Datenbank** beweisbar kollisionsfrei und O(1). Der Startwert 0 war die naheliegende Festlegung, damit alle Adapter dasselbe tun und die Permutation den vollen Bereich ausschöpft. Die Annahme dahinter: **ein Empfangskonto = eine Datenbank.** Die gilt für einen Händler mit einem Shop, aber nicht für Händler mit zwei Plattformen auf einem Wallet, nicht für Test-Setups, und nicht über eine Neuinstallation hinweg.

### Was der Core ändern sollte

1. **Vertrag anpassen** (`XrplTransactionRepositoryInterface`, `INVARIANTS.md` „Tables"): Der Zähler startet **nicht bei 0**, sondern bei einem **zufälligen Offset**, den der Adapter beim Anlegen der Zähler-Zeile einmalig würfelt. Vorschlag: `random_int(0, 2^31 - 1)` — die Bijektion braucht keinen Start bei 0, und es bleiben ≥ 2,1 Mrd. Sequenzen bis `DestinationTagsExhaustedException` (`DestinationTagService.php:63`, Guard `$sequence >= RANGE_SIZE`). Die Invariante „streng steigend, nie wiederholend pro Konto und Datenbank" bleibt.
2. **Zähler-Tabelle überlebt `uninstall()`** — als Invariante festhalten (PrestaShop macht es schon so, WooCommerce seit 1.0.0 auch, Shopware/Magento prüfen). Begründung im Vertrag: Die Tabelle ist die einzige Garantie, dass ein Tag nie zweimal vergeben wurde.
3. Optional, konzeptionell sauberer: Den Offset **im Core** erzeugen, nicht im Adapter — z. B. `nextDestinationTagSequence()` bleibt 0-basiert, und `DestinationTagService` addiert einen pro Installation gespeicherten Offset. Braucht aber Persistenz für den Offset → neuer Port-Aufruf. Der Adapter-seitige Zufallsstart ist die kleinere Änderung und reicht.

### WooCommerce-Referenz (Workaround, bereits live)

`src/Port/WpdbXrplTransactionRepository.php::nextDestinationTagSequence()`:
```sql
INSERT INTO {prefix}ledger_direct_xrpl_destination_tag (destination_account, sequence)
VALUES (%s, LAST_INSERT_ID(%d))             -- %d = random_int(1, 2^31), 1-basiert
ON DUPLICATE KEY UPDATE sequence = LAST_INSERT_ID(sequence + 1)
```
Rückgabe `LAST_INSERT_ID() - 1`. Test: `tests/Integration/WpdbXrplTransactionRepositoryTest::testSequenceStartsAtARandomOffsetAndIncrementsPerAccount`.

---

## F2 — Sync-Cursor ist global und kennt keinen Testnet-Reset

### Was passiert ist

- `SyncService::syncTransactions()` (`src/Xrpl/SyncService.php:35-55`) holt `getLastSyncedLedgerIndex()` (= `MAX(ledger_index)` über die **gesamte** tx-Tabelle, ohne Konto, ohne Netzwerk) und schickt `ledger_index_min = max + 1` an `account_tx` (`XrplClient.php:49-51`).
- Das XRPL-**Testnet wird periodisch zurückgesetzt**. Die WooCommerce-Dev-DB hatte Zeilen aus der vorherigen Testnet-Epoche mit `ledger_index` 42–45 Mio.; das live Testnet stand bei ~20,5 Mio. Jeder Sync scheiterte mit `XrplRpcException: account_tx on testnet failed: lgrIdxsInvalid` — **dauerhaft**, für jede Bestellung, bis die Zeilen von Hand gelöscht wurden (`DELETE … WHERE ledger_index > <live ledger>`).
- Zweiter Effekt derselben Ursache: Weil der Cursor **nicht pro Netzwerk** ist, verdirbt eine einzige Mainnet-Zeile (Ledger ~100 Mio.) den Testnet-Sync sofort und für immer, und ein Wechsel mainnet→testnet in der Konfiguration ist damit ohne DB-Eingriff unmöglich. Ebenso **nicht pro Konto**: Wechselt der Händler das Empfangskonto, beginnt der Sync des neuen Kontos beim Cursor des alten und verpasst dessen Historie vor diesem Punkt (für offene Bestellungen irrelevant, für Reconciliation/Pro nicht).

### Warum es heute so ist

`getLastSyncedLedgerIndex()` ist 1:1 aus der Shopware-Ground-Truth portiert (`SELECT MAX(ledger_index) FROM ledger_direct_xrpl_tx`, dort schon so). Der Zweck ist reine Effizienz: nicht bei jedem Sync die komplette Kontohistorie paginieren. Ein Shop hat ein Konto und ein Netzwerk, also war „global" gleichbedeutend mit „pro Konto und Netzwerk". Mainnet resettet nie, deshalb ist der Fehler dort nie aufgefallen — er ist ein reines Testnet-/Dev-Phänomen, aber genau da testen Händler zuerst, und ein Händler, dessen Testnet-Sync „einfach nie funktioniert", meldet einen Bug oder gibt auf.

### Was der Core ändern sollte

1. **Port-Signatur:** `getLastSyncedLedgerIndex(string $destinationAccount, string $network): ?string` — der Adapter filtert `WHERE destination = ? AND network = ?`. Das setzt eine **`network`-Spalte** in `ledger_direct_xrpl_tx` voraus (heute nicht im Schema; der CTID kodiert die Network-ID, aber das Parsen ist unnötig kompliziert). → INVARIANTS „Tables": Spalte `network VARCHAR(16) NOT NULL`, Index `(destination, network, ledger_index)`. Schema-Bump für alle Adapter, additiv (WooCommerce: `DB_VERSION` 3, `dbDelta` fügt Spalten hinzu; Bestandszeilen ohne Netzwerk bekommen es aus dem CTID oder werden als „unbekannt" ignoriert).
2. **Recovery nach Reset:** Bei `lgrIdxsInvalid` sollte `SyncService` **nicht** werfen, sondern erkennen, dass der Cursor vor dem aktuellen Ledger liegt, und **ohne `ledger_index_min` neu synchronisieren** (Dedup über `findExistingHashes()` schützt vor Doppeleinträgen). Vorschlag: `XrplRpcException` um einen Fehlercode erweitern; in `syncTransactions()` genau diesen Fall einmal mit `afterLedgerIndex = null` wiederholen und per PSR-3 `warning` loggen („ledger cursor ahead of network, resyncing from genesis"). Alternativ vorher `ledger_current` abfragen und den Cursor gegen `ledger_current_index` klemmen — ein RPC-Call mehr pro Sync, dafür kein Fehlerpfad.
3. In `INVARIANTS.md` festhalten: **Das Testnet ist nicht persistent.** Testnet-Daten in der tx-Tabelle sind nach einem Reset wertlos; der Core muss das überleben, ohne dass jemand die DB anfasst.

### WooCommerce-Referenz

Kein Workaround im Adapter (der Port hat keine Parameter dafür). Die Dev-DB wurde bereinigt. Der PrestaShop-`CLAUDE.md`-Gotcha „Synthetische Testtransaktionen brauchen einen realistischen ledger_index" beschreibt dieselbe Falle von der anderen Seite.

---

## F3 — `findTransaction()` ist nicht asset-bewusst und nimmt „die erste"

### Was passiert ist

- `SyncService::findTransaction()` (`src/Xrpl/SyncService.php:62`) delegiert an `XrplTransactionRepositoryInterface::findTransaction(destination, tag)`, das **eine** Transaktion liefert; alle Adapter nehmen die erste nach Primärschlüssel.
- Ein Tag kann mehrere Transaktionen tragen: eine Fehlzahlung im falschen Asset, eine Zahlung vor der Bestellung (F1), zwei Teilzahlungen. Wird die falsche gewählt, passiert eines von zwei Dingen: Bei **gleicher Asset-Klasse** settelt eine fremde Zahlung die Bestellung (#133), bei **anderer Asset-Klasse** wirft `PaymentIntent::withFulfillment()` → `assertAmountShape()` (`src/Payment/PaymentIntent.php`, „amount_paid must be a float for native asset XRP") — der Sync stürzt ab, die echte Zahlung wird nie angeschaut, die Bestellung bleibt für immer offen (#135).
- `SettlementPolicy` behandelt „falscher Issuer" korrekt (nicht gesettelt, `shortfall()` = voller Betrag), aber nur wenn die Transaktion überhaupt bis dorthin kommt. Der Shape-Mismatch XRP↔Issued Currency kommt nie dort an, sondern explodiert vorher.

### Warum es heute so ist

`findTransaction()` ist die Übersetzung von Shopwares `XrplTxService::findTransaction()` (Zeile ~69 im alten Code: `SELECT * … WHERE destination = ? AND destination_tag = ?`, erstes Ergebnis). Das Modell dahinter: **ein Tag = eine Bestellung = eine Zahlung.** Mit zufälligen Tags in einer Datenbank war das praktisch immer wahr. Mit deterministischen Tags über mehrere Datenbanken (F1) und mit Kunden, die zweimal senden, ist es das nicht. `assertAmountShape()` ist andererseits **richtig** streng — ein XRP-Intent darf keinen Issued-Currency-Betrag tragen. Der Fehler liegt in der Auswahl davor, nicht in der Prüfung.

### Was der Core ändern sollte

1. **Port:** `findTransactions(string $destination, int $destinationTag): XrplTransaction[]` (Plural, neueste zuerst per `ledger_index DESC`), zusätzlich oder statt der Singular-Variante.
2. **`SyncService::findTransactionFor(PaymentIntent $intent): ?XrplTransaction`** (oder in `PaymentIntentService`): wählt aus den Kandidaten die **neueste**, deren `getDeliveredAmount()`-Form zur `amount_requested`-Form des Intents passt (float ↔ float, Array ↔ Array). Andere Kandidaten werden per PSR-3 `warning` geloggt („payment in a different asset class on this tag skipped") und übersprungen; `UnexpectedValueException` aus `getDeliveredAmount()` (`'unavailable'`) ebenfalls loggen und überspringen statt abbrechen. Ein Issued-Currency-Kandidat mit **falschem Issuer** wird **nicht** übersprungen — der gehört zur `SettlementPolicy`, die ihn als „wrong asset" ausweist (Magento zeigt dafür `wrongTokenNotice`).
3. Optional: Zeitfenster. Ein Kandidat, dessen Ledger-`date` **vor** der Quote liegt, ist nie „die" Zahlung. Der Intent kennt heute nur `expiry`; `expiry − quoteExpirySeconds` ist eine Näherung. Sauberer wäre ein `quoted_at`-Feld im Schema — das wäre aber ein Schema-v2-Thema und ist mit „neueste passende Form" für die Praxis abgedeckt.
4. `INVARIANTS.md` „Settlement" ergänzen: **Welche** Transaktion einen Intent erfüllt, ist Core-Entscheidung (wie das Ob), nicht Adapter-Sache.

### WooCommerce-Referenz (Workaround, bereits live)

- `src/Port/WpdbXrplTransactionRepository.php::findTransactionsByTag()` — adapter-eigene Methode außerhalb des Ports, `ORDER BY ledger_index DESC, id DESC`.
- `src/Service/OrderTransactionService.php::findPaymentFor(PaymentIntent)` — die Auswahlregel aus Punkt 2, 1:1 übertragbar.
- Tests: `tests/Integration/OrderTransactionServiceTest::testAStrayPaymentInAnotherAssetClassIsSkippedInFavourOfTheRealOne`, `…::testAPaymentFromTheWrongIssuerNeverSettlesAStablecoinOrder`.

---

## Reihenfolge und Versionierung

- **F3 zuerst** (Patch/Minor, keine Schema-Änderung, additiver Port-Methode `findTransactions()` mit Default-Implementierung? — Interfaces in PHP haben keine Defaults; also Minor **0.3.0** mit Breaking im Port, oder das Plural als neues Interface `XrplTransactionLookupInterface`, das die Adapter zusätzlich implementieren). Behebt den Absturz.
- **F1** (Vertragstext + INVARIANTS, keine Code-Änderung im Core nötig, wenn der Offset im Adapter bleibt) — kann mit F3 in 0.3.0.
- **F2** (Schema-Spalte `network`, Port-Signatur, Reset-Recovery) — größer, **0.4.0**; alle vier Adapter brauchen eine Migration. WooCommerce ist live, dort wieder über `LedgerDirectInstall::maybe_upgrade()` (`DB_VERSION` 3).
- Nach jedem Core-Release: Constraint in allen Adaptern anheben, Adapter-Workarounds (F1-Offset, F3-`findPaymentFor`) durch die Core-Variante ersetzen, damit die Logik wieder an einer Stelle liegt.

## Reproduzieren

- **F1/F3:** Zwei frische Datenbanken (oder eine DB, Zähler-Zeile löschen) gegen dasselbe Testnet-Konto; in beiden eine Bestellung anlegen → gleicher Tag. In DB A eine RLUSD-Zahlung auf den Tag schicken, in DB B eine XRP-Bestellung mit demselben Tag syncen → Exception aus `assertAmountShape()`.
- **F2:** In `ledger_direct_xrpl_tx` eine Zeile mit `ledger_index` = live-Ledger + 10 Mio. einfügen, syncen → `lgrIdxsInvalid` bei jedem Aufruf. Live-Ledger: `POST https://s.altnet.rippletest.net:51234/ {"method":"ledger_current"}`.
- Für alle Tests einen **frischen Faucet-Account** als Ziel nehmen (`POST https://faucet.altnet.rippletest.net/accounts`), nie eine Issuer-Adresse (paginiert minutenlang).

## Referenzen

- WooCommerce-Adapter: `github.com/ledger-direct/ledger-direct-woocommerce` `main` (1.0.0), PRs #10 (Retrofit, enthält den `fix(sync)`-Commit `5d9f16b` mit F1/F3-Workarounds) und #12 (PHP-Scoper).
- Handover dort: `LedgerDirect-WooCommerce-Core-Retrofit.md` (liegt im Docker-Harness-Verzeichnis `Wordpress/wordpress-docker-compose-master/`, nicht im Repo), Abschnitt „Fund beim manuellen Test".
- Core-Fundstellen (0.2.0): `src/Xrpl/DestinationTagService.php`, `src/Xrpl/SyncService.php`, `src/Xrpl/XrplClient.php:36-70`, `src/Port/XrplTransactionRepositoryInterface.php`, `src/Payment/PaymentIntent.php::assertAmountShape()`, `INVARIANTS.md` „Tables"/„Settlement".
