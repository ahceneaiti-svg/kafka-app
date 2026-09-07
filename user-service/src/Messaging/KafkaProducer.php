<?php

namespace App\Messaging;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Thin wrapper around the rdkafka producer.
 *
 * Publishes domain events as JSON envelopes:
 *   { "eventType": "...", "occurredAt": "...", "data": { ... } }
 */
final class KafkaProducer
{
    private \RdKafka\Producer $producer;
    private \RdKafka\ProducerTopic $topic;

    public function __construct(
        #[Autowire('%env(KAFKA_BROKERS)%')] string $brokers,
        #[Autowire('%env(KAFKA_TOPIC)%')] private readonly string $topicName,
        private readonly LoggerInterface $logger,
    ) {
        $conf = new \RdKafka\Conf();
        $conf->set('bootstrap.servers', $brokers);
        $conf->set('socket.timeout.ms', '3000');
        $conf->set('message.timeout.ms', '10000');
        $conf->set('enable.idempotence', 'true');
        $conf->set('compression.codec', 'snappy');

        $this->producer = new \RdKafka\Producer($conf);
        $this->topic = $this->producer->newTopic($this->topicName);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function publish(string $eventType, string $key, array $data): void
    {
        $payload = json_encode([
            'eventType' => $eventType,
            'occurredAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'data' => $data,
        ], JSON_THROW_ON_ERROR);

        $this->topic->producev(
            RD_KAFKA_PARTITION_UA,
            0,
            $payload,
            $key,
            ['eventType' => $eventType],
        );

        $this->producer->poll(0);

        $flushResult = RD_KAFKA_RESP_ERR_NO_ERROR;
        for ($attempt = 0; $attempt < 10 && $this->producer->getOutQLen() > 0; ++$attempt) {
            $flushResult = $this->producer->flush(1000);
        }

        if (RD_KAFKA_RESP_ERR_NO_ERROR !== $flushResult || $this->producer->getOutQLen() > 0) {
            $this->logger->error('Kafka flush did not complete', [
                'topic' => $this->topicName,
                'eventType' => $eventType,
                'key' => $key,
            ]);

            throw new \RuntimeException(sprintf('Unable to publish "%s" to Kafka topic "%s".', $eventType, $this->topicName));
        }

        $this->logger->info('Kafka event published', [
            'topic' => $this->topicName,
            'eventType' => $eventType,
            'key' => $key,
        ]);
    }
}
