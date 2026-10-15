<?php

namespace App\Helper;

use App\Entity\Task;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Verifies the task password sent in the X-Task-Password HTTP header.
 */
final readonly class TaskPasswordVerifier
{
    public const HEADER = 'X-Task-Password';

    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @throws AccessDeniedHttpException when the header is missing or the password is invalid
     */
    public function verify(Task $task, ?Request $request): void
    {
        $password = (string) ($request?->headers->get(self::HEADER) ?? '');
        $this->verifyPlaintext($task, $password);
    }

    /**
     * Verifies a plaintext password (as passed to a tool call or resource read)
     * against the task's hashed password.
     *
     * @throws AccessDeniedHttpException when the password is empty or invalid
     */
    public function verifyPlaintext(Task $task, string $password): void
    {
        if ('' === $password || !$this->passwordHasher->isPasswordValid($task, $password)) {
            throw new AccessDeniedHttpException('Invalid task password.');
        }
    }
}
