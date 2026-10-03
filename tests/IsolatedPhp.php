<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

/**
 * Builds the `php` command for isolated test subprocesses (#823).
 *
 * The subprocess runs with `-n` (no php.ini), so the grpc extension is not loaded: its shutdown
 * handler deadlocks in forked children (FAQ-007). `-n` also drops every extension that is a
 * shared module (pcntl in the official Docker images), and an extension that is built in (posix
 * there) must not be loaded again. So the module is added with `-d extension=` only when this
 * PHP has it loaded but a `-n` PHP does not.
 */
final class IsolatedPhp
{
    /** Extensions that the isolated fixtures call. Never add grpc here. */
    private const REQUIRED = ['pcntl', 'posix'];

    /** @var list<string>|null */
    private static ?array $loadedWithoutIni = null;

    /**
     * The command prefix: add the script and its arguments after it.
     *
     * @return list<string>
     */
    public static function command(): array
    {
        $extensionDir = (string) ini_get('extension_dir');
        $loadedHere = array_values(array_filter(self::REQUIRED, extension_loaded(...)));
        $command = [PHP_BINARY, '-n', '-d', 'extension_dir=' . $extensionDir];

        foreach (self::extensionsToLoad(self::REQUIRED, $loadedHere, self::loadedWithoutIni($extensionDir)) as $extension) {
            $command[] = '-d';
            $command[] = 'extension=' . $extension;
        }

        return $command;
    }

    /**
     * @param list<string> $required
     * @param list<string> $loadedHere extensions of the normal runtime
     * @param list<string> $loadedWithoutIni extensions of the runtime started with `-n`
     *
     * @return list<string> shared modules to load explicitly
     */
    public static function extensionsToLoad(array $required, array $loadedHere, array $loadedWithoutIni): array
    {
        return array_values(array_filter(
            $required,
            static fn(string $extension): bool => \in_array($extension, $loadedHere, true) && !\in_array($extension, $loadedWithoutIni, true),
        ));
    }

    /**
     * @return list<string>
     */
    private static function loadedWithoutIni(string $extensionDir): array
    {
        if (self::$loadedWithoutIni !== null) {
            return self::$loadedWithoutIni;
        }

        $process = proc_open(
            [PHP_BINARY, '-n', '-d', 'extension_dir=' . $extensionDir, '-r', 'echo json_encode(get_loaded_extensions());'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!\is_resource($process)) {
            throw new \RuntimeException('Unable to start PHP to probe its extensions.');
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $loaded = json_decode($output, true);
        if (!\is_array($loaded)) {
            throw new \RuntimeException('Unable to read the extension list of `php -n`: ' . $output);
        }

        return self::$loadedWithoutIni = array_values(array_map(strval(...), $loaded));
    }
}
