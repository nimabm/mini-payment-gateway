<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Domain\Merchant\ApiCredential;
use App\Domain\Merchant\ApiCredentialRepository;
use App\Domain\Merchant\MerchantRepository;
use App\Domain\Shared\Clock;
use App\Infrastructure\Security\NonceStore;
use App\Infrastructure\Security\RateLimiter;
use App\Infrastructure\Security\RequestSigner;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authenticates merchant API calls.
 *
 * Four checks, in the order that fails cheapest first:
 *
 *   1. The key exists, is active, and its merchant is active.
 *   2. The caller is within its rate limit.
 *   3. The timestamp is inside the tolerance window.
 *   4. The signature matches, and the nonce has not been seen before.
 *
 * The authenticated merchant and credential are put on the request so
 * controllers never repeat any of this.
 */
final readonly class ApiAuthenticationMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE_MERCHANT = 'merchant';
    public const ATTRIBUTE_CREDENTIAL = 'credential';

    public function __construct(
        private ApiCredentialRepository $credentials,
        private MerchantRepository $merchants,
        private RequestSigner $signer,
        private NonceStore $nonces,
        private RateLimiter $rateLimiter,
        private Clock $clock,
        private int $toleranceSeconds,
        private int $rateLimitPerMinute,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $keyId = $request->getHeaderLine(RequestSigner::HEADER_KEY_ID);

        if ($keyId === '') {
            return ApiResponse::error('unauthenticated', 'Missing API key header.', 401);
        }

        $credential = $this->credentials->findByKeyId($keyId);

        if ($credential === null || !$credential->isActive()) {
            return ApiResponse::error('unauthenticated', 'Unknown or revoked API key.', 401);
        }

        if (!$this->rateLimiter->allow('api:' . $keyId, $this->rateLimitPerMinute)) {
            return ApiResponse::error('rate_limited', 'Too many requests.', 429);
        }

        $merchant = $this->merchants->find($credential->merchantId);

        if ($merchant === null) {
            return ApiResponse::error('unauthenticated', 'Unknown or revoked API key.', 401);
        }

        if (!$merchant->allowsRequestFrom($this->clientIp($request))) {
            return ApiResponse::error('ip_not_allowed', 'This IP address is not allowlisted.', 403);
        }

        $failure = $this->verifySignature($request, $credential);

        if ($failure !== null) {
            return $failure;
        }

        $credential->markUsed($this->clock->now());
        $this->credentials->save($credential);

        return $handler->handle(
            $request
                ->withAttribute(self::ATTRIBUTE_MERCHANT, $merchant)
                ->withAttribute(self::ATTRIBUTE_CREDENTIAL, $credential),
        );
    }

    private function verifySignature(
        ServerRequestInterface $request,
        ApiCredential $credential,
    ): ?ResponseInterface {
        $timestamp = $request->getHeaderLine(RequestSigner::HEADER_TIMESTAMP);
        $nonce = $request->getHeaderLine(RequestSigner::HEADER_NONCE);
        $signature = $request->getHeaderLine(RequestSigner::HEADER_SIGNATURE);

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return ApiResponse::error(
                'signature_missing',
                'Signed requests require the timestamp, nonce and signature headers.',
                401,
            );
        }

        $drift = abs($this->clock->now()->getTimestamp() - (int) $timestamp);

        if ($drift > $this->toleranceSeconds) {
            return ApiResponse::error(
                'signature_expired',
                sprintf(
                    'The request timestamp is %d seconds out; the tolerance is %d. Check the server clock.',
                    $drift,
                    $this->toleranceSeconds,
                ),
                401,
            );
        }

        $body = (string) $request->getBody();
        $request->getBody()->rewind();

        $valid = $this->signer->verify(
            $credential->secret,
            $request->getMethod(),
            $request->getUri()->getPath(),
            $timestamp,
            $nonce,
            $body,
            $signature,
        );

        if (!$valid) {
            return ApiResponse::error('signature_invalid', 'The request signature does not match.', 401);
        }

        // Claimed last: a replayed request must fail on the signature check
        // first, so a wrong signature never burns a legitimate nonce.
        if (!$this->nonces->claim($nonce, $credential->keyId)) {
            return ApiResponse::error('replayed_request', 'This nonce has already been used.', 409);
        }

        return null;
    }

    private function clientIp(ServerRequestInterface $request): ?string
    {
        $server = $request->getServerParams();
        $ip = $server['REMOTE_ADDR'] ?? null;

        return is_string($ip) ? $ip : null;
    }
}
