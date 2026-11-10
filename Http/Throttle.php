<?php

namespace Omnibank\Http;

use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\UsageLimitReached;
use Omnibank\Exception\RateLimitedException;

/**
 * A provider's HTTP calls and the waits it asks for (omnisense/contracts'
 * Backoff, a factory's Config::backoff()): before() refuses a call while a
 * wait lasts (UsageLimitReachedException: nothing sent), after() reads each
 * answer's Retry-After and RateLimit fields and turns a 429 into a
 * RateLimitedException with the wait asked - an UnavailableException, as a
 * 429 was before. Nothing here retries: a user, a connect session or a
 * transfer refused with 429 is the caller's to ask again.
 */
final class Throttle
{
    /** @throws \Omnibank\Exception\UsageLimitReachedException while the provider's wait lasts */
    public static function before(?Backoff $backoff): void
    {
        $backoff?->clear();
    }

    /**
     * @param array<string, string|list<string>> $headers the answer's
     *
     * @throws RateLimitedException on a 429
     */
    public static function after(?Backoff $backoff, string $provider, int $status, array $headers, string $message = ''): void
    {
        try {
            $backoff?->observe($status, $headers);
        } catch (UsageLimitReached $e) {
            throw Redaction::withoutArguments(new RateLimitedException($provider, '' !== $message ? $message : $e->getMessage(), $e->retryAfter, $e));
        }
        if (429 === $status) {
            // No Backoff given (an Api built by hand): the refusal all the same.
            throw Redaction::withoutArguments(new RateLimitedException($provider, '' !== $message ? $message : \sprintf('%s answered HTTP 429: try again later.', $provider)));
        }
    }
}
