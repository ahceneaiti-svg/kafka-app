<?php

namespace App\Service;

use App\Dto\CreateUserRequest;
use App\Entity\User;
use App\Messaging\KafkaProducer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class UserService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KafkaProducer $producer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function create(CreateUserRequest $request): User
    {
        $user = new User($request->email, $request->firstName, $request->lastName);

        $this->em->persist($user);
        $this->em->flush();

        $this->producer->publish('USER_CREATED', (string) $user->getId(), [
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'createdAt' => $user->getCreatedAt()->format(DATE_ATOM),
        ]);

        $this->logger->info('User created', ['userId' => (string) $user->getId()]);

        return $user;
    }

    public function delete(User $user): void
    {
        $this->em->remove($user);
        $this->em->flush();
    }
}
