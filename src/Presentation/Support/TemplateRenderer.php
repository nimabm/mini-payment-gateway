<?php

declare(strict_types=1);

namespace App\Presentation\Support;

use Psr\Http\Message\ResponseInterface;
use Twig\Environment;

/**
 * Renders a Twig template into a PSR-7 response, always with the panel context
 * available so no template has to be handed the locale explicitly.
 */
final readonly class TemplateRenderer
{
    public function __construct(
        private Environment $twig,
        private PanelContext $context,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(
        ResponseInterface $response,
        string $template,
        array $data = [],
    ): ResponseInterface {
        $html = $this->twig->render($template, $data + [
            'locale' => $this->context->locale(),
            'direction' => $this->context->direction(),
            'currentUser' => $this->context->user(),
        ]);

        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
