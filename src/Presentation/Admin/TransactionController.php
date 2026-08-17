<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Payment\SettlePaymentHandler;
use App\Application\Reporting\ReportingRepository;
use App\Application\Reporting\TransactionRow;
use App\Domain\Gateway\GatewayRepository;
use App\Domain\Merchant\MerchantRepository;
use App\Domain\Payment\PaymentId;
use App\Domain\Payment\PaymentRepository;
use App\Domain\Payment\PaymentStatus;
use App\Domain\Shared\Clock;
use App\Domain\Webhook\WebhookRepository;
use App\Infrastructure\Audit\AuditLogger;
use App\Presentation\Support\DateFormatter;
use App\Presentation\Support\TemplateRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * The transaction list, one transaction's full story, and the manual actions an
 * operator sometimes has to take when a PSP misbehaves.
 */
final readonly class TransactionController
{
    use ResolvesActor;

    public function __construct(
        private ReportingRepository $reporting,
        private PaymentRepository $payments,
        private MerchantRepository $merchants,
        private GatewayRepository $gateways,
        private WebhookRepository $webhooks,
        private SettlePaymentHandler $settle,
        private TransactionFilterFactory $filters,
        private TemplateRenderer $renderer,
        private DateFormatter $dates,
        private AuditLogger $audit,
        private Session $session,
        private Clock $clock,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $filter = $this->filters->fromRequest($request);

        return $this->renderer->render(new Response(), 'admin/transactions/index.html.twig', [
            'results' => $this->reporting->search($filter),
            'summary' => $this->reporting->summarise($filter),
            'filter' => $filter,
            'merchants' => $this->merchants->all(),
            'gateways' => $this->gateways->all(),
            'statuses' => PaymentStatus::cases(),
            'query' => $request->getQueryParams(),
            'csrfToken' => $this->session->csrfToken(),
        ]);
    }

    /**
     * @param array<string, string> $arguments
     */
    public function show(ServerRequestInterface $request, ResponseInterface $slimResponse, array $arguments): ResponseInterface
    {
        $payment = $this->payments->find(PaymentId::fromString($arguments['id']));

        if ($payment === null) {
            return (new Response(302))->withHeader('Location', '/admin/transactions');
        }

        $gateways = [];

        foreach ($payment->attempts() as $attempt) {
            $gateways[$attempt->gatewayId->value] = $this->gateways->find($attempt->gatewayId);
        }

        return $this->renderer->render(new Response(), 'admin/transactions/show.html.twig', [
            'payment' => $payment,
            'merchant' => $this->merchants->find($payment->merchantId),
            'gateways' => $gateways,
            'webhooks' => $this->webhooks->findForPayment($payment->id),
            'csrfToken' => $this->session->csrfToken(),
        ]);
    }

    /**
     * Asks the PSP again about a payment the operator is unsure of. This is the
     * button for "the customer says they paid but the order is not marked".
     *
     * @param array<string, string> $arguments
     */
    public function verify(ServerRequestInterface $request, ResponseInterface $slimResponse, array $arguments): ResponseInterface
    {
        $paymentId = PaymentId::fromString($arguments['id']);
        $result = $this->settle->handle($paymentId, viaInquiry: true);

        $this->audit->record(
            $this->actor($request),
            'payment.manual_verify',
            $paymentId->value,
            ['outcome' => $result->outcome->value],
            $this->ip($request),
        );

        $this->session->flash(
            $result->isSuccessful() ? 'transactions.verified' : 'transactions.verify_failed',
            $result->isSuccessful() ? 'success' : 'danger',
        );

        return (new Response(302))
            ->withHeader('Location', '/admin/transactions/' . $paymentId->value);
    }

    /**
     * Streams the current filter's rows as CSV.
     *
     * A UTF-8 BOM is written first, without which Excel renders Persian text as
     * mojibake — a small detail that decides whether the export is usable.
     */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $filter = $this->filters->fromRequest($request);
        $rows = $this->reporting->export($filter);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return new Response(500);
        }

        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            'Payment ID', 'Order ID', 'Website', 'Gateway', 'Provider', 'Status',
            'Amount', 'Currency', 'Transaction ID', 'Reference', 'Card',
            'Payer email', 'Payer mobile', 'Attempts', 'Failure reason',
            'Created at', 'Paid at',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, $this->toCsvRow($row));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $response = new Response();
        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader(
                'Content-Disposition',
                sprintf(
                    'attachment; filename="transactions-%s.csv"',
                    $this->clock->now()->format('Y-m-d'),
                ),
            );
    }

    /**
     * @return list<string>
     */
    private function toCsvRow(TransactionRow $row): array
    {
        return [
            $row->paymentId,
            $row->orderId,
            $row->merchantName,
            $row->gatewayLabel ?? '',
            $row->driver ?? '',
            $row->status->value,
            (string) $row->amount->amount,
            $row->amount->currency->value,
            $row->transactionId ?? '',
            $row->reference ?? '',
            $row->cardPan ?? '',
            $row->payerEmail ?? '',
            $row->payerMobile ?? '',
            (string) $row->attempts,
            $row->failureReason ?? '',
            // Both calendars in the export: the operator's calendar for reading,
            // ISO for anything downstream that has to parse it.
            $this->dates->dateTime($row->createdAt),
            $row->paidAt?->format(DATE_ATOM) ?? '',
        ];
    }
}
