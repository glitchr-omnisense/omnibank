<?php

namespace Omnibank;

use Omnibank\Action\ActionInterface;
use Omnibank\Exception\ProviderException;
use Omnibank\Exception\RequestNotSupportedException;
use Omnibank\Http\Redaction;
use Omnibank\Model\Account;
use Omnibank\Model\ConnectResult;
use Omnibank\Model\Connection;
use Omnibank\Model\Notification;
use Omnibank\Model\Transfer;
use Omnibank\Model\TransferResult;
use Omnibank\Request;

/** A provider's actions behind one door: the first action supporting a request answers it. */
final class Gateway implements GatewayInterface
{
    /** @param ActionInterface[] $actions */
    public function __construct(
        private readonly string $name,
        private readonly string $title,
        private readonly array $actions,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function supports(string $request): bool
    {
        $probe = (new \ReflectionClass($request))->newInstanceWithoutConstructor();
        foreach ($this->actions as $action) {
            if ($action->supports($probe)) {
                return true;
            }
        }

        return false;
    }

    public function execute(Request\Request $request): Request\Request
    {
        foreach ($this->actions as $action) {
            if ($action->supports($request)) {
                try {
                    $action->execute($request);
                } catch (\Throwable $e) {
                    // The request holds the connection, its tokens with it: no trace of the failure keeps it in its arguments.
                    throw Redaction::withoutArguments($e);
                }
                if (!$request->isAnswered()) {
                    throw Redaction::withoutArguments(new ProviderException($this->name, \sprintf('%s left the request unanswered.', $action::class)));
                }

                return $request;
            }
        }

        throw Redaction::withoutArguments(RequestNotSupportedException::for($request, $this->name));
    }

    public function connect(Connection $connection, string $returnUrl): ConnectResult
    {
        return $this->execute(new Request\Connect($connection, $returnUrl))->getConnectResult();
    }

    public function accounts(Connection $connection): array
    {
        return $this->execute(new Request\FetchAccounts($connection))->getAccounts();
    }

    public function balances(Connection $connection, Account $account): array
    {
        return $this->execute(new Request\FetchBalances($connection, $account))->getBalances();
    }

    public function transactions(Connection $connection, Account $account, ?\DateTimeInterface $since = null, ?\DateTimeInterface $until = null): array
    {
        return $this->execute(new Request\FetchTransactions($connection, $account, $since, $until))->getTransactions();
    }

    public function transfer(Connection $connection, Transfer $transfer): TransferResult
    {
        return $this->execute(new Request\Transfer($connection, $transfer))->getTransferResult();
    }

    public function notify(string $body, array $headers = []): Notification
    {
        return $this->execute(new Request\Notify($body, $headers))->getNotification();
    }
}
