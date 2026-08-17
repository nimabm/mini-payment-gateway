<?php

declare(strict_types=1);

namespace App\Presentation\Support;

use App\Domain\Payment\PaymentStatus;
use App\Domain\Shared\Money;
use App\Domain\Webhook\WebhookStatus;
use DateTimeImmutable;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The bridge between templates and the presentation services.
 *
 * Templates call `t()`, `datetime()` and `money()` and stay unaware of which
 * language or calendar is active, so adding a locale never means touching a
 * template.
 */
final class AppTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly Translator $translator,
        private readonly DateFormatter $dates,
        private readonly PanelContext $context,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', $this->translate(...)),
            new TwigFunction('panel', fn (): PanelContext => $this->context),
            new TwigFunction('status_label', $this->statusLabel(...)),
            new TwigFunction('status_tone', $this->statusTone(...)),
            new TwigFunction('webhook_status_label', $this->webhookStatusLabel(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('date_only', $this->dateOnly(...)),
            new TwigFilter('datetime', $this->dateTime(...)),
            new TwigFilter('short_date', $this->shortDate(...)),
            new TwigFilter('money', $this->money(...)),
            new TwigFilter('amount', $this->amount(...)),
            new TwigFilter('digits', $this->digits(...)),
            new TwigFilter('percent', $this->percent(...)),
            new TwigFilter('pretty_json', $this->prettyJson(...)),
        ];
    }

    /**
     * @param array<string, string|int|float> $replacements
     */
    public function translate(string $key, array $replacements = []): string
    {
        return $this->translator->translate($key, $replacements);
    }

    public function dateOnly(?DateTimeImmutable $value): string
    {
        return $this->dates->digits($this->dates->date($value));
    }

    public function dateTime(?DateTimeImmutable $value): string
    {
        return $this->dates->digits($this->dates->dateTime($value));
    }

    public function shortDate(?DateTimeImmutable $value): string
    {
        return $this->dates->digits($this->dates->shortDate($value));
    }

    public function money(?Money $money): string
    {
        if ($money === null) {
            return '—';
        }

        return $this->dates->digits($money->format()) . ' ' . $this->currencyLabel($money);
    }

    /**
     * Formats a bare integer minor-unit amount when the currency is known from
     * context, e.g. inside a report row.
     */
    public function amount(int $value, string $currency = ''): string
    {
        $formatted = $this->dates->digits(number_format($value));

        return $currency === '' ? $formatted : $formatted . ' ' . $currency;
    }

    public function digits(string|int|float $value): string
    {
        return $this->dates->digits((string) $value);
    }

    public function percent(float $value): string
    {
        return $this->dates->digits(number_format($value, 1)) . '%';
    }

    /**
     * @param array<string, mixed> $value
     */
    public function prettyJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '{}';
    }

    public function statusLabel(PaymentStatus|string $status): string
    {
        $value = $status instanceof PaymentStatus ? $status->value : $status;

        return $this->translator->translate('status.' . $value);
    }

    /** Maps a status to a CSS tone class, so templates hold no business logic. */
    public function statusTone(PaymentStatus|string $status): string
    {
        $value = $status instanceof PaymentStatus ? $status : PaymentStatus::from($status);

        return match (true) {
            $value->isSuccessful() => 'success',
            $value->isOpen() => 'pending',
            default => 'danger',
        };
    }

    public function webhookStatusLabel(WebhookStatus|string $status): string
    {
        $value = $status instanceof WebhookStatus ? $status->value : $status;

        return $this->translator->translate('webhooks.status.' . $value);
    }

    private function currencyLabel(Money $money): string
    {
        return $this->translator->translate('currency.' . strtolower($money->currency->value));
    }
}
