<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Docs;

use CrazyGoat\WorkermanBundle\Http\Response\Strategy\StreamedResponseStrategy;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use CrazyGoat\WorkermanBundle\Middleware\StaticFilesMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The facts of docs/middlewares.md and docs/http-server.md must match the code (issue #878, section 5).
 *
 * @coversNothing
 */
final class HttpMiddlewareDocTest extends TestCase
{
    private const MIDDLEWARES = 'docs/middlewares.md';
    private const HTTP_SERVER = 'docs/http-server.md';

    /**
     * @return iterable<string, array{string}>
     */
    public static function constructorArgumentProvider(): iterable
    {
        $constructor = (new \ReflectionClass(StaticFilesMiddleware::class))->getConstructor();
        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            yield $parameter->getName() => [$parameter->getName()];
        }
    }

    #[DataProvider('constructorArgumentProvider')]
    public function testConstructorArgumentIsDocumented(string $name): void
    {
        self::assertStringContainsString(
            '| `$' . $name . '` |',
            DocsHelper::read(self::MIDDLEWARES),
            sprintf('%s has no table row for the argument `$%s`.', self::MIDDLEWARES, $name),
        );
    }

    public function testInterfaceAndMiddlewareClassAreNamed(): void
    {
        $page = DocsHelper::read(self::MIDDLEWARES);

        self::assertStringContainsString(MiddlewareInterface::class, $page);
        self::assertStringContainsString(StaticFilesMiddleware::class, $page);
    }

    public function testStaticFileHeadersAreDocumented(): void
    {
        $page = DocsHelper::read(self::MIDDLEWARES);
        $source = DocsHelper::read('src/Middleware/StaticFilesMiddleware.php');

        // The headers and the Cache-Control value must be the ones that the middleware sets.
        preg_match_all("/->header\\('([A-Za-z-]+)', (?:'([^']+)'|\\$)/", $source, $matches, PREG_SET_ORDER);
        self::assertNotEmpty($matches, 'The middleware sets no header any more: update this test and the page.');

        foreach ($matches as $match) {
            self::assertStringContainsString('| `' . $match[1] . '` |', $page, sprintf('%s does not list the header %s.', self::MIDDLEWARES, $match[1]));
            if (isset($match[2])) {
                self::assertStringContainsString('`' . $match[2] . '`', $page, sprintf('%s has another value for %s.', self::MIDDLEWARES, $match[1]));
            }
        }
    }

    public function testMinimumChunkSizeIsDocumented(): void
    {
        $minimum = (new \ReflectionClassConstant(StreamedResponseStrategy::class, 'MIN_CHUNK_SIZE'))->getValue();
        self::assertIsInt($minimum);

        foreach ([self::HTTP_SERVER, 'docs/configuration.md'] as $page) {
            self::assertStringContainsString(
                'below ' . $minimum . ' ',
                DocsHelper::read($page),
                sprintf('%s does not say that chunk sizes below %d are raised.', $page, $minimum),
            );
        }
    }

    public function testHttpServerPageNamesTheLimitKeys(): void
    {
        $page = DocsHelper::read(self::HTTP_SERVER);

        foreach (['listen', 'processes', 'reuse_port', 'max_package_size', 'body_size_cap', 'connection_timeout', 'keepalive_timeout', 'response_chunk_size'] as $key) {
            self::assertStringContainsString($key, $page, sprintf('%s does not mention `%s`.', self::HTTP_SERVER, $key));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pageProvider(): iterable
    {
        foreach (DocsHelper::userPages() as $page) {
            yield $page => [$page];
        }
    }

    /**
     * The middleware service examples must be valid service definitions. They do not need `public: true`: the bundle makes the services public itself (issue #964).
     */
    #[DataProvider('pageProvider')]
    public function testMiddlewareServiceExamplesAreValidServices(string $page): void
    {
        preg_match_all('/```yaml\n(.*?)```/s', DocsHelper::read($page), $matches);

        $this->addToAssertionCount(1);
        foreach ($matches[1] as $block) {
            if (!str_contains($block, 'services:') || !preg_match('/Middleware\b/', $block)) {
                continue;
            }

            $parsed = Yaml::parse($block);
            self::assertIsArray($parsed);
            $services = $parsed['services'] ?? [];
            self::assertIsArray($services);
            foreach ($services as $id => $definition) {
                self::assertTrue($definition === null || is_array($definition), sprintf('%s: the service %s has no valid definition.', $page, $id));
            }
        }
    }
}
