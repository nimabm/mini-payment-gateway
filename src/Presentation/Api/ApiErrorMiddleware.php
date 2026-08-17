<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Domain\Shared\DomainException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Response;
use Throwable;

/**
 * Turns exceptions into responses.
 *
 * Domain exceptions carry a stable error code and become 4xx; everything else
 * is a bug, is logged with its stack trace, and becomes a generic 500. Internal
 * messages are never leaked to a merchant unless debug mode is on.
 */
final readonly class ApiErrorMiddleware implements MiddlewareInterface
{
    /** Maps domain error codes to the HTTP status that best describes them. */
    private const STATUS_MAP = [
        'merchant_suspended' => 403,
        'callback_url_not_allowed' => 422,
        'duplicate_order_id' => 409,
        'payment_not_found' => 404,
        'no_gateway_available' => 503,
        'payment_not_payable' => 409,
        'invalid_payment_state' => 409,
        'refund_exceeds_paid_amount' => 422,
        'unknown_driver' => 500,
    ];

    public function __construct(
        private LoggerInterface $logger,
        private bool $debug = false,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        try {
            return $handler->handle($request);
        } catch (HttpNotFoundException) {
            return $this->isApi($request)
                ? ApiResponse::error('not_found', 'No such endpoint.', 404)
                : new Response(404);
        } catch (DomainException $e) {
            $status = self::STATUS_MAP[$e->errorCode()] ?? 400;

            $this->logger->info('Rejected a request on a domain rule.', [
                'code' => $e->errorCode(),
                'path' => $request->getUri()->getPath(),
            ]);

            return $this->isApi($request)
                ? ApiResponse::error($e->errorCode(), $e->getMessage(), $status)
                : new Response($status);
        } catch (InvalidArgumentException $e) {
            // Almost always a malformed identifier in the URL.
            return $this->isApi($request)
                ? ApiResponse::error('invalid_request', $e->getMessage(), 400)
                : new Response(400);
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception.', [
                'exception' => $e->getMessage(),
                'class' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'path' => $request->getUri()->getPath(),
            ]);

            if (!$this->isApi($request)) {
                return new Response(500);
            }

            return ApiResponse::error(
                'internal_error',
                $this->debug ? $e->getMessage() : 'Something went wrong on our side.',
                500,
            );
        }
    }

    private function isApi(ServerRequestInterface $request): bool
    {
        return str_starts_with($request->getUri()->getPath(), '/api/');
    }
}
