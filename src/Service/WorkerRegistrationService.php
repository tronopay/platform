<?php

namespace App\Service;

use App\Entity\Worker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class WorkerRegistrationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function register(Worker $worker, string $plainPassword): Worker
    {
        if (null !== $worker->getPassword()) {
            throw new \LogicException('Password is already set.');
        }

        $worker->setPassword($this->passwordHasher->hashPassword($worker, $plainPassword));

        $this->entityManager->persist($worker);
        $this->entityManager->flush();

        return $worker;
    }
}