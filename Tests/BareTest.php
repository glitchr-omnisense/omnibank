<?php

namespace Omnibank\Tests;

use Omnibank\Bridge\Symfony\OmnibankBundle;
use Omnibank\Files\FilesGatewayFactory;
use Omnibank\Qonto\QontoGatewayFactory;
use Omnibank\Registry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Omnibank outside Symfony: the harness's bare script (docker/harness/bin/bare)
 * run in a PHP process of its own - this one has loaded the bundle's tests -
 * builds the registry by hand, reads a statement through omnibank/files and
 * an organization's accounts through omnibank/qonto from the answers kept in
 * docker/harness/recorded/, and reports every class and file PHP loaded on
 * the way. None may be a framework's.
 */
final class BareTest extends TestCase
{
    private const FRAMEWORK = '~^(?:Symfony\\\\Component\\\\(?:DependencyInjection|Config|HttpKernel|HttpFoundation)|Symfony\\\\Bundle|Doctrine|Twig)\\\\~';
    private const FRAMEWORK_FILES = '~/vendor/(?:symfony/(?:dependency-injection|config|http-kernel|http-foundation|[a-z-]*bundle)|doctrine|twig)/~';

    public function testTheRegistryIsBuiltByHandAndNoClassOfAFrameworkIsLoaded(): void
    {
        [$status, $report] = self::php([__DIR__.'/../docker/harness/bin/bare', '--recorded', '--json']);

        self::assertSame(0, $status);
        self::assertContains(Registry::class, $report['symbols'], 'the registry was built there');
        self::assertSame([], self::framework($report), 'no class nor file of a framework');
        $installed = array_values(array_filter(array_column(require __DIR__.'/../docker/harness/plugins.php', 1), 'class_exists'));
        foreach ($installed as $factory) {
            self::assertContains($factory, $report['symbols'], 'every provider package installed, its factory built');
        }
        self::assertCount(\count($installed), $report['factories']);
    }

    public function testAStatementFileIsReadWithNoClassOfAFrameworkLoaded(): void
    {
        if (!class_exists(FilesGatewayFactory::class)) {
            self::markTestSkipped('omnibank/files is not installed.');
        }
        [$status, $report] = self::php([__DIR__.'/../docker/harness/bin/bare', '--recorded', '--json']);

        self::assertSame(0, $status);
        self::assertSame(['connect', 'accounts', 'balances', 'transactions', 'transfer'], $report['gateways']['files']);
        self::assertSame(['name' => 'Compte courant', 'iban' => 'FR7630006000011234567890189', 'bic' => 'TESTFRPPXXX', 'currency' => 'EUR'], $report['statement']['account']);
        self::assertSame(['opening' => '10000.00', 'booked' => '5926.60', 'available' => '5926.60'], $report['statement']['balances']);
        self::assertSame(6, $report['statement']['transactions']);
        self::assertSame([], self::framework($report), 'no class nor file of a framework');
    }

    public function testAProviderAnswersFromRecordedAnswersWithNoClassOfAFrameworkLoaded(): void
    {
        if (!class_exists(QontoGatewayFactory::class)) {
            self::markTestSkipped('omnibank/qonto is not installed.');
        }
        [$status, $report] = self::php([__DIR__.'/../docker/harness/bin/bare', '--recorded', '--json']);

        self::assertSame(0, $status);
        self::assertTrue($report['recorded']);
        self::assertSame(['connect', 'accounts', 'balances', 'transactions'], $report['gateways']['qonto']);
        self::assertSame(2, $report['qonto']['accounts']);
        self::assertSame(['name' => 'Compte principal', 'iban' => 'FR5116958000011234567890142', 'bic' => 'QNTOFRP1XXX', 'currency' => 'EUR'], $report['qonto']['account']);
        self::assertSame(['booked' => '18432.17', 'available' => '18102.17'], $report['qonto']['balances']);
        self::assertSame(3, $report['qonto']['transactions'], 'both pages');
        self::assertSame(['on' => '2026-09-02', 'amount' => '1250.00', 'counterparty' => 'Camille Durand', 'reference' => 'Facture F-2026-042'], $report['qonto']['first']);
        self::assertContains('Symfony\\Component\\HttpClient\\MockHttpClient', $report['symbols'], 'the answers came through the HTTP client given');
        self::assertSame([], self::framework($report), 'no class nor file of a framework');
    }

    /** The check is not blind: the same report, once the bundle is loaded, names the framework. */
    public function testTheBundleDoesLoadTheFramework(): void
    {
        if (!class_exists(AbstractBundle::class)) {
            self::markTestSkipped('symfony/http-kernel is not installed.');
        }
        [$status, $report] = self::php(['-r', 'require getenv("OMNIBANK_AUTOLOAD"); class_exists($argv[1]) || exit(2); echo json_encode(["symbols" => [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()], "files" => get_included_files()]);', '--', OmnibankBundle::class]);

        self::assertSame(0, $status);
        $framework = self::framework($report);
        self::assertContains(AbstractBundle::class, $framework);
        self::assertNotEmpty(preg_grep('~/symfony/http-kernel/~', $framework));
    }

    /**
     * @param array{symbols: list<string>, files: list<string>} $report
     *
     * @return list<string> the classes, interfaces, traits and files of a framework among those loaded
     */
    private static function framework(array $report): array
    {
        return [...array_values(preg_grep(self::FRAMEWORK, $report['symbols'])), ...array_values(preg_grep(self::FRAMEWORK_FILES, $report['files']))];
    }

    /**
     * Runs PHP apart, on the autoloader of this run.
     *
     * @param list<string> $arguments
     *
     * @return array{int, array<string, mixed>} the exit status, the JSON printed
     */
    private static function php(array $arguments): array
    {
        $autoload = \dirname((string) (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2).'/autoload.php';
        $process = proc_open([\PHP_BINARY, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['OMNIBANK_AUTOLOAD' => $autoload] + getenv());
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        $status = proc_close($process);
        $report = json_decode($out, true);
        self::assertIsArray($report, 'PHP exited '.$status.': '.$err.$out);

        return [$status, $report];
    }
}
