<?php

namespace Omnibank\Http;

/**
 * Whether a provider can reach an address: a webhook registered on
 * localhost, a *.local or *.test name, a private or reserved IP, or plain
 * HTTP would never receive anything. unreachable() says why, or null when
 * the address is public HTTPS.
 */
final class PublicUrl
{
    private const LOCAL_SUFFIXES = ['.localhost', '.local', '.test', '.internal', '.lan', '.home', '.example', '.invalid'];

    public static function unreachable(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ('' === $host) {
            return sprintf('"%s" names no host.', $url);
        }
        if ('https' !== strtolower((string) ($parts['scheme'] ?? ''))) {
            return sprintf('%s is not HTTPS: providers post to HTTPS addresses only.', $url);
        }
        if ('localhost' === $host || !str_contains($host, '.') && false === filter_var($host, \FILTER_VALIDATE_IP)) {
            return sprintf('%s is a local name: no provider can reach it.', $host);
        }
        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return sprintf('%s is a local name: no provider can reach it.', $host);
            }
        }
        if (false !== filter_var($host, \FILTER_VALIDATE_IP) && false === filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE)) {
            return sprintf('%s is a private address: no provider can reach it.', $host);
        }

        return null;
    }
}
