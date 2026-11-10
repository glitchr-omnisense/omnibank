<?php

namespace Omnibank\Exception;

/**
 * The provider could not be reached or failed on its side (network, timeout,
 * 5xx, rate limit): nothing is known, which is not the same as nothing
 * there. Never read it as "no accounts" or "no transactions"; try again later.
 * A 429 is its RateLimitedException, with the wait the provider asked for.
 */
class UnavailableException extends ProviderException
{
}
