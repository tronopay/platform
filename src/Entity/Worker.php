<?php

namespace App\Entity;

use App\Repository\WorkerRepository;
use App\Validator\ValidCoordinates;
use App\Validator\ValidTronAddress;
use App\ValueObject\Coordinates;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WorkerRepository::class)]
#[ORM\Table(name: 'worker')]
class Worker implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private ?Uuid $id = null,

        #[ValidTronAddress]
        #[ORM\Column(type: 'string', length: 34, unique: true)]
        private ?string $tronAddress = null,

        #[ORM\Column(type: 'string', length: 255)]
        private ?string $password = null,

        #[ORM\Column(type: 'datetime_immutable')]
        private ?\DateTimeImmutable $createdAt = null,

        #[ValidCoordinates]
        #[ORM\Column(type: 'geography', nullable: true, options: ['geometry_type' => 'POINT', 'srid' => 4326])]
        private ?string $location = null,

        #[ORM\Column(name: 'location_updated_at', type: 'datetime_immutable', nullable: true)]
        private ?\DateTimeImmutable $locationUpdatedAt = null,
    ) {
        $this->id ??= Uuid::v7();
        $this->createdAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getTronAddress(): ?string
    {
        return $this->tronAddress;
    }

    public function setTronAddress(string $tronAddress): static
    {
        $this->tronAddress = $tronAddress;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The worker's geo-location as a GeoJSON Point (like Task::$location),
     * or null when the worker has not shared a location yet.
     */
    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = null === $location ? null : Coordinates::normalize($location);

        return $this;
    }

    public function getLocationUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->locationUpdatedAt;
    }

    public function setLocationUpdatedAt(?\DateTimeImmutable $locationUpdatedAt): static
    {
        $this->locationUpdatedAt = $locationUpdatedAt;

        return $this;
    }

    public function getRoles(): array
    {
        return ['ROLE_WORKER'];
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->tronAddress;
    }

    public function eraseCredentials(): void
    {
    }
}