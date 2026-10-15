<?php

namespace App\Entity;

use App\Repository\PayoutRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One escrow money movement for a finished task: either the worker payout
 * (`accepted`) or the client refund (`rejected`).
 *
 * The row is created online by the preparer as a `prepared` intent, settled on
 * the machine holding the keys (no database there), and folded back online by
 * the report applier into `broadcast` / `confirmed` / `failed`. The machine
 * reports the txId, the actual fee, the block number and (on failure) why it
 * failed, so the online server knows the settled truth without ever holding a
 * key or building a payout transaction. `unique(task_id)` guarantees that a
 * task can never produce two payouts.
 */
#[ORM\Entity(repositoryClass: PayoutRepository::class)]
#[ORM\Table(name: 'payout')]
#[ORM\UniqueConstraint(name: 'uniq_payout_task', columns: ['task_id'])]
#[ORM\Index(name: 'idx_payout_batch_id', columns: ['batch_id'])]
#[ORM\Index(name: 'idx_payout_status', columns: ['status'])]
class Payout
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private ?Uuid $id = null,

        #[ORM\ManyToOne(targetEntity: Task::class)]
        #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private ?Task $task = null,

        #[ORM\Column(length: 16, enumType: PayoutDirection::class, nullable: true)]
        private ?PayoutDirection $direction = null,

        #[ORM\Column(length: 34, nullable: true)]
        private ?string $fromAddress = null,

        #[ORM\Column(length: 34, nullable: true)]
        private ?string $toAddress = null,

        #[ORM\Column(type: Types::INTEGER)]
        private int $amountSun = 0,

        /** The HD wallet index, so the offline machine can derive the signing key. */
        #[ORM\Column(type: Types::INTEGER)]
        private int $walletIndex = 0,

        #[ORM\Column(length: 16, enumType: PayoutStatus::class)]
        private PayoutStatus $status = PayoutStatus::Prepared,

        /** Why a failed payout cannot proceed; null while it has not failed. */
        #[ORM\Column(length: 16, enumType: PayoutFailureKind::class, nullable: true)]
        private ?PayoutFailureKind $failureKind = null,

        /** txID reported by the offline machine after it broadcast the transfer. */
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $txId = null,

        #[ORM\Column(length: 32, nullable: true)]
        private ?string $batchId = null,

        /** Actual network fee in sun reported by the offline machine. */
        #[ORM\Column(type: Types::INTEGER, nullable: true)]
        private ?int $feeSun = null,

        /** Block number reported by the offline machine for a confirmed transfer. */
        #[ORM\Column(type: Types::BIGINT, nullable: true)]
        private ?int $blockNumber = null,

        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $error = null,

        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $preparedAt = null,

        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $broadcastAt = null,

        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $confirmedAt = null,

        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $abandonedAt = null,

        #[ORM\Column]
        private ?\DateTimeImmutable $createdAt = null,

        #[ORM\Column]
        private ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->id ??= Uuid::v7();

        $now = new \DateTimeImmutable();
        $this->createdAt ??= $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getTask(): ?Task
    {
        return $this->task;
    }

    public function setTask(Task $task): static
    {
        $this->task = $task;

        return $this;
    }

    public function getDirection(): ?PayoutDirection
    {
        return $this->direction;
    }

    public function setDirection(PayoutDirection $direction): static
    {
        $this->direction = $direction;

        return $this;
    }

    public function getFromAddress(): ?string
    {
        return $this->fromAddress;
    }

    public function setFromAddress(string $fromAddress): static
    {
        $this->fromAddress = $fromAddress;

        return $this;
    }

    public function getToAddress(): ?string
    {
        return $this->toAddress;
    }

    public function setToAddress(string $toAddress): static
    {
        $this->toAddress = $toAddress;

        return $this;
    }

    public function getAmountSun(): int
    {
        return $this->amountSun;
    }

    public function setAmountSun(int $amountSun): static
    {
        $this->amountSun = $amountSun;

        return $this;
    }

    public function getWalletIndex(): int
    {
        return $this->walletIndex;
    }

    public function setWalletIndex(int $walletIndex): static
    {
        $this->walletIndex = $walletIndex;

        return $this;
    }

    public function getStatus(): PayoutStatus
    {
        return $this->status;
    }

    public function setStatus(PayoutStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getTxId(): ?string
    {
        return $this->txId;
    }

    public function setTxId(?string $txId): static
    {
        $this->txId = $txId;

        return $this;
    }

    public function getFailureKind(): ?PayoutFailureKind
    {
        return $this->failureKind;
    }

    public function setFailureKind(?PayoutFailureKind $failureKind): static
    {
        $this->failureKind = $failureKind;

        return $this;
    }

    public function getBatchId(): ?string
    {
        return $this->batchId;
    }

    public function setBatchId(?string $batchId): static
    {
        $this->batchId = $batchId;

        return $this;
    }

    public function getFeeSun(): ?int
    {
        return $this->feeSun;
    }

    public function setFeeSun(?int $feeSun): static
    {
        $this->feeSun = $feeSun;

        return $this;
    }

    public function getBlockNumber(): ?int
    {
        return $this->blockNumber;
    }

    public function setBlockNumber(?int $blockNumber): static
    {
        $this->blockNumber = $blockNumber;

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        $this->error = $error;

        return $this;
    }

    public function getPreparedAt(): ?\DateTimeImmutable
    {
        return $this->preparedAt;
    }

    public function setPreparedAt(?\DateTimeImmutable $preparedAt): static
    {
        $this->preparedAt = $preparedAt;

        return $this;
    }

    public function getBroadcastAt(): ?\DateTimeImmutable
    {
        return $this->broadcastAt;
    }

    public function setBroadcastAt(?\DateTimeImmutable $broadcastAt): static
    {
        $this->broadcastAt = $broadcastAt;

        return $this;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(?\DateTimeImmutable $confirmedAt): static
    {
        $this->confirmedAt = $confirmedAt;

        return $this;
    }

    public function getAbandonedAt(): ?\DateTimeImmutable
    {
        return $this->abandonedAt;
    }

    public function setAbandonedAt(?\DateTimeImmutable $abandonedAt): static
    {
        $this->abandonedAt = $abandonedAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(?\DateTimeImmutable $now = null): static
    {
        $this->updatedAt = $now ?? new \DateTimeImmutable();

        return $this;
    }

    /**
     * Whether a prepare run may (re)build this instruction right now.
     *
     * A settled payout never. A retryable failure may be rebuilt; a permanent
     * failure and a payout still waiting for the offline machine (`prepared`)
     * must be closed by an operator first so a fresh batch cannot duplicate an
     * in-flight transfer. `abandoned` is always rebuilt.
     */
    public function canBeRebuilt(\DateTimeImmutable $now): bool
    {
        if ($this->status->isSettled()) {
            return false;
        }

        return match ($this->status) {
            PayoutStatus::Failed => PayoutFailureKind::Permanent !== $this->failureKind,
            PayoutStatus::Abandoned => true,
            default => false,
        };
    }
}
