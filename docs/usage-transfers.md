---
title: Transfers
order: 32
---

# Transfers

`transfer($connection, $transfer)` sends money: a SEPA credit transfer. Of the providers today only
`files` does it, by writing the **pain.001.001.03** file a bank's website takes: the application
hands it to the user (a download) or uploads it where the bank allows. Qonto, Powens and Bridge do
not support `Request\Transfer` in this version: `supports(Request\Transfer::class)` is `false`, and
`transfer()` is a `RequestNotSupportedException`.

```php
<?php // transfer.php

require __DIR__.'/vendor/autoload.php';

use Omnibank\Exception\ProviderException;
use Omnibank\Files\FilesGatewayFactory;
use Omnibank\Model\Connection;
use Omnibank\Model\Money;
use Omnibank\Model\Transfer;

$gateway = (new FilesGatewayFactory())->create(['debtor_name' => 'Nakaya SAS', 'debtor_bic' => 'AGRIFRPP882']);

$result = $gateway->transfer(new Connection(), new Transfer(
    debtorIban: 'FR76 3000 6000 0112 3456 7890 189',
    creditorName: 'Société Générale d\'Électricité',
    creditorIban: 'FR1420041010050500013M02606',
    creditorBic: null,
    amount: Money::fromDecimal('1250.00', 'EUR'),
    reference: 'Facture F-2026-042 — septembre',
    executionDate: new DateTimeImmutable('2026-10-30'),
));
echo $result->status, ' ', $result->fileName, "\n";
echo preg_replace('/OMNIBANK-\d{14}-[0-9A-F]{6}/', 'OMNIBANK-…', $result->file);

try {
    $gateway->transfer(new Connection(), new Transfer('FR7630006000011234567890189', 'X', 'FR0000000000000000000000000', null, Money::of(100, 'EUR'), 'test'));
} catch (ProviderException $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
```

```
$ php transfer.php
generated OMNIBANK-20261026092827-081B1D.xml
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03">
  <CstmrCdtTrfInitn>
    <GrpHdr>
      <MsgId>OMNIBANK-…</MsgId>
      <CreDtTm>2026-10-26T09:28:27</CreDtTm>
      <NbOfTxs>1</NbOfTxs>
      <CtrlSum>1250.00</CtrlSum>
      <InitgPty>
        <Nm>Nakaya SAS</Nm>
      </InitgPty>
    </GrpHdr>
    <PmtInf>
      <PmtInfId>OMNIBANK-…-1</PmtInfId>
      <PmtMtd>TRF</PmtMtd>
      <BtchBookg>false</BtchBookg>
      <NbOfTxs>1</NbOfTxs>
      <CtrlSum>1250.00</CtrlSum>
      <PmtTpInf>
        <SvcLvl>
          <Cd>SEPA</Cd>
        </SvcLvl>
      </PmtTpInf>
      <ReqdExctnDt>2026-10-30</ReqdExctnDt>
      <Dbtr>
        <Nm>Nakaya SAS</Nm>
      </Dbtr>
      <DbtrAcct>
        <Id>
          <IBAN>FR7630006000011234567890189</IBAN>
        </Id>
      </DbtrAcct>
      <DbtrAgt>
        <FinInstnId>
          <BIC>AGRIFRPP882</BIC>
        </FinInstnId>
      </DbtrAgt>
      <ChrgBr>SLEV</ChrgBr>
      <CdtTrfTxInf>
        <PmtId>
          <EndToEndId>OMNIBANK-…</EndToEndId>
        </PmtId>
        <Amt>
          <InstdAmt Ccy="EUR">1250.00</InstdAmt>
        </Amt>
        <Cdtr>
          <Nm>Societe Generale d'Electricite</Nm>
        </Cdtr>
        <CdtrAcct>
          <Id>
            <IBAN>FR1420041010050500013M02606</IBAN>
          </Id>
        </CdtrAcct>
        <RmtInf>
          <Ustrd>Facture F-2026-042 - septembre</Ustrd>
        </RmtInf>
      </CdtTrfTxInf>
    </PmtInf>
  </CstmrCdtTrfInitn>
</Document>
Omnibank\Exception\ProviderException: [files] The creditor's IBAN "FR0000000000000000000000000" does not hold.
```

(run on 2026-10-26, PHP 8.4.27 with `intl`; the script masks the message id, which changes on each
run; the IBANs are examples that pass the check)

## What the file holds

- One `GrpHdr`: the message id (`OMNIBANK-<YmdHis>-<6 hex>`), the creation time, the number of
  transactions and their sum, the initiating party (`initiating_party`, else the debtor's name).
- One `PmtInf` per debtor account and execution date: `TRF`, `BtchBookg` (`batch_booking`), service
  level `SEPA`, the requested execution date (`executionDate`, today when null), the debtor's name
  and IBAN, the debtor's bank (`debtor_bic`, else `Othr/Id NOTPROVIDED`), charges `SLEV`.
- One `CdtTrfTxInf` per transfer: the end-to-end id (`endToEndId`, else the message id), the amount
  in euros, the creditor's bank when `creditorBic` is given, the creditor's name and IBAN, the
  remittance information (`reference`).
- Names (70 characters) and the reference (140) are put in the SEPA character set (`a-z A-Z 0-9
  / - ? : ( ) . , ' +` and space): accents transliterated, dashes and quotes made plain, anything
  else a space.

`TransferResult`: `status` `generated`, `file` (the XML), `fileName` (`<message id>.xml`), `id` the
message id. With no `endToEndId` given, the message id is the end-to-end id too, so the bank's
statement brings it back: a CAMT.053 entry's `reference` (or `raw['endToEndId']`) matches it.

## What is refused before any file

| Case | Exception |
|---|---|
| no `debtor_name` option | `InvalidConfigException` (`The "files" gateway writes transfers with the debtor's name: set its "debtor_name" option.`) |
| a currency other than EUR | `ProviderException` (`A SEPA credit transfer is in euros, not USD.`) |
| an amount of 0 or less | `ProviderException` |
| a debtor or creditor IBAN whose check digits fail | `ProviderException` (`The creditor's IBAN "..." does not hold.`) |
| a malformed BIC (the option's or the transfer's) | `ProviderException` |
| a creditor name empty once in the SEPA character set | `ProviderException` |

## Several transfers in one file

`transfer()` writes one file per call. `Omnibank\Files\Pain001Writer` writes several transfers in
one file, one payment block per debtor account and date:

```php
use Omnibank\Files\Pain001Writer;

$xml = (new Pain001Writer('Nakaya SAS', 'AGRIFRPP882'))->write([$rent, $electricity, $salary], 'PAYROLL-2026-10');
```

Each transfer then gets the end-to-end id `<message id, 29 characters>-<block>-<rank>` unless it
gives its own. The package's tests validate such files against the ISO 20022 schema
(`Tests/Fixtures/pain.001.001.03.xsd`).
