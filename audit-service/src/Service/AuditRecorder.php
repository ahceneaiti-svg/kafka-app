<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Reacts to domain events by writing an immutable audit line.
 *
 * Here it just emits structured JSON to stdout / the logger; a real
 * service would append to an append-only store or a separate database.
 */
final class AuditRecorder
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function record(string $eventType, array $data): void
    {
        $line = json_encode([
            'recordedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'eventType' => $eventType,
            'subjectId' => $data['id'] ?? null,
            'email' => $data['email'] ?? null,
        ], JSON_THROW_ON_ERROR);

        fwrite(\STDOUT, 'AUDIT '.$line.\PHP_EOL);
        $this->logger->info('audit.record', ['eventType' => $eventType, 'subjectId' => $data['id'] ?? null]);
    }
}
