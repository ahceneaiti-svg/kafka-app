<?php

namespace App\Command;

use App\Messaging\KafkaConsumer;
use App\Service\AuditRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:consume-user-events',
    description: 'Consume user events from Kafka and write audit records.',
)]
final class ConsumeUserEventsCommand extends Command implements SignalableCommandInterface
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly KafkaConsumer $consumer,
        private readonly AuditRecorder $recorder,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(KAFKA_TOPIC)%')] private readonly string $topic,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->consumer->subscribe([$this->topic]);
        $io->success(sprintf('audit-service listening on topic "%s"', $this->topic));

        while (!$this->shouldStop) {
            try {
                $message = $this->consumer->poll(1000);
            } catch (\RuntimeException $e) {
                $this->logger->error('Kafka poll failed', ['error' => $e->getMessage()]);
                usleep(500_000);
                continue;
            }

            if (null === $message) {
                continue;
            }

            try {
                $event = json_decode((string) $message->payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->logger->error('Skipping malformed payload', ['error' => $e->getMessage()]);
                $this->consumer->commit($message);
                continue;
            }

            if (\is_array($event) && \is_string($event['eventType'] ?? null)) {
                try {
                    $this->recorder->record($event['eventType'], $event['data'] ?? []);
                } catch (\Throwable $e) {
                    $this->logger->error('Handler failed, will retry', ['error' => $e->getMessage()]);
                    usleep(1_000_000);
                    continue;
                }
            }

            $this->consumer->commit($message);
        }

        $this->consumer->close();
        $io->writeln('<comment>audit-service stopped</comment>');

        return Command::SUCCESS;
    }

    public function getSubscribedSignals(): array
    {
        return [\SIGINT, \SIGTERM];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->shouldStop = true;

        return false;
    }
}
