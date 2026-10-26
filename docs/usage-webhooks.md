---
title: Webhooks
order: 33
---

# Webhooks

An aggregator calls the application back when it has synchronised a bank connection, or when the
user must act. `notify($body, $headers)` checks the call's signature and reads it as one
`Notification`: the provider's event `type`, the `connectionId` it is about, and the `Consent` it
implies. Powens and Bridge support it; files and Qonto have no webhooks
(`RequestNotSupportedException`).

```php
#[Route('/bank/webhook', methods: ['POST'])]
public function __invoke(Request $request, GatewayInterface $banks): Response
{
    try {
        $notification = $banks->notify($request->getContent(), $request->headers->all());
    } catch (InvalidNotificationException) {
        return new Response(status: 400);
    }
    $connection = $this->connections->findByProviderId($notification->connectionId);  // the application's
    $this->connections->save($connection->withConsent($notification->consent));
    // NEEDS_RENEWAL: ask the user to go through connect() again; ACTIVE: read the fresh data

    return new Response(status: 204);
}
```

Give the **raw body** (the signature is over its bytes, before any JSON decoding) and the request's
headers (any case; a list of values is read by its first).

## Both providers, end to end

```php
<?php // webhooks.php

require __DIR__.'/vendor/autoload.php';

use Omnibank\Bridge\Api as BridgeApi;
use Omnibank\Bridge\BridgeGatewayFactory;
use Omnibank\Exception\InvalidNotificationException;
use Omnibank\Powens\Api as PowensApi;
use Omnibank\Powens\PowensGatewayFactory;
use Symfony\Component\HttpClient\MockHttpClient;

$http = new MockHttpClient([]);   // notify() calls nothing

// Bridge: BridgeApi-Signature "v1=<HMAC-SHA256 of the body, hex>"
$bridge = (new BridgeGatewayFactory($http))->create(['client_id' => 'id', 'client_secret' => 'secret', 'webhook_secret' => 'whsec_bridge']);
$body = '{"content":{"item_id":8675309,"status":1010,"status_code_info":"SCA_REQUIRED"},"timestamp":1759262400,"type":"item.refreshed"}';
$notification = $bridge->notify($body, ['BridgeApi-Signature' => 'v1='.BridgeApi::sign($body, 'whsec_bridge')]);
printf("bridge: %s, item %s, consent %s\n", $notification->type, $notification->connectionId, $notification->consent->status->value);

// Powens: BI-Signature, base64 of the HMAC-SHA256 of "POST.<path>.<BI-Signature-Date>.<body>"
$powens = (new PowensGatewayFactory($http))->create(['domain' => 'example-sandbox.biapi.pro', 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_secret' => 'whsec_powens', 'webhook_url' => 'https://app.example/bank/webhook']);
$body = '{"connection":{"id":77,"state":"SCARequired","expire":"2026-12-01 00:00:00"}}';
$date = 'Wed, 30 Sep 2026 22:15:03 GMT';
$signature = PowensApi::signatures($body, 'whsec_powens', $date, 'https://app.example/bank/webhook')[0];
$notification = $powens->notify($body, ['BI-Signature' => $signature, 'BI-Signature-Date' => $date]);
printf("powens: %s, connection %s, consent %s until %s\n", $notification->type, $notification->connectionId, $notification->consent->status->value, $notification->consent->expiresAt->format('Y-m-d'));

try {
    $bridge->notify($body, ['BridgeApi-Signature' => 'v1=00ff']);
} catch (InvalidNotificationException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
```

```
$ php webhooks.php
bridge: item.refreshed, item 8675309, consent needs_renewal
powens: CONNECTION_SYNCED, connection 77, consent needs_renewal until 2026-12-01
Omnibank\Exception\InvalidNotificationException: [bridge] The notification is unsigned, or its signature does not match.
```

(run on 2026-10-26, PHP 8.4.27, no network; the bodies follow each provider's documentation, the
secrets are made up)

## What each provider reads

| | Powens | Bridge |
|---|---|---|
| Signature header | `BI-Signature`, with `BI-Signature-Date` | `BridgeApi-Signature` |
| Accepted | base64(HMAC-SHA256(secret, `POST.<webhook_url's path>.<date>.<body>`)) when `webhook_url` and the date are known; else, or also, base64 or hex HMAC-SHA256 of the body alone | `v1=<hex HMAC-SHA256 of the body>`, several comma-separated (a secret being rotated), the hex in either case |
| Options | `webhook_secret`, `webhook_url` | `webhook_secret` |
| `type` | the body's `type` or `event`, else `CONNECTION_SYNCED` when the body has a `connection`, else `UNKNOWN` | the event's `type` (`item.created`, `item.refreshed`...) |
| `connectionId` | `connection.id` | `content.item_id` |
| `consent` | from `connection.state` (or `error`): `SCARequired`, `webauthRequired`, `decoupled`, `additionalInformationNeeded`, `actionNeeded`, `wrongpass`, `passwordExpired` are NEEDS_RENEWAL, any other ACTIVE; `connection.expire` is `expiresAt`. No `connection`: NONE | `item.*` events only: a status 402, 429, 430, 1010 or 1100 is NEEDS_RENEWAL (without a numeric status, words of `status_code_info` such as SCA, credential, password); an `item.*.deleted` (not an account's) REVOKED; any other ACTIVE. Other events: NONE |

Both signature readings are written from the providers' documentation and have not been checked
against a real webhook ([Verified, and not](verified.md)).

> [!WARNING]
> Known defect: neither provider checks how old a webhook is (Powens' `BI-Signature-Date`, Bridge's `timestamp` are not compared with the time), where `InvalidNotificationException` says a webhook "too old" is refused: a captured webhook can be replayed. Powens also accepts the HMAC of the body alone, which covers no date at all.

> [!WARNING]
> Known defect: Powens compares a signature in lower case too (`strtolower` on both sides), meant for hex, but applied to the base64 signatures as well: a base64 signature is accepted whatever the case of its letters.

## Errors

| Case | Exception |
|---|---|
| no `webhook_secret` option | `InvalidNotificationException` (`No webhook secret: the notification cannot be checked.`) |
| no signature header, or a signature that does not match | `InvalidNotificationException` |
| a signed body that is not JSON (Powens), or not a Bridge event (no `type`) | `ProviderException` |

`InvalidNotificationException` extends `ProviderException`: catch it first, and answer such a
call with a 4xx.
