<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Phar;

/**
 * Composes a standalone executable from a phpmicro SFX, optional custom
 * php.ini header, and a PHAR payload.
 *
 * Uses stream copying so memory stays bounded regardless of PHAR size.
 *
 * @internal
 */
final class BinaryComposer
{
    private const MAGIC_BYTES = "\xfd\xf6\x69\xe6";

    /**
     * Compose [SFX][optional INI header][PHAR] into a single executable at $binPath.
     *
     * @throws \RuntimeException when any file operation fails
     */
    public function compose(string $sfxPath, string $pharPath, string $binPath, ?string $customIni = null): void
    {
        if (!is_file($sfxPath)) {
            throw new \RuntimeException(sprintf('SFX file not found: %s', $sfxPath));
        }
        if (!is_file($pharPath)) {
            throw new \RuntimeException(sprintf('PHAR file not found: %s', $pharPath));
        }

        if (file_exists($binPath)) {
            unlink($binPath);
        }

        $out = fopen($binPath, 'wb');
        if (!is_resource($out)) {
            throw new \RuntimeException(sprintf('Unable to open "%s" for writing.', $binPath));
        }

        try {
            $this->copyStream($sfxPath, $out);

            if (is_string($customIni) && $customIni !== '') {
                fwrite($out, self::MAGIC_BYTES);
                fwrite($out, pack('N', strlen($customIni)));
                fwrite($out, $customIni);
            }

            $this->copyStream($pharPath, $out);
        } finally {
            fclose($out);
        }

        chmod($binPath, 0755);
    }

    /**
     * @param resource $out
     */
    private function copyStream(string $source, $out): void
    {
        $in = fopen($source, 'rb');
        if (!is_resource($in)) {
            throw new \RuntimeException(sprintf('Unable to open "%s" for reading.', $source));
        }

        try {
            if (stream_copy_to_stream($in, $out) === false) {
                throw new \RuntimeException(sprintf('Failed to stream "%s" into binary.', $source));
            }
        } finally {
            fclose($in);
        }
    }
}
