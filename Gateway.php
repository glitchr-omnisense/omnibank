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
use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * A provider's actions behind one door: the first action supporting a request
 * answers it. The waits the provider asks for (a 429's Retry-After) hold its
 * calls in every case, in its API client. Guarded (guardedBy(), what
 * Registry::guarded() gives), every request but a webhook's (Notify) also
 * goes through the provider's usage policy as the site runs it
 * (omnisense/contracts' PolicyGuard): a limit reached refuses it before
 * anything is sent (UsageLimitReachedException), the call is counted after,
 * answered or failed. A limit counted per user counts per the provider's user
 * the connection names (the factory's "omnibank.user_key"); a connection with
 * no user yet is a new one, which no limit has counted. Nothing retries a call.
 */
final class Gateway implements GatewayInterface, DeclaresUsagePolicyInterface
{
    /** Each request's operation: GatewayInterface's method name, the policy's "operations". */
    public const OPERATIONS = [
        Request\Connect::class => 'connect',
        Request\FetchAccounts::class => 'accounts',
        Request\FetchBalances::class => 'balances',
        Request\FetchTransactions::class => 'transactions',
        Request\Transfer::class => 'transfer',
        Request\Notify::class => 'notify',
        Request\RegisterWebhook::class => 'registerWebhook',
        Request\FetchWebhooks::class => 'fetchWebhooks',
        Request\DeleteWebhook::class => 'deleteWebhook',
    ];

    private ?Capabilities $capabilities = null;

    /**
     * @param ActionInterface[] $actions
     * @param ?UsagePolicy      $policy  the provider's, as it declares it (unknown when not given)
     * @param ?PolicyGuard      $guard   the policy every request but a webhook's goes through (none: unguarded)
     * @param ?string           $userKey the key of a connection's state naming the provider's user, for the limits counted per user
     */
    public function __construct(
        private readonly string $name,
        private readonly string $title,
        private readonly array $actions,
        private readonly ?UsagePolicy $policy = null,
        private readonly ?PolicyGuard $guard = null,
        private readonly ?string $userKey = null,
    ) {
    }

    /** The same gateway, its requests (but a webhook's) through this guard. */
    public function guardedBy(PolicyGuard $guard): self
    {
        return new self($this->name, $this->title, $this->actions, $this->policy, $guard, $this->userKey);
    }

    /** The operation a request is: "connect", "transactions"... (its class's short name, first letter lower, when it is no request of the family's). */
    public static function operation(Request\Request $request): string
    {
        return self::OPERATIONS[$request::class] ?? lcfirst((new \ReflectionClass($request))->getShortName());
    }

    public function usagePolicy(): UsagePolicy
    {
        return $this->policy ?? UsagePolicy::unknown($this->name);
    }

    public function capabilities(): Capabilities
    {
        if (null === $this->capabilities) {
            $operations = [];
            foreach (self::OPERATIONS as $request => $operation) {
                if ($this->supports($request)) {
                    $operations[] = $operation;
                }
            }
            $this->capabilities = new Capabilities($operations, $this->usagePolicy());
        }

        return $this->capabilities;
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
        // A webhook is a call made to the site: its own calls to the provider wait as the provider asked, in its API client.
        if (null !== $this->guard && !$request instanceof Request\Notify) {
            return $this->guard->call(self::operation($request), fn () => $this->answer($request), $this->context($request));
        }

        return $this->answer($request);
    }

    /**
     * Who a limit counted per user counts: the provider's user the request's
     * connection names, or - a connection with none yet - a new user's.
     *
     * @return array{user?: string}
     */
    private function context(Request\Request $request): array
    {
        if (null === $this->userKey || !property_exists($request, 'connection') || !$request->connection instanceof Model\Connection) {
            return [];
        }
        $user = $request->connection->get($this->userKey);

        return ['user' => \is_scalar($user) && '' !== (string) $user ? (string) $user : 'new:'.bin2hex(random_bytes(8))];
    }

    /**
     * @template T of Request\Request
     *
     * @param T $request
     *
     * @return T
     */
    private function answer(Request\Request $request): Request\Request
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
