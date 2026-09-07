<?php

namespace App\Messaging;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Thin wrapper around the rdkafka high-level consumer.
 *
 * Manual commit only: the caller commits after a message has been
 * handled successfully, so a crash mid-processing replays the message.
 */
final class KafkaConsumer
{
    private \RdKafka\KafkaConsumer $consumer;

    public function __construct(
        #[Autowire('%env(KAFKA_BROKERS)%')] string $brokers,
        #[Autowire('%env(KAFKA_CONSUMER_GROUP)%')] string $group,
    ) {
        $conf = new \RdKafka\Conf();
        $conf->set('bootstrap.servers', $brokers);
        $conf->set('group.id', $group);
        $conf->set('client.id', $group);
        $conf->set('auto.offset.reset', 'earliest');
        $conf->set('enable.auto.commit', 'false');
        $conf->set('enable.partition.eof', 'false');

        $this->consumer = new \RdKafka\KafkaConsumer($conf);
    }

    /**
     * @param string[] $topics
     */
    public function subscribe(array $topics): void
    {
        $this->consumer->subscribe($topics);
    }

    public function poll(int $timeoutMs = 1000): ?\RdKafka\Message
    {
        $message = $this->consumer->consume($timeoutMs);

        return match ($message->err) {
            RD_KAFKA_RESP_ERR_NO_ERROR => $message,
            RD_KAFKA_RESP_ERR__PARTITION_EOF, RD_KAFKA_RESP_ERR__TIMED_OUT => null,
            default => throw new \RuntimeException($message->errstr(), $message->err),
        };
    }

    public function commit(\RdKafka\Message $message): void
    {
        $this->consumer->commit($message);
    }

    public function close(): void
    {
        $this->consumer->close();
    }
}
