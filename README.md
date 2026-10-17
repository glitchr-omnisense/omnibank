# omniscient/omnibank

One contract for bank access - accounts, balances, transactions, transfers - over statement
files, a bank's own API or an aggregator: the Omnitrade of banking.

Published as `omniscient/omnibank` (formerly `glitchr/omnibank`, which this package replaces: a
`composer.json` that still requires the old name keeps resolving to this package and never installs
both). `omniscient/` is the vendor of every family's core, the providers keep `omnibank/`, the
namespace `Omnibank\` and the GitHub repositories do not change.

```php
$gateway = $registry->get('treasury');

$result = $gateway->connect($connection, 'https://app.example/bank/back');
$connection = $result->connection;          // keep it: the provider's user, its tokens
if ($result->url) { return redirect($result->url); }   // the provider's consent page

foreach ($gateway->accounts($connection) as $account) {
    $gateway->balances($connection, $account);                                  // list<Balance>
    $gateway->transactions($connection, $account, new \DateTimeImmutable('-30 days'));   // list<BankTransaction>
}
$gateway->transfer($connection, $transfer);                     // a TransferResult (a pain.001 file, for files)
$gateway->notify($request->getContent(), $request->headers->all());   // a webhook, checked and read
```

This package holds the contract (`GatewayInterface`, `GatewayFactory`, `Registry`), the models,
the requests and a bridge for Symfony. It needs no framework: it requires nothing but
`symfony/http-client-contracts`; a provider that calls an API requires `symfony/http-client`,
`omnibank/files` neither. Each provider is a package of its own:

| Package | Provider |
|---|---|
| `omnibank/files` | Statement files: CAMT.053, OFX, CSV read; transfers written as pain.001.001.03. No network |
| `omnibank/qonto` | Qonto: accounts, balances, transactions with an API key (unverified) |
| `omnibank/powens` | Powens: bank aggregation through its Webview, CONNECTION_SYNCED webhooks (unverified) |
| `omnibank/bridge` | Bridge API v3: bank aggregation through Connect sessions, item webhooks (unverified) |

## Plain PHP

```sh
composer require omniscient/omnibank omnibank/files
```

```php
require __DIR__.'/vendor/autoload.php';

use Omnibank\Files\FilesGatewayFactory;
use Omnibank\Model\Connection;
use Omnibank\Registry;

$registry = new Registry([new FilesGatewayFactory()], ['statements' => ['factory' => 'files']]);
$gateway = $registry->get('statements');

