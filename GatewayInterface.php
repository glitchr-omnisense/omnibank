<?php

namespace Omnibank;

use Omnibank\Model\Account;
use Omnibank\Model\Balance;
use Omnibank\Model\BankTransaction;
use Omnibank\Model\ConnectResult;
use Omnibank\Model\Connection;
use Omnibank\Model\Notification;
use Omnibank\Model\Transfer;
use Omnibank\Model\TransferResult;
use Omnibank\Request\Request;
use Omnisense\Contracts\Provider\ProviderInterface;

/**
 * One bank access, configured - statement files, a bank's own API (Qonto) or
 * an aggregator (Powens, Bridge): the same questions for all of them. The
 * application keeps the Connection (serialized) and hands it back on every
 * call; connect() gives it back updated. Each typed method is a shortcut for
 * execute() with its request; a provider that does not do something throws
 * RequestNotSupportedException, and supports() says so beforehand.
 *
 * It is an omnisense/contracts provider: capabilities() lists the operations
 * it supports and its usage policy (Capabilities).
 */
interface GatewayInterface extends ProviderInterface
{
    public function getName(): string;

    public function getTitle(): string;

    /** @param class-string<Request> $request */
    public function supports(string $request): bool;

    /** The operations it supports, and the provider's usage policy. */
    public function capabilities(): Capabilities;

    /**
     * @template T of Request
     *
     * @param T $request
     *
     * @return T answered
     *
     * @throws Exception\RequestNotSupportedException
     * @throws Exception\ProviderException
     */
    public function execute(Request $request): Request;

    /**
     * Opens the access, or renews it: the hosted page to send the user to
     * (null when there is nothing to do) and the connection with its new
     * state (tokens, the provider's user) to keep.
     */
    public function connect(Connection $connection, string $returnUrl): ConnectResult;

    /** @return list<Account> */
    public function accounts(Connection $connection): array;

    /** @return list<Balance> */
    public function balances(Connection $connection, Account $account): array;

    /**
     * The account's booked transactions, oldest first; their amount is
     * negative when money leaves the account.
     *
     * @return list<BankTransaction>
     */
    public function transactions(Connection $connection, Account $account, ?\DateTimeInterface $since = null, ?\DateTimeInterface $until = null): array;

    public function transfer(Connection $connection, Transfer $transfer): TransferResult;

    /**
     * What the provider is telling us (a webhook): checked against its
     * signature, read as one event about one connection.
     *
     * @param array<string, string|string[]> $headers
     *
     * @throws Exception\InvalidNotificationException when the signature does not hold
     */
    public function notify(string $body, array $headers = []): Notification;
}
