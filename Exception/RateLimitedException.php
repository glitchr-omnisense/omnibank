<?php

namespace Omnibank\Exception;

/**
 * The provider answered 429 Too Many Requests: an UnavailableException, as a
 * 429 always was here ("try again later"), with the seconds to wait when the
 * provider said ($retryAfter, from Retry-After or RateLimit). The provider's
 * next calls wait as long (UsageLimitReachedException before they are sent).
 * Nothing retries it: a user, a connect session or a transfer is never asked
 * twice by Omnibank.
 */
final class RateLimitedException extends UnavailableException
{
    public function __construct(
        string $provider,
        string $message,
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($provider, $message, '429', $previous);
    }
}
