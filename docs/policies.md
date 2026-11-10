---
title: Usage policies and rate limits
order: 37
---

# Usage policies and rate limits

Omnibank is built on [omnisense/contracts](https://github.com/glitchr-omnisense/contracts). Each
provider package declares its **usage policy** as data: rate limits, rights, requirements, and
where they were read. The file is `policy.json` in the package, named by `extra.omnisense.policy`
in its `composer.json`, in the schema of `omnisense/contracts`
(`resources/usage-policy.schema.json`). Run `vendor/bin/omnisense-policies` to print every
installed one.

| Package | Rate limits declared | Rights | What the policy's notes add |
|---|---|---|---|
| omnibank/qonto | 1,000 every 10 seconds and 10,000 every 10 minutes, per IP address | contract | an IP is throttled after 200 answers 401 within an hour; the organization's own access, no PSD2 AIS |
| omnibank/powens | unknown (`null`) | contract | only the sandbox's are published (30 calls a minute, 86,400 a day for the "Default" client type) |
| omnibank/bridge | 3,000 every 5 minutes per application, 60 a minute per user | contract | 10 `/authenticate` per user a minute, 4 Connect Sessions per item in 24 hours, attachments: not counted by the guard |
| omnibank/files | none (`[]`): no remote service | allowed | a transfer file written twice and uploaded twice is two payments |

Read on 2026-11-10 (each policy's `provenance.verifiedAt`, with the pages read).

## PSD2's four accesses a day

Commission Delegated Regulation (EU) 2018/389 (the RTS on strong customer authentication and common
and secure communication), Article 36(5)(b): an account information service provider accesses
the designated payment accounts, "where the payment service user does not actively request such
information, no more than four times in a 24-hour period", unless the AISP and the bank agree on
more. Delegated Regulation (EU) 2022/2360, applied since 25 July 2023, changed Article 10 and added
Article 10a (strong customer authentication renewed every 180 days); it left Article 36(5)
unchanged. The Payment Services Regulation and PSD3 were not yet in the Official Journal on
2026-11-10.

The AISPs here are Powens and Bridge: they schedule the bank synchronisations within the rule.
Omnibank's reads (`accounts()`, `balances()`, `transactions()`) answer from what the aggregator
synced and trigger no access to the bank, so the rule is not one of this package's limits. Qonto's
Business API is the organization's own access with its key, not an AIS.

## Reading and tightening a policy

```php
$registry = new Registry($factories, [
    'treasury' => [
        'factory' => 'qonto',
        'options' => ['login' => '...', 'secret_key' => '...'],
        'policy' => ['rateLimits' => [['max' => 60, 'window' => 'hour', 'operations' => ['transactions']]]],
    ],
], $sharedLimiter);

$registry->policy('treasury');       // Qonto's, tightened by the site's
$registry->policies();               // every gateway's
$registry->get('treasury')->capabilities()->contracts();   // the operations it supports
```

A site may only tighten a policy. A looser limit is an `InvalidPolicyException` from
omnisense/contracts. An operation is `GatewayInterface`'s method name (`connect`, `accounts`,
`balances`, `transactions`, `transfer`, `notify`), plus `registerWebhook`, `fetchWebhooks` and
`deleteWebhook` (`Gateway::OPERATIONS`). A limit counts operations; one operation may be several
HTTP requests (pages of transactions, a new token).

## The guard

`Registry::get()` gives the gateway as before. `Registry::guarded($name)` gives it behind its
policy: before a request is sent, each limit counting its operation is checked. A limit reached
refuses the request with `UsageLimitReachedException`, and nothing is sent. The request is counted
after, answered or failed. A webhook (`notify()`) is never refused by the guard.

A limit with `"scope": "user"` counts per the provider's user the connection names: the factory
says which key of the connection's state holds it (`omnibank.user_key`: Bridge's `user_uuid`,
Powens' `user_id`). A connection with no user yet is a new user, which no limit has counted.

The limiter counts in memory by default. An application served by many processes gives the
registry a shared one (its third argument; in Symfony, `omnibank.limiter`): any
`Omnisense\Contracts\Policy\RateLimiterInterface`.

## A 429, and nothing sent twice

Whether the gateway is guarded or not, each provider's API client honours the waits the provider
asks for:

- **A 429** is a `RateLimitedException`. It is an `UnavailableException`, as a 429 always was here
  ("try again later"), with `providerCode` `429` and `retryAfter` in seconds when the provider said
  (`Retry-After`, or the `RateLimit` field).
- **The next calls** to that provider wait as long. They are refused with
  `UsageLimitReachedException` before anything is sent: in every process when the limiter is
  shared.
- **Nothing retries a call**: neither the guard, the wait, nor the API clients. What writes:
  - Powens: `connect()` creates a Powens user (`POST /auth/init`) the first time. Powens offers
    no idempotency key: a failure after Powens created it leaves an unused user, and a new
    `connect()` creates another.
  - Bridge: `connect()` creates a Bridge user the first time and a Connect session each time.
    Bridge documents no idempotency key; each session counts towards its 4 a day per item.
  - Qonto: read only in this version.
  - Files: a transfer is a pain.001 file, never sent by Omnibank. Each `transfer()` writes a new
    message id: give each transfer its `endToEndId` and upload one file once.

```php
try {
    $transactions = $registry->guarded('treasury')->transactions($connection, $account);
} catch (RateLimitedException|UsageLimitReachedException $e) {
    // Nothing was read: try again in $e->retryAfter seconds.
}
```
