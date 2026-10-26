---
title: Accounts, balances and transactions
order: 31
---

# Accounts, balances and transactions

```php
foreach ($gateway->accounts($connection) as $account) {
    foreach ($gateway->balances($connection, $account) as $balance) {
        // $balance->type: booked, available, expected...; $balance->amount, $balance->at
    }
    foreach ($gateway->transactions($connection, $account, $since, $until) as $transaction) {
        // oldest first; $transaction->amount negative when money left the account
    }
}
```

## Accounts

`accounts()` lists what the connection reaches: the organization's bank accounts (Qonto), every
account of every bank the user connected (Powens, Bridge; deleted ones left out), one account per
IBAN or account number the statement files are about (files). `Account::$id` is what
`balances()` and `transactions()` are asked with; keep the `Account` (or its id and IBAN) rather
than rebuilding one by hand.

## Balances

`balances()` gives what the provider knows of an account now, one `Balance` per type:

| Provider | Types |
|---|---|
| files | the latest of each type the statements give: CAMT.053 `booked` (CLBD), `available` (CLAV), `opening` (OPBD), `interim_booked`, `interim_available`, `forward_available`, `previously_closed`, `opening_available`, `expected`; OFX `booked` (LEDGERBAL), `available` (AVAILBAL); CSV `booked`, the balance column after the latest line |
| qonto | `booked` (`balance_cents`), `available` (`authorized_balance_cents`), at the account's `updated_at` |
| powens | `booked` (`balance`), and `expected` (`balance` + `coming`) when something is coming, at `last_update` |
| bridge | `booked` (`balance`), `instant` (`instant_balance`), at `updated_at` |

A balance is as fresh as the provider's last synchronisation with the bank: an aggregator's
`at` says when that was.

## Transactions

`transactions($connection, $account, $since = null, $until = null)` gives the account's **booked**
lines, oldest first:

- `$since` and `$until` are days, both included (`FetchTransactions::covers()` compares
  `Y-m-d`); either can be null. Providers with an API pass them on (Qonto's `settled_at_from` /
  `settled_at_to`, Powens' `min_date` / `max_date`, Bridge's `since` / `until`) and filter the answer
  again.
- Every page is read: Qonto by `meta.next_page`, Powens by `_links.next.href` (else the next
  offset while pages come full), Bridge by `pagination.next_uri`, up to 1000 pages.
- Pending lines are left out: CAMT entries not `BOOK`, Qonto transactions not `completed`, Powens'
  `coming` and `deleted`, Bridge's `future` and `deleted`.
- `amount` is signed: negative when money left the account (CAMT `DBIT`, Qonto `side: debit`, the
  sign of OFX's `TRNAMT`, Powens' `value` and Bridge's `amount`; a CSV debit column made negative).
- `id` is stable: the provider's id, the bank's reference in a file (`AcctSvcrRef`, `FITID`), or a
  fingerprint of the line, so a line read twice is one transaction.

## Statement files

The files gateway reads the files a connection carries in `state.statements`:

```php
<?php // reading.php

require __DIR__.'/vendor/autoload.php';

use Omnibank\Files\FilesGatewayFactory;
use Omnibank\Model\Connection;

$gateway = (new FilesGatewayFactory())->create();

// Two exports of the same account that overlap on 2026-09-05, and two identical coffees that day
$august = "Date;Libellé;Montant\n28/08/2026;CB CAFE DU COIN;-2,40\n05/09/2026;CB CAFE DU COIN;-2,40\n05/09/2026;CB CAFE DU COIN;-2,40\n";
$september = "Date;Libellé;Montant\n05/09/2026;CB CAFE DU COIN;-2,40\n05/09/2026;CB CAFE DU COIN;-2,40\n12/09/2026;VIR SEPA RECU /DE CAMILLE DURAND;1 250,00\n";
$connection = new Connection(['statements' => [
    ['name' => 'august.csv', 'content' => $august, 'account' => ['iban' => 'FR76 3000 6000 0112 3456 7890 189', 'name' => 'Compte courant']],
    ['name' => 'september.csv', 'content' => $september, 'account' => ['iban' => 'FR7630006000011234567890189']],
]]);

[$account] = $gateway->accounts($connection);
echo $account->id, ' ', $account->name, ' ', $account->currency, "\n";
foreach ($gateway->transactions($connection, $account) as $t) {
    printf("%s %8s %-5s %-34s %s\n", $t->bookedOn->format('Y-m-d'), $t->amount->decimal(), $t->isDebit() ? 'debit' : 'credit', $t->label, substr($t->id, 0, 12).(str_contains($t->id, '#') ? strstr($t->id, '#') : ''));
}
echo count($gateway->transactions($connection, $account, new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-05'))), " between 2026-09-01 and 2026-09-05\n";
```

```
$ php reading.php
FR7630006000011234567890189 Compte courant EUR
2026-08-28    -2.40 debit CB CAFE DU COIN                    23d6b7cf4b67
2026-09-05    -2.40 debit CB CAFE DU COIN                    e4e723b08fb4
2026-09-05    -2.40 debit CB CAFE DU COIN                    e4e723b08fb4#2
2026-09-12  1250.00 credit VIR SEPA RECU /DE CAMILLE DURAND   4bfcaaeca4ea
2 between 2026-09-01 and 2026-09-05
```

(run on 2026-10-26, PHP 8.4.27, no network; the ids are cut to 12 characters by the script)

The two exports are one account (the IBAN, written with or without spaces, is its id), the lines
of the overlap once each, the two coffees of 2026-09-05 two transactions (`<hash>` and
`<hash>#2`, the same in both files). The formats, the CSV column map and every option are on
[omnibank/files](https://github.com/glitchr-omnisense/omnibank-files/blob/1.x/docs/index.md).

## A provider that cannot answer

An `UnavailableException` (a network failure, a timeout, a 5xx, a 429) is **never "no data"**:
nothing is known. Do not read it as an empty list of accounts or transactions; keep what was read
before and try again later ([Errors](errors.md)).
