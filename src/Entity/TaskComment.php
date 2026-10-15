<?php

namespace App\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;

#[Entity]
class TaskComment
{
    public function __construct(
        #[Id, GeneratedValue, Column(type: "integer")]
        private int                 $id = 0,

        #[Column(name: "comment", type: "text")]
        private string              $comment = '',

        #[Column(name: "changes", type: "json")]
        private array               $changes = [],

        #[ManyToOne(targetEntity: Task::class, cascade: ['persist'], inversedBy: "comments")]
        private ?Task               $task = null,

        #[Column(type: "datetime_immutable", nullable: false)]
        private ?\DateTimeImmutable $createdAt = null,
    )
    {
        $now = new \DateTimeImmutable();
        if (null === $this->getCreatedAt()) {
            $this->setCreatedAt($now);
        }
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): TaskComment
    {
        $this->id = $id;
        return $this;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    public function setComment(string $comment): TaskComment
    {
        $this->comment = $comment;
        return $this;
    }

    public function getChanges(): array
    {
        return $this->changes;
    }

    public function setChanges(array $changes): TaskComment
    {
        $this->changes = $changes;
        return $this;
    }

    public function getTask(): ?Task
    {
        return $this->task;
    }

    public function setTask(?Task $task): TaskComment
    {
        $this->task = $task;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): TaskComment
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
