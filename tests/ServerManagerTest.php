<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\ConfigLoader;
use CrazyGoat\WorkermanBundle\ProcessInspector;
use CrazyGoat\WorkermanBundle\ServerManager;
use CrazyGoat\WorkermanBundle\StatusFileReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Tests for ServerManager construction and delegation.
 */
final class ServerManagerTest extends TestCase
{
    private function createEmptyConfigLoader(): ConfigLoader
    {
        return new ConfigLoader(
            projectDir: sys_get_temp_dir(),
            cacheDir: sys_get_temp_dir(),
            isDebug: false,
        );
    }

    public function testCanBeConstructedWithCollaborators(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $configLoader = $this->createEmptyConfigLoader();
        $processInspector = new ProcessInspector();
        $statusFileReader = new StatusFileReader($configLoader);

        $manager = new ServerManager($kernel, $configLoader, $processInspector, $statusFileReader);

        $this->assertInstanceOf(ServerManager::class, $manager);
    }
}
