<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

final class IsolatedPhp
{
    private const REQUIRED = ['pcntl', 'posix'];

    /** @var list<string>|null */
    private static ?array $loadedWithoutIni = null;

    /**
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
     * @param list<string> $loadedHere
     * @param list<string> $loadedWithoutIni
     * @return list<string>
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
