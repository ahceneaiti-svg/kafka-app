<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class WelcomeMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $from,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     */
    public function sendWelcome(array $user): void
    {
        $to = (string) ($user['email'] ?? '');
        if ('' === $to) {
            throw new \InvalidArgumentException('USER_CREATED event has no email.');
        }

        $email = (new Email())
            ->from($this->from)
            ->to($to)
            ->subject('Bienvenue sur User Platform')
            ->text(sprintf(
                "Bonjour %s %s,\n\nVotre compte a bien ete cree.\n\nIdentifiant : %s\nEmail : %s\n\nA bientot,\nL'equipe User Platform\n",
                (string) ($user['firstName'] ?? ''),
                (string) ($user['lastName'] ?? ''),
                (string) ($user['id'] ?? ''),
                $to,
            ));

        $this->mailer->send($email);

        $this->logger->info('Welcome email sent', ['to' => $to, 'userId' => $user['id'] ?? null]);
    }
}
