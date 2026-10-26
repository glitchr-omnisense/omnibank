---
title: Testing
order: 60
---

# Testing

Omnibank has no fake gateway and no conformance suite: an application tests its code against the
real gateways, on recorded answers.

## The files gateway as a test double

`omnibank/files` calls nothing: a test hands it a statement (CAMT.053, OFX or CSV) in the
connection and gets real `Account`, `Balance` and `BankTransaction` objects back. For code that
reconciles payments, imports transactions or shows balances, it is the simplest double:

```php
use Omnibank\Files\FilesGatewayFactory;
use Omnibank\Model\Connection;

$gateway = (new FilesGatewayFactory())->create();
$connection = new Connection(['statements' => [
    ['name' => 'export.csv', 'content' => "Date;Libellé;Montant\n02/09/2026;VIR SEPA RECU /DE CAMILLE DURAND /MOTIF F-2026-042;1 250,00\n", 'account' => ['iban' => 'FR7630006000011234567890189']],
]]);

$reconciler->reconcile($gateway, $connection);    // the application's code under test
```

In a Symfony test environment, a gateway can be pointed at the files factory under the same name:

```yaml
when@test:
    omnibank:
        gateways:
            treasury: { factory: files }
```

## An API's answers: `MockHttpClient`

Every factory takes the HTTP client to call with. A `Symfony\Component\HttpClient\MockHttpClient`
answering with the provider's JSON tests the real gateway without the network:

```php
use Omnibank\Model\Connection;
use Omnibank\Qonto\QontoGatewayFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$http = new MockHttpClient([
    new MockResponse(file_get_contents(__DIR__.'/Fixtures/qonto-organization.json')),
]);
$qonto = (new QontoGatewayFactory($http))->create(['login' => 'example-organization', 'secret_key' => 'qonto_test_not_a_real_key']);

$accounts = $qonto->accounts(new Connection());
```

The providers' own `Tests/Fixtures/` hold answers written from each service's documentation
(`organization.json` and `transactions-page-*.json` for Qonto, `auth-init.json`, `accounts.json`,
`webhook-connection-synced.json`... for Powens, `user.json`, `connect-session.json`,
`webhook-item-refreshed.json`... for Bridge); the harness keeps Qonto's and a CAMT.053 statement
in `docker/harness/recorded/`. Copy them as a starting point.

Failures are tested the same way: a `MockResponse` with `['http_code' => 503]` is an
`UnavailableException`, `['http_code' => 401]` and a JSON error a `ProviderException`,
`['error' => '...']` (a transport failure) an `UnavailableException`:

```php
<?php // errors.php

require __DIR__.'/vendor/autoload.php';

use Omnibank\Exception\OmnibankException;
use Omnibank\Model\Connection;
use Omnibank\Qonto\QontoGatewayFactory;
use Omnibank\Request\Transfer;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$answers = [
    'a 503' => new MockResponse('{"message":"Service Unavailable"}', ['http_code' => 503]),
    'a 401' => new MockResponse('{"errors":[{"code":"unauthorized","detail":"Invalid credentials"}]}', ['http_code' => 401]),
    'no network' => new MockResponse('', ['error' => 'Could not resolve host: thirdparty.qonto.com']),
];
foreach ($answers as $case => $answer) {
    $qonto = (new QontoGatewayFactory(new MockHttpClient($answer)))->create(['login' => 'example-organization', 'secret_key' => 'not-a-real-key']);
    try {
        $qonto->accounts(new Connection());
    } catch (OmnibankException $e) {
        printf("%-10s %s (%s)\n", $case, $e::class, $e->getMessage());
    }
}

$qonto = (new QontoGatewayFactory(new MockHttpClient([])))->create(['login' => 'x', 'secret_key' => 'y']);
var_dump($qonto->supports(Transfer::class));
try {
    (new QontoGatewayFactory())->create(['login' => 'x']);
} catch (OmnibankException $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
```

```
$ php errors.php
a 503      Omnibank\Exception\UnavailableException ([qonto] Qonto answered HTTP 503: try again later.)
a 401      Omnibank\Exception\ProviderException ([qonto] Invalid credentials)
no network Omnibank\Exception\UnavailableException ([qonto] Qonto cannot be reached: Could not resolve host: thirdparty.qonto.com)
bool(false)
Omnibank\Exception\InvalidConfigException: The "qonto" gateway needs: secret_key.
```

(run on 2026-10-26, PHP 8.4.27, no network)

## Webhooks in a test

`Omnibank\Bridge\Api::sign($body, $secret)` and `Omnibank\Powens\Api::signatures($body, $secret,
$date, $webhookUrl)` compute the signature each gateway accepts: a test signs a body with the
secret of its configuration and posts it to the application's webhook route
([Webhooks](usage-webhooks.md#both-providers-end-to-end)).

## A gateway of the test's own

A class implementing `GatewayInterface` (or a `GatewayFactory` whose actions answer from arrays)
can stand in for any provider; the core's own tests do it with `Tests/StubFactory.php`. Registered
in a `Registry` under the name the application uses, it replaces the real one.
