<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Infrastructure\Security\RequestSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestSigner::class)]
final class RequestSignerTest extends TestCase
{
    private RequestSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new RequestSigner();
    }

    #[Test]
    public function a_correctly_signed_request_verifies(): void
    {
        $signature = $this->sign();

        self::assertTrue($this->signer->verify(
            'sk_secret',
            'POST',
            '/api/v1/payments',
            '1723800000',
            'nonce-1',
            '{"amount":1000}',
            $signature,
        ));
    }

    /**
     * Each of these is a thing an attacker would want to change. All of them
     * are inside the signature, so all of them must break it.
     */
    #[Test]
    public function tampering_with_any_signed_part_breaks_the_signature(): void
    {
        $signature = $this->sign();

        $mutations = [
            'body' => ['sk_secret', 'POST', '/api/v1/payments', '1723800000', 'nonce-1', '{"amount":9999}'],
            'path' => ['sk_secret', 'POST', '/api/v1/refunds', '1723800000', 'nonce-1', '{"amount":1000}'],
            'method' => ['sk_secret', 'DELETE', '/api/v1/payments', '1723800000', 'nonce-1', '{"amount":1000}'],
            'timestamp' => ['sk_secret', 'POST', '/api/v1/payments', '1723800099', 'nonce-1', '{"amount":1000}'],
            'nonce' => ['sk_secret', 'POST', '/api/v1/payments', '1723800000', 'nonce-2', '{"amount":1000}'],
            'secret' => ['sk_other', 'POST', '/api/v1/payments', '1723800000', 'nonce-1', '{"amount":1000}'],
        ];

        foreach ($mutations as $what => $arguments) {
            self::assertFalse(
                $this->signer->verify(...[...$arguments, $signature]),
                sprintf('Changing the %s should have invalidated the signature.', $what),
            );
        }
    }

    #[Test]
    public function the_method_is_case_insensitive(): void
    {
        self::assertSame(
            $this->signer->sign('sk_secret', 'post', '/x', '1', 'n', ''),
            $this->signer->sign('sk_secret', 'POST', '/x', '1', 'n', ''),
        );
    }

    private function sign(): string
    {
        return $this->signer->sign(
            'sk_secret',
            'POST',
            '/api/v1/payments',
            '1723800000',
            'nonce-1',
            '{"amount":1000}',
        );
    }
}
