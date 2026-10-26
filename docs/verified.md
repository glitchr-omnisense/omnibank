---
title: Verified, and not
order: 90
---

# Verified, and not

## What the tests prove

Run on 2026-10-26 on the workspace's checkouts (the core and its four providers), PHP 8.4.27,
PHPUnit 11.5.57, no network: 63 tests, 410 assertions, green.

- The core: a gateway gives the page and the connection to keep, transactions kept between the
  dates asked, an unsupported request named with its gateway, required options checked, the registry
  building each gateway once by name and naming an unknown factory (`GatewayTest`); money as signed
  minor units read from decimals without floats, a connection as an array or serialized, a consent
  active until it expires (`ModelTest`); the bundle building the configured gateways, injectable by
  name, registering every installed factory (`Bridge/OmnibankBundleTest`).
- In plain PHP (`BareTest`, `bin/bare --recorded --json`): the registry built by hand, a statement
  read and a provider answering from recorded answers with no class of a framework loaded; the
  bundle does load one.
- omnibank/files: CAMT.053 entry by entry, statements that overlap giving each line once, OFX 1
  (SGML, Windows-1252) and 2 (XML), French CSV exports, a preamble and thousands separators, a
  column map, a date in no format an error; pain.001 files valid against the ISO 20022 schema, with
  and without BICs, several transfers in blocks, refusals before the file.
- omnibank/qonto, omnibank/powens, omnibank/bridge, on answers written from each service's
  documentation (`Tests/Fixtures`): connect (and renewal for the aggregators), accounts and
  balances, transactions every page, booked only, signed; an expired Bridge token renewed; a webhook
  checked then read, a forged one refused; an outage never "no data"; no transfers.

## Run for this manual

On 2026-10-26, PHP 8.4.27, no network, the workspace's checkouts: the plain-PHP script of
[Installation](installation.md#plain-php), `bin/bare --recorded` ([harness](harness.md)), the
bundle's configuration tree and services ([Symfony](symfony.md)), the examples of
[connecting](usage-connecting.md), [reading](usage-reading.md), [transfers](usage-transfers.md),
[webhooks](usage-webhooks.md) and [testing](testing.md), and the `supports()` probe of the
[index](index.md#providers). Their output is pasted as it came.

## What is not verified

- **Qonto, Powens and Bridge against the real services.** Each is written from the service's
  published documentation and recorded answers; none has been run against a real organization, a
  sandbox or a real webhook (each provider's README says so). The webhook signatures of Powens and
  Bridge above all are readings of their documentation, to check against a real call.
- **Real bank files** beyond the fixtures: the CSV defaults are written for the usual French
  exports (Crédit Mutuel, CIC, BNP, Société Générale, LCL, La Banque Postale, Boursorama) and tested
  on samples; another bank's export may need the `csv` options.
- **A pain.001 file uploaded to a bank**: the files validate against the schema; no bank has taken
  one in a test.
- **Laravel**: no bridge; the service provider of [Laravel](laravel.md) is a sketch, not a tested
  package.
- No conformance suite exists for the family yet.
