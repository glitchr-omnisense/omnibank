# omnisense/omnibank

Omnibank is one PHP contract for bank access. An application asks every source the same questions
- open the access, which accounts, what do they hold, which lines were booked, send this transfer,
what does this webhook say - and gets the same answers back: `Account`, `Balance`,
`BankTransaction`, `TransferResult`, `Notification`. A source is a package of its own: the
statement files a bank exports (`omnibank/files`), a bank's own API (`omnibank/qonto`), or an
aggregator that reaches many banks after the user's consent (`omnibank/powens`, `omnibank/bridge`).

```php
$gateway = $registry->get('treasury');

$result = $gateway->connect($connection, 'https://app.example/bank/back');
$connection = $result->connection;                     // keep it: the provider's user, its tokens
if ($result->url) { return redirect($result->url); }   // the provider's consent page

foreach ($gateway->accounts($connection) as $account) {
    $gateway->balances($connection, $account);                                          // list<Balance>
    $gateway->transactions($connection, $account, new \DateTimeImmutable('-30 days'));  // list<BankTransaction>, oldest first
}
$gateway->transfer($connection, $transfer);                          // a TransferResult (a pain.001 file, for files)
$gateway->notify($request->getContent(), $request->headers->all());  // a webhook, checked and read
```

The design is Payum's, as in Omnibus and Omnitrade: a provider's **factory** fills a configuration
with its name, its options, its API client and one **action** per request it answers; the
**gateway** it builds runs the action that supports each request; a **registry** holds the
application's gateways by name. The core needs no framework: it requires
`symfony/http-client-contracts` and nothing else. A provider that calls an API requires
`symfony/http-client`, a library that stands alone; `omnibank/files` calls nothing and requires
neither. A Symfony bundle is included ([Symfony](symfony.md)).

Published as `omnisense/omnibank`, formerly `omniscient/omnibank` and before that
`glitchr/omnibank`: the package `replace`s the old names. The namespace is `Omnibank\`; providers
keep the `omnibank/` vendor and the `Omnibank\<Provider>\` namespace.

## When to use it

- An application that reads its own bank accounts - a treasury screen, a reconciliation of invoices
  with what was paid - from more than one bank, or wants to change source without rewriting it.
- Bank statement files dropped by a user (CAMT.053, OFX, CSV), read into the same models as an API.
- SEPA transfers prepared as the pain.001 file a bank's website takes.
- An aggregator's consent flow (a hosted page, a connection to keep, webhooks that say when the
  consent must be renewed).

It is not a payment gateway (charging cards is Omnitrade's) and it stores nothing: keeping the
`Connection` (it holds tokens), the accounts and the transactions is the application's job.

## Providers

The capabilities are what each gateway's `supports()` answers with its required options set (probed
on 2026-10-26).

| Package | Factory name | Source | Connect | Accounts | Balances | Transactions | Transfer | Notify |
|---|---|---|---|---|---|---|---|---|
| [`omnibank/files`](https://github.com/glitchr-omnisense/omnibank-files) | `files` | statement files (CAMT.053, OFX, CSV); transfers as pain.001.001.03 | yes (nothing to open) | yes | yes | yes | yes (a file) | - |
| [`omnibank/qonto`](https://github.com/glitchr-omnisense/omnibank-qonto) | `qonto` | Qonto's Business API, an API key | yes (nothing to open) | yes | yes | yes | - | - |
| [`omnibank/powens`](https://github.com/glitchr-omnisense/omnibank-powens) | `powens` | Powens (ex Budget Insight), its Webview | yes | yes | yes | yes | - | yes |
| [`omnibank/bridge`](https://github.com/glitchr-omnisense/omnibank-bridge) | `bridge` | Bridge API v3 (bridgeapi.io), Connect sessions | yes | yes | yes | yes | - | yes |

Qonto, Powens and Bridge are written from their published documentation and recorded answers: none
has been run against the real service or its sandbox yet ([Verified, and not](verified.md)).

## How this manual is organised

- [Installation](installation.md): requirements, a whole script in plain PHP, a bank's API, an
  aggregator.
- [Symfony](symfony.md) and [Laravel](laravel.md): wiring the registry in a framework.
- [Concepts](concepts.md): gateways, factories, the registry, requests and actions, every model.
- Usage: [connecting and consent](usage-connecting.md), [accounts, balances and
  transactions](usage-reading.md), [transfers](usage-transfers.md), [webhooks](usage-webhooks.md).
- [Configuration reference](configuration.md): the registry, the bundle, every provider's options.
- [Errors](errors.md): the exceptions, when each is thrown, what to catch.
- [Testing](testing.md): testing an application that reads its banks with Omnibank.
- [The Docker harness](harness.md): every provider installed, a console to call them.
- [Writing a provider](providers.md).
- [Verified, and not](verified.md).
