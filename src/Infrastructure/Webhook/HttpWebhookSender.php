<?php

declare(strict_types=1);

namespace App\Infrastructure\Webhook;

use App\Application\Webhook\WebhookSender;
use App\Application\Webhook\WebhookSendResult;
use App\Domain\Merchant\ApiCredentialRepository;
use App\Domain\Webhook\WebhookDelivery;
use App\Infrastructure\Security\CallbackSigner;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Posts webhooks to merchant servers, signed with the merchant's own API
 * secret so the receiving end can prove the notification came from us.
 */
final readonly class HttpWebhookSender implements WebhookSender
{
    public function __construct(
        private ClientInterface $http,
        private ApiCredentialRepository $credentials,
        private CallbackSigner $signer,
        private int $timeoutSeconds = 10,
    ) {
    }

    public function send(WebhookDelivery $delivery): WebhookSendResult
    {
        $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $secret = $this->secretFor($delivery);

        if ($secret === null) {
            return WebhookSendResult::rejected(
                null,
                'The merchant has no active API credential to sign the webhook with.',
            );
        }

        try {
            $response = $this->http->request('POST', $delivery->url, [
                'body' => $body,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'MiniPaymentGateway-Webhook/1.0',
                    CallbackSigner::HEADER_EVENT => $delivery->event,
                    CallbackSigner::HEADER_SIGNATURE => $this->signer->signBody($body, $secret),
                ],
                'timeout' => $this->timeoutSeconds,
                'connect_timeout' => 5,
                'http_errors' => false,
                // Following a redirect would leak the signature to whatever
                // host the merchant's server points at.
                'allow_redirects' => false,
            ]);

            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                return WebhookSendResult::accepted($status);
            }

            return WebhookSendResult::rejected(
                $status,
                sprintf('The merchant endpoint answered %d.', $status),
            );
        } catch (GuzzleException $e) {
            return WebhookSendResult::rejected(null, $e->getMessage());
        }
    }

    private function secretFor(WebhookDelivery $delivery): ?string
    {
        foreach ($this->credentials->findForMerchant($delivery->merchantId) as $credential) {
            if ($credential->isActive()) {
                return $credential->secret;
            }
        }

        return null;
    }
}
