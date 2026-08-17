<?php

declare(strict_types=1);

namespace App\Console;

use Psr\Log\LoggerInterface;

/**
 * The long-running worker: one pass, sleep, repeat.
 *
 * Responds to SIGTERM and SIGINT by finishing the pass it is in and then
 * exiting, so `docker compose down` never kills a job halfway through a webhook
 * delivery.
 */
final class WorkerCommand implements Command
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly WorkerOnceCommand $pass,
        private readonly LoggerInterface $logger,
        private readonly int $intervalSeconds = 30,
    ) {
    }

    public function __invoke(array $arguments): int
    {
        $this->listenForSignals();

        $this->logger->info('Worker started.', ['interval' => $this->intervalSeconds]);

        // A do/while, because the first pass always runs: a signal cannot have
        // arrived before the loop is entered.
        do {
            ($this->pass)([]);

            // Slept in one-second slices so a shutdown signal is acted on
            // promptly instead of after a full interval.
            for ($i = 0; $i < $this->intervalSeconds && !$this->shouldStop(); $i++) {
                sleep(1);

                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            }
        } while (!$this->shouldStop());

        $this->logger->info('Worker stopped cleanly.');

        return 0;
    }

    /**
     * Read through a method rather than the property directly: the flag is only
     * ever set from a signal handler, which static analysis cannot see, and it
     * would otherwise conclude the loop can never end.
     */
    private function shouldStop(): bool
    {
        return $this->shouldStop;
    }

    private function listenForSignals(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $stop = function (): void {
            $this->shouldStop = true;
        };

        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
}
