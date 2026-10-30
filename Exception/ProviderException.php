<?php

namespace Omnibank\Exception;

use Omnibank\Model\ConnectionState;

/** The provider refused: wrong credentials, an unknown account, a file it cannot read. */
class ProviderException extends \RuntimeException implements OmnibankException
{
    public function __construct(
        public readonly string $provider,
        string $message,
        /** The provider's own error code, when it gave one */
        public readonly ?string $providerCode = null,
        ?\Throwable $previous = null,
        /** What the refusal says of the access to the bank, when the provider's code says it */
        public readonly ?ConnectionState $connectionState = null,
    ) {
        parent::__construct(\sprintf('[%s] %s', $provider, $message), 0, $previous);
    }
}
