<?php

namespace App\Entity;

use App\Validator\ValidTronAddress;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Wallet
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue]
        #[ORM\Column(type: 'integer')]
        private int     $index = 0,

        #[ORM\Column(type: 'boolean', options: ['default' => false])]
        private bool    $change = false,

        #[ORM\Column(type: 'integer', options: ['default' => 0])]
        private int     $account = 0,

        #[ValidTronAddress]
        #[ORM\Column(type: 'string', length: 34, nullable: true)]
        private ?string $address = null,

        #[ORM\OneToOne(targetEntity: Task::class, inversedBy: "wallet")]
        private ?Task   $task = null,
    )
    {
    }

    public function getId(): int
    {
        return $this->getIndex();
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function setIndex(int $index): Wallet
    {
        $this->index = $index;

        return $this;
    }

    public function isChange(): bool
    {
        return $this->change;
    }

    public function setChange(bool $change): Wallet
    {
        $this->change = $change;

        return $this;
    }

    public function getAccount(): int
    {
        return $this->account;
    }

    public function setAccount(int $account): Wallet
    {
        $this->account = $account;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): Wallet
    {
        $this->address = $address;

        return $this;
    }

    public function getTask(): ?Task
    {
        return $this->task;
    }

    public function setTask(?Task $task): Wallet
    {
        $this->task = $task;

        return $this;
    }
}
