<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'task_result_file')]
class TaskResultFile
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private ?Uuid $id = null,

        #[ORM\ManyToOne(targetEntity: TaskResult::class, cascade: ['persist'], inversedBy: 'files')]
        #[ORM\JoinColumn(name: 'result_id', nullable: false)]
        private ?TaskResult $result = null,

        #[ORM\Column(type: 'string', length: 255)]
        private ?string $originalName = null,

        #[ORM\Column(type: 'string', length: 255)]
        private ?string $virtualPath = null,

        #[ORM\Column(type: 'string', length: 255)]
        private ?string $mimeType = null,

        #[ORM\Column(type: 'integer')]
        private int $size = 0,

        #[ORM\Column(type: 'datetime_immutable')]
        private ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id ??= Uuid::v7();
        $this->createdAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getResult(): ?TaskResult
    {
        return $this->result;
    }

    public function setResult(?TaskResult $result): static
    {
        $this->result = $result;

        return $this;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): static
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getVirtualPath(): ?string
    {
        return $this->virtualPath;
    }

    public function setVirtualPath(string $virtualPath): static
    {
        $this->virtualPath = $virtualPath;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
