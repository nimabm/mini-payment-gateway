<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Reporting\ReportingRepository;
use App\Application\Reporting\TransactionFilter;
use App\Application\Shared\UrlBuilder;
use App\Domain\Payment\PaymentStatus;
use App\Presentation\Support\TemplateRenderer;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * The panel's landing page: what happened today, this week and this month, plus
 * the two queues an operator needs to notice early — payments stuck awaiting
 * verification, and webhooks that are not getting through.
 */
final readonly class DashboardController
{
    public function __construct(
        private ReportingRepository $reporting,
        private TemplateRenderer $renderer,
        private UrlBuilder $urls,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $today = new TransactionFilter(from: $now->modify('-1 day'));
        $week = new TransactionFilter(from: $now->modify('-7 days'));
        $month = new TransactionFilter(from: $now->modify('-30 days'));

        return $this->renderer->render(new Response(), 'admin/dashboard.html.twig', [
            'today' => $this->reporting->summarise($today),
            'week' => $this->reporting->summarise($week),
            'month' => $this->reporting->summarise($month),
            'trend' => $this->reporting->dailyBreakdown($month),
            'byMerchant' => $this->reporting->merchantBreakdown($month),
            'byGateway' => $this->reporting->gatewayBreakdown($month),
            'failures' => $this->reporting->topFailureReasons($month, 5),
            'stuck' => $this->reporting->search(new TransactionFilter(
                statuses: [PaymentStatus::AwaitingVerification],
                perPage: 5,
            )),
            'recentFailures' => $this->reporting->search(new TransactionFilter(
                statuses: [PaymentStatus::Failed],
                from: $now->modify('-7 days'),
                perPage: 5,
            )),
            'urlMismatch' => $this->urlMismatch($request),
        ]);
    }

    /**
     * The one misconfiguration the startup guard cannot catch: a server left on
     * `APP_ENV=local`, where the strict checks do not run.
     *
     * Reaching the panel on a host that is not the one in APP_URL is proof on
     * its own — no guessing about environments. Payments would be sent to banks
     * carrying a return address that leads nowhere.
     */
    private function urlMismatch(ServerRequestInterface $request): ?string
    {
        $configured = parse_url($this->urls->baseUrl(), PHP_URL_HOST);
        $actual = $request->getUri()->getHost();

        if (!is_string($configured) || $configured === '' || $actual === '') {
            return null;
        }

        return strcasecmp($configured, $actual) === 0 ? null : $this->urls->baseUrl();
    }
}
