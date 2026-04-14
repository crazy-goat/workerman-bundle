<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Worker\ServerWorker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class ServerWorkerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/workerman-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        rmdir($this->tempDir);
    }

    private function createKernelFactory(): KernelFactory
    {
        $kernel = $this->createMock(KernelInterface::class);
        return new KernelFactory(
            fn() => $kernel,
            [],
        );
    }

    public function testMissingCertThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('local_cert');

        new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'https://0.0.0.0:8443',
                'local_pk' => $this->tempDir . '/key.pem',
            ],
        );
    }

    public function testMissingKeyThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('local_pk');

        new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'https://0.0.0.0:8443',
                'local_cert' => $this->tempDir . '/cert.pem',
            ],
        );
    }

    public function testUnreadableCertThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not readable');

        new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'https://0.0.0.0:8443',
                'local_cert' => '/nonexistent/cert.pem',
                'local_pk' => $this->tempDir . '/key.pem',
            ],
        );
    }

    public function testUnreadableKeyThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not readable');

        new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'https://0.0.0.0:8443',
                'local_cert' => $this->tempDir . '/cert.pem',
                'local_pk' => '/nonexistent/key.pem',
            ],
        );
    }

    public function testCorrectSslConfigurationDoesNotThrow(): void
    {
        $certFile = $this->tempDir . '/cert.pem';
        $keyFile = $this->tempDir . '/key.pem';

        $this->generateSelfSignedCert($certFile, $keyFile);

        $serverWorker = new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'https://0.0.0.0:8443',
                'local_cert' => $certFile,
                'local_pk' => $keyFile,
            ],
        );

        $this->assertInstanceOf(ServerWorker::class, $serverWorker);
    }

    public function testWssTransportWithValidSslConfigDoesNotThrow(): void
    {
        $certFile = $this->tempDir . '/cert.pem';
        $keyFile = $this->tempDir . '/key.pem';

        $this->generateSelfSignedCert($certFile, $keyFile);

        $serverWorker = new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'wss://0.0.0.0:8443',
                'local_cert' => $certFile,
                'local_pk' => $keyFile,
            ],
        );

        $this->assertInstanceOf(ServerWorker::class, $serverWorker);
    }

    private function generateSelfSignedCert(string $certFile, string $keyFile): void
    {
        $dn = ['countryName' => 'US', 'stateOrProvinceName' => 'Test', 'localityName' => 'TestCity', 'organizationName' => 'Test Org'];

        $privkey = \openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($privkey === false) {
            throw new \RuntimeException('Failed to generate private key');
        }

        $cert = \openssl_csr_new($dn, $privkey, ['digest_alg' => 'sha256']);
        if ($cert === false || $cert === true) {
            throw new \RuntimeException('Failed to generate CSR');
        }

        $x509 = \openssl_csr_sign($cert, null, $privkey, 365);
        if ($x509 === false) {
            throw new \RuntimeException('Failed to sign certificate');
        }

        if (!\openssl_pkey_export_to_file($privkey, $keyFile)) {
            throw new \RuntimeException('Failed to export private key');
        }

        if (!\openssl_x509_export_to_file($x509, $certFile)) {
            throw new \RuntimeException('Failed to export certificate');
        }
    }
}
