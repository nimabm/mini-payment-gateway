<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * The single shape every API response takes.
 *
 * Success:  {"data": {...}}
 * Failure:  {"error": {"code": "...", "message": "..."}}
 *
 * One shape means a merchant module can branch on the presence of `error` and
 * never has to special-case an endpoint.
 */
final class ApiResponse
{
    /**
     * @param array<string, mixed> $data
     */
    public static function success(array $data, int $status = 200): ResponseInterface
    {
        return self::json(['data' => $data], $status);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(
        string $code,
        string $message,
        int $status = 400,
        array $details = [],
    ): ResponseInterface {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return self::json(['error' => $error], $status);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function json(array $payload, int $status): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
