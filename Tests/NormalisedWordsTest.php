<?php

namespace Omnibank\Tests;

use Omnibank\Exception\ProviderException;
use Omnibank\Exception\UnavailableException;
use Omnibank\Model\Account;
use Omnibank\Model\AccountKind;
use Omnibank\Model\BankTransaction;
use Omnibank\Model\ConnectionState;
use Omnibank\Model\Consent;
use Omnibank\Model\Money;
use Omnibank\Model\Notification;
use Omnibank\Model\TransactionCategory;
use PHPUnit\Framework\TestCase;

/** The words every provider maps its own onto: account kinds, transaction categories, connection states. */
final class NormalisedWordsTest extends TestCase
{
    public function testAnAccountKeepsTheProvidersWordAndSaysItsKind(): void
    {
        $account = new Account('1', null, null, 'Livret A', 'EUR', 'livret_a', ['type' => 'livret_a'], AccountKind::SAVINGS, ConnectionState::OK);
        self::assertSame('livret_a', $account->type);
        self::assertSame(AccountKind::SAVINGS, $account->kind);
        self::assertSame(ConnectionState::OK, $account->state);

        $bare = new Account('2', null, null, 'Compte', 'EUR', 'whatever');
        self::assertSame(AccountKind::UNKNOWN, $bare->kind, 'an account built as before has no kind');
        self::assertNull($bare->state);
    }

    public function testAccountKindsGroupForADashboard(): void
    {
        self::assertSame('cash', AccountKind::CHECKING->group());
        self::assertSame('cash', AccountKind::CARD->group());
        self::assertSame('savings', AccountKind::TERM_DEPOSIT->group());
        self::assertSame('investment', AccountKind::LIFE_INSURANCE->group());
        self::assertSame('investment', AccountKind::RETIREMENT->group());
        self::assertSame('credit', AccountKind::REVOLVING->group());
        self::assertTrue(AccountKind::CONSUMER_CREDIT->isLiability());
        self::assertFalse(AccountKind::SAVINGS->isLiability());
        self::assertSame(AccountKind::UNKNOWN, AccountKind::of('a word nobody published'));
        self::assertSame(AccountKind::MARKET, AccountKind::of('MARKET'));
        self::assertSame(AccountKind::UNKNOWN, AccountKind::of(null));
    }

    public function testATransactionsCategoryIsOptional(): void
    {
        $line = new BankTransaction('t', new \DateTimeImmutable('2026-10-01'), null, Money::of(-1250, 'EUR'), 'EDF', null, null, null);
        self::assertNull($line->category);

        $line = new BankTransaction('t', new \DateTimeImmutable('2026-10-01'), null, Money::of(-1250, 'EUR'), 'EDF', null, null, null, [], TransactionCategory::HOUSING);
        self::assertSame(TransactionCategory::HOUSING, $line->category);

        self::assertNull(TransactionCategory::of(null));
        self::assertNull(TransactionCategory::of(''));
        self::assertSame(TransactionCategory::UNKNOWN, TransactionCategory::of('astrology'));
        self::assertSame(TransactionCategory::BANK_FEES, TransactionCategory::of('bank_fees'));
    }

    public function testConnectionStatesGroupAndSayWhoActs(): void
    {
        self::assertSame('ok', ConnectionState::PENDING->group());
        self::assertSame('action', ConnectionState::WRONG_CREDENTIALS->group());
        self::assertSame('unavailable', ConnectionState::BANK_UNAVAILABLE->group());
        self::assertSame('error', ConnectionState::UNKNOWN->group());
        self::assertTrue(ConnectionState::ACTION_NEEDED->needsUser());
        self::assertFalse(ConnectionState::BANK_UNAVAILABLE->needsUser());
        self::assertSame(ConnectionState::UNKNOWN, ConnectionState::of('nonsense'));
    }

    public function testAFailureSaysWhatItMeansForTheAccess(): void
    {
        self::assertSame(ConnectionState::WRONG_CREDENTIALS, ConnectionState::ofFailure(new ProviderException('powens', 'Wrong password', 'wrongpass', null, ConnectionState::WRONG_CREDENTIALS)));
        self::assertSame(ConnectionState::BANK_UNAVAILABLE, ConnectionState::ofFailure(new UnavailableException('powens', 'HTTP 503', '503')));
        self::assertSame(ConnectionState::BUG, ConnectionState::ofFailure(new ProviderException('powens', 'No such account', '404')));
        self::assertSame(ConnectionState::BUG, ConnectionState::ofFailure(new \LogicException('?')));
        self::assertSame(ConnectionState::ACTION_NEEDED, ConnectionState::ofFailure(new \RuntimeException('wrapped', 0, new ProviderException('bridge', 'SCA', '1010', null, ConnectionState::ACTION_NEEDED))), 'read through the chain');
    }

    public function testANotificationMaySayTheState(): void
    {
        self::assertNull((new Notification('X', null, Consent::none(), []))->state);
        self::assertSame(ConnectionState::BUG, (new Notification('X', '1', Consent::active(), [], ConnectionState::BUG))->state);
    }
}
