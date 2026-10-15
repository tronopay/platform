<?php

namespace App\Entity;

use App\Repository\TaskResultRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TaskResultRepository::class)]
#[ORM\Table(name: 'task_result')]
class TaskResult
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private ?Uuid $id = null,

        #[ORM\OneToOne(targetEntity: Task::class, mappedBy: 'result')]
        private ?Task $task = null,

        #[ORM\Column(type: 'text')]
        private ?string $message = null,

        #[ORM\Column(type: 'datetime_immutable')]
        private ?\DateTimeImmutable $submittedAt = null,

        #[ORM\OneToMany(targetEntity: TaskResultFile::class, mappedBy: 'result', cascade: ['persist', 'remove'], orphanRemoval: true)]
        private Collection $files = new ArrayCollection(),
    ) {
        $this->id ??= Uuid::v7();
        $this->submittedAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getTask(): ?Task
    {
        return $this->task;
    }

    public function setTask(?Task $task): static
    {
        $this->task = $task;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(\DateTimeImmutable $submittedAt): static
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }

    /**
     * @return Collection<int, TaskResultFile>
     */
    public function getFiles(): Collection
    {
        return $this->files;
    }
}