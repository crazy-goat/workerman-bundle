<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http;

use CrazyGoat\WorkermanBundle\Exception\ClientInputExceptionInterface;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\FileCleanupState;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use CrazyGoat\WorkermanBundle\Middleware\StaticFilesMiddleware;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use CrazyGoat\WorkermanBundle\Utils;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Response as WorkermanResponse;

final class HttpRequestHandler implements StaticFileHandlerInterface, MiddlewareDispatchInterface
{
    /** @var MiddlewareInterface[] */
    private array $middlewares = [];
    /** @var array<string, mixed> */
    private array $staticFileConfig = [];
    private ?StaticFilesMiddleware $staticFilesMiddleware = null;

    private ?\Closure $pipeline = null;

    public function __construct(
        private readonly SymfonyController         $controller,
        private readonly RebootStrategyInterface   $rebootStrategy,
        private readonly ?LoggerInterface          $logger = null,
    ) {
    }

    public function withMiddlewares(MiddlewareInterface ...$middlewares): self
    {
        $this->middlewares = $middlewares;
        $this->pipeline = null;

        return $this;
    }

    public function withRootDirectory(?string $rootDirectory): self
    {
        if ($rootDirectory === null) {
            return $this;
        }
        $allowedExtensions = $this->staticFileConfig['allowed_extensions'] ?? [];
        $this->staticFilesMiddleware = new StaticFilesMiddleware(rtrim($rootDirectory, '/'), $allowedExtensions);
        $this->pipeline = null;

        return $this;
    }

    public function withStaticFileConfig(array $staticFileConfig): self
    {
        $this->staticFileConfig = $staticFileConfig;
        return $this;
    }

    private function getPipeline(): \Closure
    {
        if ($this->pipeline instanceof \Closure) {
            return $this->pipeline;
        }

        $middlewares = $this->middlewares;
        if ($this->staticFilesMiddleware instanceof StaticFilesMiddleware) {
            $middlewares[] = $this->staticFilesMiddleware;
        }

        $this->pipeline = fn(Request $request, callable $controller): Http\Response
            => (new MiddlewareDispatcher($middlewares, $controller))($request);

        return $this->pipeline;
    }

    private function sendResponse(TcpConnection $connection, Http\Response $response, Request $request): void
    {
        $responseAlreadySent = $connection->context instanceof \stdClass
            && isset($connection->context->responseSentDirectly);
        if ($responseAlreadySent) {
            unset($connection->context->responseSentDirectly);

            return;
        }

        $response->withProtocolVersion($request->protocolVersion());
        if ($this->shouldCloseConnection($request)) {
            $response->header('Connection', 'close');
        }

        $connection->send(Http::encode($response, $connection), true);

        if ($connection->context instanceof \stdClass
            && ($connection->context->pendingCleanup ?? null) instanceof FileCleanupState
        ) {
            $connection->context->pendingCleanup->release($connection, $this->logger ?? new NullLogger());
        }
    }

    private function doTerminate(string $errorPrefix = 'Kernel termination failed'): void
    {
        try {
            $this->controller->terminateIfNeeded();
        } catch (\Throwable $e) {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->error($errorPrefix, [
                    'exception' => $e,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            } else {
                error_log(sprintf(
                    '%s: %s in %s:%d',
                    $errorPrefix,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                ));
            }
        }
    }

    private function shouldCloseConnection(Request $request): bool
    {
        return ConnectionIntent::shouldClose($request);
    }

    private function isClientError(\Throwable $e): bool
    {
        return $e instanceof ClientInputExceptionInterface;
    }

    private function buildErrorResponse(bool $clientError): WorkermanResponse
    {
        if ($clientError) {
            return new WorkermanResponse(400, [], 'Bad Request');
        }

        return new WorkermanResponse(500, [], 'Internal Server Error');
    }

    private function logThrowable(\Throwable $e, bool $clientError): void
    {
        if ($this->logger instanceof LoggerInterface) {
            if ($clientError) {
                $this->logger->debug('Client request rejected: ' . $e->getMessage(), [
                    'exception' => $e,
                ]);
            } else {
                $this->logger->error('Request lifecycle failed', [
                    'exception' => $e,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        } elseif (!$clientError) {
            error_log(sprintf(
                'Request lifecycle failed: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));
        }
    }

    public function __invoke(TcpConnection $connection, Request $request): void
    {
        $controllerCall = fn(Request $input): Http\Response => ($this->controller)($input, $connection);
        $pipeline = $this->getPipeline();

        try {
            $response = $pipeline($request, $controllerCall);
            $this->sendResponse($connection, $response, $request);
        } catch (\Throwable $e) {
            $clientError = $this->isClientError($e);
            $this->logThrowable($e, $clientError);
            try {
                if ($connection->context instanceof \stdClass && isset($connection->context->responseSentDirectly)) {
                    unset($connection->context->responseSentDirectly);
                    $connection->close();
                } else {
                    $this->sendResponse($connection, $this->buildErrorResponse($clientError), $request);
                }
            } catch (\Throwable $sendError) {
                $this->logThrowable($sendError, false);
                $connection->close();
            }
        }

        $this->doTerminate();

        if ($this->shouldCloseConnection($request)) {
            $connection->close();
        }

        if ($this->rebootStrategy->shouldReboot()) {
            Utils::reload();
        }
    }
}