$connection = new Connection(['statements' => [['name' => 'releve.xml', 'content' => file_get_contents('releve.xml')]]]);
foreach ($gateway->accounts($connection) as $account) {
    foreach ($gateway->transactions($connection, $account) as $transaction) {
        echo $transaction->bookedOn->format('Y-m-d'), ' ', $transaction->amount->decimal(), ' ', $transaction->label, "\n";
    }
}
```

A provider that calls an API takes the HTTP client to call with - the application's, a
`MockHttpClient` in a test - and makes its own when given none:
`new Registry([new QontoGatewayFactory($http)], ['treasury' => ['factory' => 'qonto', 'options' => [...]]])`.
A whole script that runs as it is, and the rest: [docs/installation.md](docs/installation.md).
No class of a framework is loaded on the way: `Tests/BareTest.php` checks it in a process of its
own, and so does `docker compose run --rm omnibank bare`.

## The models

- `Money` - an amount in minor units, **signed**, and its currency: `Money::of(-1250, 'EUR')`,
  `isNegative()`, `decimal()`, `Money::fromDecimal('1234.56', 'EUR')` (read digit by digit).
- `Account` - `id` (the provider's), `iban`, `bic`, `name`, `currency`, `type`, `raw`.
- `Balance` - `amount`, `at`, `type` (`booked`, `available`, `expected`, or the provider's word).
- `BankTransaction` - `id` (stable: the bank's reference, or a fingerprint of the line), `bookedOn`,
  `valueOn`, `amount` (**negative when money leaves the account**), `label`, `counterpartyName`,
  `counterpartyIban`, `reference`, `raw`.
- `Connection` - what the application keeps for one access and hands back on every call: the
  provider's `state` and the `consent` (`Consent`: a `ConsentStatus` - ACTIVE, NEEDS_RENEWAL,
  REVOKED, NONE - and `expiresAt`). `withState()`, `withConsent()`, `toArray()`/`fromArray()` for
  JSON. It holds tokens: store it as you store secrets.
- `ConnectResult` - `url` (the page to send the user to, null when there is nothing to do) and
  the `connection` to keep.
- `Transfer` / `TransferResult` - a SEPA credit transfer, and what became of it (`id`, `status`,
  and the `file`/`fileName` when it is a file to hand the bank).
- `Notification` - a webhook read: `type`, `connectionId`, `consent`, `raw`.

What every provider answers with is kept whole in `$raw`: normalization loses nothing.

## Errors

`RequestNotSupportedException` (the provider does not do that: `supports()` says so beforehand),
`InvalidConfigException` (an option missing), `ProviderException` (the provider refused),
`InvalidNotificationException` (a webhook whose signature does not hold) and
`UnavailableException` - the provider could not be reached or failed (network, timeout, 5xx, 429).
**An `UnavailableException` is never "no data"**: nothing is known, try again later.

## Symfony

In a Symfony application, `Omnibank\Bridge\Symfony\OmnibankBundle` does the wiring: every
`omnibank/*` provider installed registered on the application's `http_client`, `Omnibank\Registry`
autowired, and each configured gateway injectable by its name. Its components (`symfony/config`,
`symfony/dependency-injection`, `symfony/http-kernel`) are not required by this package: the
application has them ([docs/symfony.md](docs/symfony.md)).

```yaml
omnibank:
    gateways:
        statements: { factory: files, options: { debtor_name: 'Nakaya SAS', debtor_bic: AGRIFRPP882 } }
        treasury:   { factory: qonto, options: { login: '%env(QONTO_LOGIN)%', secret_key: '%env(QONTO_SECRET_KEY)%' } }
        banks:      { factory: powens, options: { domain: '%env(POWENS_DOMAIN)%', client_id: '%env(POWENS_CLIENT_ID)%', client_secret: '%env(POWENS_CLIENT_SECRET)%', webhook_secret: '%env(POWENS_WEBHOOK_SECRET)%' } }
```

```php
public function __construct(GatewayInterface $treasury) {}
```

An application's own `GatewayFactoryInterface` is registered too (autoconfigured).

## Docker: every provider with your sandbox keys

`docker/` runs this package with every `omnibank/*` provider installed - from GitHub, or from the
checkouts beside this one when `OMNIBANK_PLUGINS=../..` is set - and a console that exercises
them with the keys in `docker/.env` (copy `.env.dist`). Connections are kept between runs.

```sh
cd docker && cp .env.dist .env
docker compose run --rm omnibank gateways
docker compose run --rm omnibank connect powens                       # prints the Webview to open
docker compose run --rm omnibank accounts powens
docker compose run --rm omnibank transactions qonto --since 2026-09-01
docker compose run --rm -v ~/Downloads:/statements omnibank transactions files --path /statements/releve.xml
docker compose run --rm omnibank bare                                 # plain PHP: no bundle, no container, and what PHP loaded
docker compose run --rm omnibank test                                 # every package's tests
```

## Documentation

- [Installation and first calls](docs/installation.md): plain PHP first
- [Symfony](docs/symfony.md)
- [The Docker harness](docs/harness.md)

## Responsibility

`omniscient/omnibank` and its `omnibank/*` providers are independent, unofficial clients: none is
affiliated with, endorsed by or sponsored by the services it reaches. Each service keeps its own
terms (accounts and contracts, keys, rate limits, what may be done with the banking data they
return), named with a link in the "Responsibility" section of the README of each provider that
reaches one. The MIT licence covers this code only and grants no right to any service or to its
data.

Whoever installs and runs these packages is responsible for complying with those terms, for
obtaining whatever they require, and for any use made of what they return. The authors publish them
free of charge, make no commercial use of them, and accept no liability for how they are used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
