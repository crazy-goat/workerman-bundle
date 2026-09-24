<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for issue #778: the test app's var/cache and var/log trees
 * must stay non-world-writable even when the invoking shell runs with umask
 * 0000 (containers). Symfony's Runtime and Workerman's daemonize() both reset
 * the umask to 0, so the pin alone is not enough — the daemon's tree is
 * hardened after startup.
 *
 * @coversNothing
 */
final class TestAppHygieneTest extends TestCase
{
    public function testBootstrapPinsUmaskBeforeStartingTheDaemon(): void
    {
        $contents = $this->read('tests/App/bootstrap.php');

        $pin = strpos($contents, 'umask(0077)');
        $start = strpos($contents, '\\workerman_start();');

        self::assertNotFalse($pin, 'tests/App/bootstrap.php must pin umask(0077) for the test process');
        self::assertNotFalse($start, 'tests/App/bootstrap.php must start the test daemon');
        self::assertLessThan($start, $pin, 'The umask pin must run before the daemon is started');
    }

    public function testBootstrapHardensTheDaemonVarTreeAfterStartup(): void
    {
        $contents = $this->read('tests/App/bootstrap.php');

        self::assertStringContainsString(
            'harden_test_var_tree();',
            $contents,
            'tests/App/bootstrap.php must harden var/cache and var/log after the daemon starts',
        );

        $start = strpos($contents, '\\workerman_start();');
        $harden = strpos($contents, "\n\\harden_test_var_tree();");
        self::assertNotFalse($start);
        self::assertNotFalse($harden, 'The hardening call must be at the top level, not only inside the function');
        self::assertGreaterThan($start, $harden, 'Hardening must run after the daemon has created its var tree');
        self::assertStringContainsString('__DIR__ . \'/../../var/cache\'', $contents);
        self::assertStringContainsString('__DIR__ . \'/../../var/log\'', $contents);
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(__DIR__ . '/../' . $relativePath);
        self::assertIsString($contents);

        return $contents;
    }
}
