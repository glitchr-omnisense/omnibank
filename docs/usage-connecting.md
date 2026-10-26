---
title: Connecting and consent
order: 30
---

# Connecting and consent

Every call takes a `Connection`: what the application keeps for one bank access. `connect()` opens
the access or renews it, and gives the connection back with its new state.

```php
$result = $gateway->connect($connection, 'https://app.example/bank/back');
$store($result->connection->toArray());     // keep it, as secrets are kept
if ($result->isRedirect()) {
    return redirect($result->url);          // the provider's page: a Webview, a Connect session
}
```

## Three kinds of access

| Provider | What `connect()` does | What the connection holds |
|---|---|---|
| `files` | nothing: no page, the consent set ACTIVE | the statement files themselves (`state.statements`) |
| `qonto` | nothing: no page, the consent set ACTIVE (the API key is the access) | nothing |
| `powens` | creates the Powens user the first time, then gives the Webview's page | `auth_token` (the user's permanent token), `user_id`, and `connection_id` once the application puts it there |
| `bridge` | creates the Bridge user the first time, asks a token, opens a Connect session | `user_uuid`, `external_user_id`, `access_token`, `expires_at`, and `item_id`, `user_email` when the application puts them there |

For `files` and `qonto` a `new Connection()` (or the files in its state) is enough, and `connect()`
can be skipped altogether: no call depends on the consent.

## An aggregator's flow

On a mock client answering as Powens' API does, a whole first connection and a renewal:

```php
<?php // connect.php

require __DIR__.'/vendor/autoload.php';

use Omnibank\Model\Connection;
use Omnibank\Model\Consent;
use Omnibank\Powens\PowensGatewayFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// Powens' answers, as its API gives them (the user's creation, then a temporary code)
$http = new MockHttpClient(function (string $method, string $url) {
    echo '  ', $method, ' ', $url, "\n";

    return new MockResponse(match (true) {
        str_ends_with($url, '/auth/init') => '{"auth_token": "pwns_test_not_a_real_token", "type": "permanent", "id_user": 1987}',
        str_ends_with($url, '/auth/token/code') => '{"code": "tmp_8fR2mWq1ZpL0", "type": "temporary", "access": "single", "expires_in": 1800}',
    });
});
$gateway = (new PowensGatewayFactory($http))->create(['domain' => 'example-sandbox.biapi.pro', 'client_id' => '12345678', 'client_secret' => 'secret', 'language' => 'fr']);

echo "first connect:\n";
$result = $gateway->connect(new Connection(), 'https://app.example/bank/back');
echo '  redirect to ', $result->url, "\n";
echo '  keep ', json_encode($result->connection->toArray()), "\n";

// Back from the Webview with ?connection_id=77, later the consent needs renewal
$kept = $result->connection->withState(['connection_id' => '77'] + $result->connection->state)->withConsent(Consent::needsRenewal());
echo "renewal:\n";
$url = $gateway->connect($kept, 'https://app.example/bank/back')->url;
echo '  redirect to ', $url, "\n";
```

```
$ php connect.php
first connect:
  POST https://example-sandbox.biapi.pro/2.0/auth/init
  GET https://example-sandbox.biapi.pro/2.0/auth/token/code
  redirect to https://webview.powens.com/fr/connect?domain=example-sandbox.biapi.pro&client_id=12345678&redirect_uri=https%3A%2F%2Fapp.example%2Fbank%2Fback&code=tmp_8fR2mWq1ZpL0
  keep {"state":{"auth_token":"pwns_test_not_a_real_token","user_id":1987},"consent":{"status":"none","expires_at":null}}
renewal:
  GET https://example-sandbox.biapi.pro/2.0/auth/token/code
  redirect to https://webview.powens.com/fr/reconnect?domain=example-sandbox.biapi.pro&client_id=12345678&redirect_uri=https%3A%2F%2Fapp.example%2Fbank%2Fback&code=tmp_8fR2mWq1ZpL0&connection_id=77
```

(run on 2026-10-26, PHP 8.4.27, no network: the answers are written as Powens documents them, the
token and the code are made up)

The steps the application owns:

1. **Keep the connection** `connect()` returns, before redirecting: it holds the user's token.
2. **On the return URL**, Powens' Webview adds the new bank connection's `connection_id` to the
   query; Bridge's item id comes with its `item.*` webhooks (`Notification::$connectionId`). Put it
   in the state and mark the consent active:
   `$connection->withState(['connection_id' => $id] + $connection->state)->withConsent(Consent::active())`.
   No provider's `connect()` sets the consent of an aggregator: it stays as it was until the
   application or a webhook says.
3. **When the consent needs renewal** (a webhook said so, or a call was refused), set
   `Consent::needsRenewal()` and call `connect()` again: with `connection_id` (Powens) or `item_id`
   (Bridge) in the state, the page is the one that renews that bank connection; without it, a new
   connection is opened.

The return URL is `connect()`'s second argument; with an empty string, Powens uses its
`redirect_uri` option and Bridge its `callback_url` option, and an `InvalidConfigException` says so
when neither is set. The URL must be whitelisted in the provider's console.

## Consent

`Connection::$consent` is the application's record of where the user's consent stands:

- `ConsentStatus::ACTIVE`, with an `expiresAt` when the provider says (`isActive()` checks it);
- `NEEDS_RENEWAL`: the user must go through the provider's page again;
- `REVOKED`: withdrawn (a Bridge item deleted);
- `NONE`: never given.

Webhooks are where an aggregator says the consent changed ([Webhooks](usage-webhooks.md)): each
`Notification` carries the `Consent` it implies and the provider's `connectionId`, to find the
application's connection and update it.

## Several accesses

One `Connection` per bank access and per user: a Powens user with three banks is one connection
(its `accounts()` lists the accounts of every bank connected); two Qonto organizations are two
gateways (two API keys), each with an empty connection.
