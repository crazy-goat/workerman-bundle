<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Tools;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PhpCsFixerAutoloadTest extends TestCase
{
    public function testResolvesThePharFromTheConfiguredVendorDir(): void
    {
        [$code, $output] = $this->load(\dirname(__DIR__, 2) . '/vendor');

        $this->assertSame(0, $code, $output);
        $this->assertSame('ok', $output);
    }

    public function testMissingShimFailsWithAClearMessage(): void
    {
        [$code, $output] = $this->load('/nonexistent-vendor-dir');

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('php-cs-fixer/shim is not installed (expected /nonexistent-vendor-dir/php-cs-fixer/shim/php-cs-fixer.phar)', $output);
    }

    /**
     * @return array{int, string}
     */
    private function load(string $vendorDir): array
    {
        $script = \sprintf(
            'require %s; echo class_exists(%s) ? "ok" : "missing";',
            var_export(\dirname(__DIR__, 2) . '/tools/php-cs-fixer/autoload.php', true),
            var_export(\PhpCsFixer\Tokenizer\Token::class, true),
        );
        exec(
            'COMPOSER_VENDOR_DIR=' . escapeshellarg($vendorDir) . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1',
            $output,
            $code,
        );

        return [$code, implode("\n", $output)];
    }
}
