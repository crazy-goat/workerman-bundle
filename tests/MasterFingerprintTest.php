<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\MasterFingerprint;
use PHPUnit\Framework\TestCase;

final class MasterFingerprintTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/workerman_fingerprint_test_' . uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testCaptureReturnsValidFingerprint(): void
    {
        $fingerprint = MasterFingerprint::capture();

        $this->assertGreaterThan(0, $fingerprint->pid, 'Captured PID must be positive');
        $this->assertGreaterThanOrEqual(0, $fingerprint->startTime, 'Captured start time must be non-negative');
        $this->assertGreaterThanOrEqual(0, $fingerprint->uid, 'Captured UID must be non-negative');
    }

    public function testToStringProducesPipeSeparatedFormat(): void
    {
        $fingerprint = new MasterFingerprint(1234, 5678, 1000);

        $this->assertSame('1234|5678|1000', $fingerprint->toString());
    }

    public function testFromStringParsesValidFormat(): void
    {
        $fingerprint = MasterFingerprint::fromString('1234|5678|1000');

        $this->assertNotNull($fingerprint);
        $this->assertSame(1234, $fingerprint->pid);
        $this->assertSame(5678, $fingerprint->startTime);
        $this->assertSame(1000, $fingerprint->uid);
    }

    public function testFromStringReturnsNullForEmptyString(): void
    {
        $this->assertNull(MasterFingerprint::fromString(''));
        $this->assertNull(MasterFingerprint::fromString('   '));
    }

    public function testFromStringReturnsNullForMalformedFormat(): void
    {
        $this->assertNull(MasterFingerprint::fromString('1234'));
        $this->assertNull(MasterFingerprint::fromString('1234|5678'));
        $this->assertNull(MasterFingerprint::fromString('1234|5678|1000|extra'));
    }

    public function testFromStringReturnsNullForNonNumericFields(): void
    {
        $this->assertNull(MasterFingerprint::fromString('abc|5678|1000'));
        $this->assertNull(MasterFingerprint::fromString('1234|xyz|1000'));
        $this->assertNull(MasterFingerprint::fromString('1234|5678|abc'));
    }

    public function testFromStringReturnsNullForZeroOrNegativePid(): void
    {
        $this->assertNull(MasterFingerprint::fromString('0|5678|1000'));
        $this->assertNull(MasterFingerprint::fromString('-1|5678|1000'));
    }

    public function testWriteToCreatesFileWithCorrectContent(): void
    {
        $fingerprint = new MasterFingerprint(1234, 5678, 1000);
        $path = $this->tmpDir . '/test.fingerprint';

        $fingerprint->writeTo($path);

        $this->assertFileExists($path);
        $this->assertSame('1234|5678|1000', file_get_contents($path));
    }

    public function testWriteToCreatesFileWith0600Permissions(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->markTestSkipped('Permission test requires POSIX');
        }

        $fingerprint = new MasterFingerprint(1234, 5678, 1000);
        $path = $this->tmpDir . '/perms.fingerprint';

        $fingerprint->writeTo($path);

        $perms = fileperms($path) & 0777;
        $this->assertSame(0600, $perms, 'Fingerprint file must have 0600 permissions');
    }

    public function testWriteToOverwritesExistingFile(): void
    {
        $path = $this->tmpDir . '/overwrite.fingerprint';
        file_put_contents($path, 'old|content|here');

        $fingerprint = new MasterFingerprint(1234, 5678, 1000);
        $fingerprint->writeTo($path);

        $this->assertSame('1234|5678|1000', file_get_contents($path));
    }

    public function testWriteToCreatesDirectoryIfMissing(): void
    {
        $path = $this->tmpDir . '/subdir/test.fingerprint';

        $fingerprint = new MasterFingerprint(1234, 5678, 1000);
        $fingerprint->writeTo($path);

        $this->assertFileExists($path);
    }

    public function testReadFromReturnsNullWhenFileDoesNotExist(): void
    {
        $path = $this->tmpDir . '/nonexistent.fingerprint';

        $this->assertNull(MasterFingerprint::readFrom($path));
    }

    public function testReadFromReturnsNullWhenFileIsEmpty(): void
    {
        $path = $this->tmpDir . '/empty.fingerprint';
        file_put_contents($path, '');

        $this->assertNull(MasterFingerprint::readFrom($path));
    }

    public function testReadFromReturnsNullWhenFileIsMalformed(): void
    {
        $path = $this->tmpDir . '/malformed.fingerprint';
        file_put_contents($path, 'garbage');

        $this->assertNull(MasterFingerprint::readFrom($path));
    }

    public function testReadFromReturnsParsedFingerprint(): void
    {
        $path = $this->tmpDir . '/valid.fingerprint';
        file_put_contents($path, '1234|5678|1000');

        $fingerprint = MasterFingerprint::readFrom($path);

        $this->assertNotNull($fingerprint);
        $this->assertSame(1234, $fingerprint->pid);
        $this->assertSame(5678, $fingerprint->startTime);
        $this->assertSame(1000, $fingerprint->uid);
    }

    public function testWriteThenReadRoundTrip(): void
    {
        $original = new MasterFingerprint(42, 9999, 500);
        $path = $this->tmpDir . '/roundtrip.fingerprint';

        $original->writeTo($path);
        $loaded = MasterFingerprint::readFrom($path);

        $this->assertNotNull($loaded);
        $this->assertSame($original->pid, $loaded->pid);
        $this->assertSame($original->startTime, $loaded->startTime);
        $this->assertSame($original->uid, $loaded->uid);
    }

    public function testCaptureWriteReadRoundTrip(): void
    {
        $captured = MasterFingerprint::capture();
        $path = $this->tmpDir . '/capture.fingerprint';

        $captured->writeTo($path);
        $loaded = MasterFingerprint::readFrom($path);

        $this->assertNotNull($loaded);
        $this->assertSame($captured->pid, $loaded->pid);
        $this->assertSame($captured->startTime, $loaded->startTime);
        $this->assertSame($captured->uid, $loaded->uid);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }

        @rmdir($path);
    }
}
