<?php

namespace Omnibank\Model;

use Omnibank\Exception\ProviderException;
use Omnibank\Exception\UnavailableException;

/**
 * How the provider's access to the bank is doing, beside the consent: the
 * consent says whether the user allowed it, the state whether the last
 * synchronisation went through. Each provider maps its own words onto these
 * (Powens' wrongpass, SCARequired, websiteUnavailable...; Bridge's item
 * statuses); a word its mapping does not know is UNKNOWN - never an error.
 */
enum ConnectionState: string
{
    /** The last synchronisation went through. */
    case OK = 'ok';
    /** Just added or being validated: the provider has not finished its first synchronisation. */
    case PENDING = 'pending';
    /** The user must act: strong authentication, new terms to accept, information to give, a password to renew. */
    case ACTION_NEEDED = 'action_needed';
    /** The stored credentials are refused. */
    case WRONG_CREDENTIALS = 'wrong_credentials';
    /** The bank's site or API is down or limits the calls: the provider tries again by itself. */
    case BANK_UNAVAILABLE = 'bank_unavailable';
    /** An error on the provider's side, or an account it cannot read. */
    case BUG = 'bug';
    case UNKNOWN = 'unknown';

    /** Whether the user has to go through the provider's page again. */
    public function needsUser(): bool
    {
        return self::ACTION_NEEDED === $this || self::WRONG_CREDENTIALS === $this;
    }

    /**
     * The family a dashboard counts it in: "ok" (ok, pending), "action" (the
     * user must act), "unavailable" (the bank, for a while), "error" (a bug,
     * an unknown word).
     */
    public function group(): string
    {
        return match ($this) {
            self::OK, self::PENDING => 'ok',
            self::ACTION_NEEDED, self::WRONG_CREDENTIALS => 'action',
            self::BANK_UNAVAILABLE => 'unavailable',
            self::BUG, self::UNKNOWN => 'error',
        };
    }

    /**
     * What a failure says of the access: the state the provider put on its
     * ProviderException, BANK_UNAVAILABLE for an UnavailableException (the
     * provider or the bank could not answer), BUG for anything else.
     */
    public static function ofFailure(\Throwable $failure): self
    {
        for ($e = $failure; null !== $e; $e = $e->getPrevious()) {
            if ($e instanceof ProviderException && null !== $e->connectionState) {
                return $e->connectionState;
            }
            if ($e instanceof UnavailableException) {
                return self::BANK_UNAVAILABLE;
            }
        }

        return self::BUG;
    }

    /** From a value stored earlier (the enum's value); UNKNOWN for anything else. */
    public static function of(?string $value): self
    {
        return null === $value ? self::UNKNOWN : (self::tryFrom(strtolower($value)) ?? self::UNKNOWN);
    }
}
