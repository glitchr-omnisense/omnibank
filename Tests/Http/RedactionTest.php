<?php

namespace Omnibank\Tests\Http;

use Omnibank\Exception\InvalidConfigException;
use Omnibank\Http\Redaction;
use Omnibank\Tests\StubFactory;
use PHPUnit\Framework\TestCase;

/**
 * What the providers' catch blocks call: the secrets a request carries, its message masked, the
 * client's exception copied without arguments in its trace, which PHP keeps unless
 * zend.exception_ignore_args is on.
 */
final class RedactionTest extends TestCase
{
    private const URL = 'https://user:url-pass-31337@api.example/v1/rates?api_key=query-key-123456&postcode=75002';
    private const OPTIONS = [
        'auth_basic' => ['client-id', 'basic-secret-654321'],
        'auth_bearer' => 'bearer-token-987654',
        'headers' => ['X-Api-Key' => 'header-key-246810', 'Accept' => 'application/json'],
        'body' => ['client_secret' => 'body-secret-135791', 'weight' => '1.5'],
    ];
    private const SECRETS = ['url-pass-31337', 'query-key-123456', 'basic-secret-654321', 'bearer-token-987654', 'header-key-246810', 'body-secret-135791'];

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', false !== $this->ignoreArgs ? $this->ignoreArgs : '1');
    }

    public function testSecretsAreThoseOfTheRequest(): void
    {
        $secrets = Redaction::secrets(self::URL, self::OPTIONS);
        foreach (self::SECRETS as $secret) {
            self::assertContains($secret, $secrets);
        }
        self::assertNotContains('75002', $secrets);
        self::assertNotContains('application/json', $secrets);
    }

    public function testACopyKeepsTheClassAndCodeAndNothingOfTheSecrets(): void
    {
        $failure = self::failure(self::URL, self::OPTIONS);
        self::assertStringContainsString('basic-secret-654321', print_r($failure->getTrace(), true));

        $copy = Redaction::copy($failure, Redaction::secrets(self::URL, self::OPTIONS));

        self::assertSame(\DomainException::class, $copy::class);
        self::assertSame(6, $copy->getCode());
        self::assertStringContainsString('api_key=***', $copy->getMessage());
        self::assertStringContainsString('postcode=75002', $copy->getMessage());
        self::assertInstanceOf(\LogicException::class, $copy->getPrevious());
        self::assertClean($copy);
    }

    public function testWithoutArgumentsEmptiesEveryTraceOfTheChain(): void
    {
        $failure = Redaction::withoutArguments(new \RuntimeException('failed', 0, self::failure(self::URL, self::OPTIONS)));

        self::assertNotEmpty($failure->getPrevious()?->getTrace());
        for ($one = $failure; null !== $one; $one = $one->getPrevious()) {
            foreach ($one->getTrace() as $frame) {
                self::assertArrayNotHasKey('args', $frame);
            }
        }
    }

    public function testAFactoryThatFailsKeepsNoOptionInItsTrace(): void
    {
        try {
            (new StubFactory())->create(['token' => '', 'api_secret' => 'factory-secret-864209']);
            self::fail('no exception');
        } catch (InvalidConfigException $e) {
            self::assertNotEmpty($e->getTrace());
            self::assertStringNotContainsString('factory-secret-864209', print_r($e->getTrace(), true));
            self::assertStringNotContainsString('factory-secret-864209', $e->getTraceAsString());
        }
    }

    /** @param array<string, mixed> $options */
    private static function failure(string $url, array $options): \DomainException
    {
        try {
            throw new \LogicException(\sprintf('Inner failure for "%s" with %s.', $url, json_encode($options['body'])));
        } catch (\LogicException $e) {
            return new \DomainException(\sprintf('Could not resolve host: api.example for "%s".', $url), 6, $e);
        }
    }

    private static function assertClean(\Throwable $e): void
    {
        for ($one = $e; null !== $one; $one = $one->getPrevious()) {
            foreach (self::SECRETS as $secret) {
                self::assertStringNotContainsString($secret, $one->getMessage());
                self::assertStringNotContainsString($secret, print_r($one->getTrace(), true));
                self::assertStringNotContainsString($secret, $one->getTraceAsString());
            }
        }
    }
}
