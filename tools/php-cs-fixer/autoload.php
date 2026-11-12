<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    static $pharSource = null;

    $toolsPrefix = 'CrazyGoat\\WorkermanBundle\\Tools\\PhpCsFixer\\';
    if (str_starts_with($class, $toolsPrefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, \strlen($toolsPrefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }

        return;
    }

    if (!str_starts_with($class, 'PhpCsFixer\\')) {
        return;
    }

    if ($pharSource === null) {
        $projectDir = \dirname(__DIR__, 2);
        $installPath = null;
        if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('php-cs-fixer/shim')) {
            $installPath = \Composer\InstalledVersions::getInstallPath('php-cs-fixer/shim');
        }

        if ($installPath === null) {
            $vendorDir = getenv('COMPOSER_VENDOR_DIR');
            if (!\is_string($vendorDir) || $vendorDir === '') {
                $composer = json_decode((string) @file_get_contents($projectDir . '/composer.json'), true);
                $configured = \is_array($composer) && \is_array($composer['config'] ?? null) ? ($composer['config']['vendor-dir'] ?? null) : null;
                $vendorDir = \is_string($configured) && $configured !== '' ? $configured : 'vendor';
            }
            if (!str_starts_with($vendorDir, '/')) {
                $vendorDir = $projectDir . '/' . $vendorDir;
            }
            $installPath = $vendorDir . '/php-cs-fixer/shim';
        }

        $phar = rtrim($installPath, '/') . '/php-cs-fixer.phar';
        if (!is_file($phar)) {
            throw new \RuntimeException(\sprintf('tools/php-cs-fixer/autoload.php: php-cs-fixer/shim is not installed (expected %s); run "composer install".', $phar));
        }

        $pharSource = 'phar://' . $phar . '/src/';
    }

    $file = $pharSource . str_replace('\\', '/', substr($class, \strlen('PhpCsFixer\\'))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
