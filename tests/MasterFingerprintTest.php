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

    public function testWriteToThrowsWhenDirectoryCannotBeCreated(): void
    {
        $readOnlyDir = $this->makeReadOnlyDir();
        $path = $readOnlyDir . '/missing/test.fingerprint';

        try {
            $thrown = null;
            try {
                (new MasterFingerprint(1234, 5678, 1000))->writeTo($path);
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }

            $this->assertNotNull($thrown, 'writeTo() must throw when the directory cannot be created');
            $this->assertStringContainsString('Unable to create directory', $thrown->getMessage());
            $this->assertSame([], $this->tempFilesIn($readOnlyDir));
        } finally {
            chmod($readOnlyDir, 0700);
        }
    }

    public function testWriteToThrowsWhenTempFileCannotBeCreated(): void
    {
        $readOnlyDir = $this->makeReadOnlyDir();
        $path = $readOnlyDir . '/test.fingerprint';

        try {
            $thrown = null;
            try {
                (new MasterFingerprint(1234, 5678, 1000))->writeTo($path);
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }

            $this->assertNotNull($thrown, 'writeTo() must throw when the temp file cannot be created');
            $this->assertStringContainsString('Unable to create fingerprint temp file', $thrown->getMessage());
            $this->assertSame([], $this->tempFilesFor($path));
        } finally {
            chmod($readOnlyDir, 0700);
        }
    }

    public function testWriteToRemovesTempFileWhenRenameFails(): void
    {
        $target = $this->tmpDir . '/existing-directory';
        mkdir($target, 0700);

        $thrown = null;
        try {
            (new MasterFingerprint(1234, 5678, 1000))->writeTo($target);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'writeTo() must throw when the target is an existing directory');
        $this->assertStringContainsString('Unable to rename fingerprint temp file', $thrown->getMessage());
        $this->assertSame([], $this->tempFilesFor($target));
        $this->assertDirectoryExists($target);
    }

    public function testWriteToThrowsAndCleansTempFileWhenChmodFails(): void
    {
        MasterFingerprintFailureStreamWrapper::register();
        MasterFingerprintFailureStreamWrapper::reset(chmodFails: true);

        $thrown = null;
        try {
            (new MasterFingerprint(1234, 5678, 1000))->writeTo('fingerprintfail://root/test.fingerprint');
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'writeTo() must throw when chmod() fails');
        $this->assertStringContainsString('Unable to chmod fingerprint temp file', $thrown->getMessage());
        $this->assertCleanedUpWrapperTempFile();
    }

    public function testWriteToThrowsAndCleansTempFileWhenWriteFails(): void
    {
        MasterFingerprintFailureStreamWrapper::register();
        MasterFingerprintFailureStreamWrapper::reset(writeFails: true);

        $thrown = null;
        try {
            (new MasterFingerprint(1234, 5678, 1000))->writeTo('fingerprintfail://root/test.fingerprint');
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'writeTo() must throw when fwrite() writes fewer bytes than expected');
        $this->assertStringContainsString('Unable to write fingerprint temp file', $thrown->getMessage());
        $this->assertCleanedUpWrapperTempFile();
    }

    private function assertCleanedUpWrapperTempFile(): void
    {
        $this->assertSame([], MasterFingerprintFailureStreamWrapper::openFiles(), 'The temp stream must be closed');
        $unlinked = MasterFingerprintFailureStreamWrapper::unlinkedPaths();
        $this->assertCount(1, $unlinked, 'The inner catch must unlink the temp file exactly once');
        $this->assertMatchesRegularExpression(
            '#^fingerprintfail://root/\.test\.fingerprint\.[0-9a-f]+\.tmp$#',
            $unlinked[0],
        );
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

    /**
     * Create a directory inside the test temp dir that denies writes.
     *
     * Returns a read-only directory, or skips the test on Windows and when
     * running as root (where the permission bits do not apply).
     */
    private function makeReadOnlyDir(): string
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->markTestSkipped('Permission test requires POSIX');
        }

        if (\function_exists('posix_getuid') && \posix_getuid() === 0) {
            $this->markTestSkipped('Permission test cannot work as root');
        }

        $dir = $this->tmpDir . '/read-only-' . \bin2hex(\random_bytes(4));
        mkdir($dir, 0700, true);
        chmod($dir, 0500);

        if (is_writable($dir)) {
            chmod($dir, 0700);
            $this->markTestSkipped('Directory is still writable after chmod');
        }

        return $dir;
    }

    /**
     * @return list<string>
     */
    private function tempFilesFor(string $path): array
    {
        return $this->tempFilesIn(\dirname($path), \basename($path));
    }

    /**
     * @return list<string>
     */
    private function tempFilesIn(string $directory, ?string $basename = null): array
    {
        $pattern = $basename === null
            ? $directory . '/.*.tmp'
            : $directory . '/.' . $basename . '.*.tmp';
        $files = \glob($pattern);

        return \is_array($files) ? $files : [];
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

/**
 * User stream wrapper that lets `MasterFingerprint::writeTo()` reach its inner
 * try block, then fails exactly one step on demand.
 *
 * A read-only directory can only make `fopen()` fail; it never reaches
 * `chmod()` or `fwrite()`. This wrapper reports a writable directory via
 * `url_stat()`, accepts `fopen(..., 'xb')` via `stream_open()`, and then:
 *
 * - `chmodFails: true` -> `stream_metadata()` returns false, so `chmod()` fails;
 * - `writeFails: true` -> `stream_write()` returns 0, so `fwrite()` writes
 *   fewer bytes than expected.
 *
 * Both paths must run the inner catch, which closes and unlinks the temp file;
 * `openFiles()`/`unlinkedPaths()` expose that for assertions. `fclose()`
 * returning false cannot be reached here: PHP returns true from `fclose()` even
 * when `stream_flush()` returns false and ignores the `void` result of
 * `stream_close()` (verified on PHP 8.5; that branch needs a production seam).
 */
final class MasterFingerprintFailureStreamWrapper
{
    private const PROTOCOL = 'fingerprintfail';

    private static bool $registered = false;

    private static bool $chmodFails = false;

    private static bool $writeFails = false;

    /** @var array<string, true> */
    private static array $openFiles = [];

    /** @var list<string> */
    private static array $unlinkedPaths = [];

    /**
     * Set by PHP's stream layer on every wrapper instance; declared so PHP 8.2+
     * does not report a dynamic property.
     *
     * @var resource|null
     */
    public $context;

    private string $path = '';

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        if (!@\stream_wrapper_register(self::PROTOCOL, self::class)) {
            throw new \RuntimeException('Unable to register the fingerprint failure stream wrapper.');
        }

        self::$registered = true;
    }

    public static function reset(bool $chmodFails = false, bool $writeFails = false): void
    {
        self::$chmodFails = $chmodFails;
        self::$writeFails = $writeFails;
        self::$openFiles = [];
        self::$unlinkedPaths = [];
    }

    /**
     * @return list<string>
     */
    public static function openFiles(): array
    {
        return \array_keys(self::$openFiles);
    }

    /**
     * @return list<string>
     */
    public static function unlinkedPaths(): array
    {
        return self::$unlinkedPaths;
    }

    /**
     * Report every path under the wrapper protocol as an existing writable
     * directory (mode 0700) so `is_dir(dirname($path))` succeeds.
     *
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path): array|false
    {
        if (!\str_starts_with($path, self::PROTOCOL . '://')) {
            return false;
        }

        return ['mode' => 0040700] + \array_fill(0, 13, 0);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (!\str_starts_with($mode, 'x')) {
            return false;
        }

        $this->path = $path;
        self::$openFiles[$path] = true;

        if (($options & \STREAM_USE_PATH) === \STREAM_USE_PATH) {
            $openedPath = $path;
        }

        return true;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        if ($option === \STREAM_META_ACCESS) {
            return !self::$chmodFails;
        }

        return true;
    }

    public function stream_write(string $data): int
    {
        if (self::$writeFails) {
            return 0;
        }

        return \strlen($data);
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
        unset(self::$openFiles[$this->path]);
    }

    public function unlink(string $path): bool
    {
        self::$unlinkedPaths[] = $path;
        unset(self::$openFiles[$path]);

        return true;
    }
}
