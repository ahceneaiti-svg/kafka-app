<?php

namespace App\Controller;

use App\Dto\CreateUserRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/users')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly UserService $users,
        private readonly UserRepository $repository,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'users_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        if (!\is_array($body)) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $dto = new CreateUserRequest(
            email: (string) ($body['email'] ?? ''),
            firstName: (string) ($body['firstName'] ?? ''),
            lastName: (string) ($body['lastName'] ?? ''),
        );

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            $details = [];
            foreach ($violations as $violation) {
                $details[$violation->getPropertyPath()][] = $violation->getMessage();
            }

            return $this->json(['error' => 'Validation failed', 'details' => $details], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (null !== $this->repository->findOneBy(['email' => $dto->email])) {
            return $this->json(['error' => 'Email already in use'], Response::HTTP_CONFLICT);
        }

        $user = $this->users->create($dto);

        return $this->json($this->serialize($user), Response::HTTP_CREATED);
    }

    #[Route('', name: 'users_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $users = $this->repository->findBy([], ['createdAt' => 'DESC']);

        return $this->json(array_map($this->serialize(...), $users));
    }

    #[Route('/{id}', name: 'users_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $user = $this->find($id);
        if (null === $user) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serialize($user));
    }

    #[Route('/{id}', name: 'users_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $user = $this->find($id);
        if (null === $user) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $this->users->delete($user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function find(string $id): ?User
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->repository->find(Uuid::fromString($id));
    }

    /**
     * @return array<string, string>
     */
    private function serialize(User $user): array
    {
        return [
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'createdAt' => $user->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
