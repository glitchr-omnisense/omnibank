# Installation and first calls

```sh
composer require omnisense/omnibank omnibank/files                 # statement files: no network
composer require omnibank/qonto omnibank/powens omnibank/bridge  # a bank's API, an aggregator
```

PHP 8.2 or later.

Omnibank needs no framework. The core requires nothing but `symfony/http-client-contracts`; a
provider that calls an API requires `symfony/http-client`, a library that stands alone;
`omnibank/files` calls nothing and requires neither. It runs the same in plain PHP, in a worker,
in Laravel or Slim, and in Symfony, where a bundle does the wiring ([Symfony](symfony.md)).

## Plain PHP

```php
<?php // bare.php

require __DIR__.'/vendor/autoload.php';

use Omnibank\Files\FilesGatewayFactory;
use Omnibank\Model\Connection;
use Omnibank\Registry;

$registry = new Registry([new FilesGatewayFactory()], [
    'statements' => ['factory' => 'files'],
]);
$gateway = $registry->get('statements');

// What a French bank exports: semicolons, dd/mm/yyyy, a decimal comma, the balance after each line
$csv = <<<'CSV'
Date;Date de valeur;Débit;Crédit;Libellé;Solde
01/09/2026;01/09/2026;-1 234,56;;PRLV SEPA LOYER SCI DES TILLEULS;8 765,44
02/09/2026;02/09/2026;;2 500,00;VIR SEPA RECU /DE NAKAYA SAS /MOTIF SALAIRE;11 265,44
05/09/2026;05/09/2026;-45,90;;PRLV SEPA EDF CLIENT 12345;11 219,54
CSV;
$connection = new Connection(['statements' => [
    ['name' => 'export.csv', 'content' => $csv, 'account' => ['iban' => 'FR7630006000011234567890189', 'name' => 'Compte courant']],
]]);

foreach ($gateway->accounts($connection) as $account) {
    echo $account->name, ' ', $account->iban, "\n";
    foreach ($gateway->balances($connection, $account) as $balance) {
        echo $balance->type, ' ', $balance->amount->decimal(), ' ', $account->currency, ' on ', $balance->at->format('Y-m-d'), "\n\n";
    }
    foreach ($gateway->transactions($connection, $account) as $transaction) {
        printf("%s %9s  %s\n", $transaction->bookedOn->format('Y-m-d'), $transaction->amount->decimal(), $transaction->label);
    }
}
```

```
$ php bare.php
Compte courant FR7630006000011234567890189
booked 11219.54 EUR on 2026-09-05

2026-09-01  -1234.56  PRLV SEPA LOYER SCI DES TILLEULS
2026-09-02   2500.00  VIR SEPA RECU /DE NAKAYA SAS /MOTIF SALAIRE
2026-09-05    -45.90  PRLV SEPA EDF CLIENT 12345
```

(run on 2026-10-05 in an empty directory, after `composer require omnisense/omnibank omnibank/files`:
three packages installed, `symfony/http-client-contracts` the only one that is not Omnibank's; the
account, the names and the amounts are made up)

No key, no network: the statement travels in the connection. That is all there is to it:

- a **factory** per provider package (`FilesGatewayFactory`, `QontoGatewayFactory`,
  `PowensGatewayFactory`, `BridgeGatewayFactory`); one that calls an API takes the HTTP client to
  call with - the application's, a `MockHttpClient` in a test - and with none given makes its own
  (`HttpClient::create()`);
- the **registry**, built by hand from the factories and the gateways' options, by name;
- the **gateways** it gives: `connect()`, `accounts()`, `balances()`, `transactions()`,
  `transfer()`, `notify()` - the same questions for every provider;
- the **connection**, which the application keeps (serialized, as it keeps secrets) and hands
  back on every call.

No class of a framework is loaded on the way - a test of this package checks it in a process of
its own (`Tests/BareTest.php`), and so does `docker compose run --rm omnibank bare`
([harness](harness.md)).

## A bank's API

```php
use Omnibank\Model\Connection;
use Omnibank\Qonto\QontoGatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

$qonto = (new QontoGatewayFactory(HttpClient::create()))->create([
    'login' => getenv('QONTO_LOGIN'),
    'secret_key' => getenv('QONTO_SECRET_KEY'),
]);

$connection = new Connection();                  // Qonto: the key is the access, nothing to keep
foreach ($qonto->accounts($connection) as $account) {
    $qonto->balances($connection, $account);                                          // list<Balance>
    $qonto->transactions($connection, $account, new DateTimeImmutable('-30 days'));   // list<BankTransaction>, oldest first
}
```

In a test, the same factory on a client that answers from files:

```php
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$http = new MockHttpClient(static fn (string $method, string $url) => new MockResponse(file_get_contents(__DIR__.'/organization.json')));
$qonto = (new QontoGatewayFactory($http))->create(['login' => 'example-organization', 'secret_key' => 'qonto_test_not_a_real_key']);
```

## An aggregator: a consent to give, a connection to keep

```php
use Omnibank\Model\Connection;
use Omnibank\Powens\PowensGatewayFactory;
use Omnibank\Registry;

$registry = new Registry([new PowensGatewayFactory($http)], [
    'banks' => ['factory' => 'powens', 'options' => ['domain' => '...', 'client_id' => '...', 'client_secret' => '...']],
]);
$gateway = $registry->get('banks');

$connection = Connection::fromArray($stored ?? []);     // what was kept for this user, if anything
$result = $gateway->connect($connection, 'https://app.example/bank/back');
$store($result->connection->toArray());                 // keep it: the provider's user, its tokens
if ($result->url) {
    header('Location: '.$result->url);                  // the provider's consent page
    exit;
}
```

`$gateway->supports(Request\Transfer::class)` says beforehand what a provider does; what it does
not do is a `RequestNotSupportedException`.

## In a framework

- **Symfony**: `Omnibank\Bridge\Symfony\OmnibankBundle` registers the factories on the
  application's `http_client`, builds the registry from `config/packages/omnibank.yaml` and makes
  each gateway injectable by its name: see [Symfony](symfony.md). Its components
  (`symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel`) are not required by
  this package: a Symfony application has them.
- **Any other**: build the `Registry` once, where the framework builds its services (a service
  provider, a container definition), as the script above does.

## Errors

| Exception | When |
|---|---|
| `RequestNotSupportedException` | the provider does not do that: `supports()` says so beforehand |
| `InvalidConfigException` | a gateway not configured, a factory not installed, an option missing |
| `ProviderException` | the provider refused (wrong credentials, an unknown account) |
| `InvalidNotificationException` | a webhook whose signature does not hold |
| `UnavailableException` | the provider could not be reached or failed (network, timeout, 5xx, 429): **never "no data"** |

All implement `Omnibank\Exception\OmnibankException`.
