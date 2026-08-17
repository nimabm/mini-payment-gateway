<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Reporting\ReportingRepository;
use App\Domain\Gateway\GatewayRepository;
use App\Domain\Merchant\MerchantRepository;
use App\Presentation\Support\TemplateRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Every report on one page, driven by one filter: daily trend, per-website
 * breakdown, gateway comparison, failure reasons and busiest hours.
 *
 * Keeping them together rather than on five separate pages means changing the
 * period once answers every question at that period.
 */
final readonly class ReportController
{
    public function __construct(
        private ReportingRepository $reporting,
        private MerchantRepository $merchants,
        private GatewayRepository $gateways,
        private TransactionFilterFactory $filters,
        private TemplateRenderer $renderer,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $filter = $this->filters->withDefaultPeriod($request);

        return $this->renderer->render(new Response(), 'admin/reports.html.twig', [
            'filter' => $filter,
            'summary' => $this->reporting->summarise($filter),
            'daily' => $this->reporting->dailyBreakdown($filter),
            'byMerchant' => $this->reporting->merchantBreakdown($filter),
            'byGateway' => $this->reporting->gatewayBreakdown($filter),
            'failures' => $this->reporting->topFailureReasons($filter, 10),
            'hourly' => $this->reporting->hourlyDistribution($filter),
            'merchants' => $this->merchants->all(),
            'gateways' => $this->gateways->all(),
            'query' => $request->getQueryParams(),
        ]);
    }
}
