<?php

namespace App\Dto;

use App\Entity\Task;
use App\Entity\TaskStatus;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

class CreateTaskResponse
{
    public function __construct(
        #[Groups([Task::GROUP_READ])]
        private ?Uuid       $id = null,

        #[Groups([Task::GROUP_READ])]
        private ?TaskStatus $taskStatus = null,

        #[Groups([Task::GROUP_READ])]
        private string      $price = '',

        #[Groups([Task::GROUP_READ])]
        private string      $paymentAddress = '',

        #[Groups([Task::GROUP_READ])]
        private string      $hint = '',

        private string      $paymentWindow = '1 hour',
    )
    {
        if ('' === $this->getHint()) {
            $hint = match ($this->getTaskStatus()) {
                TaskStatus::Created => sprintf(
                    'Please deposit %s TRX to the address "%s" within %s; otherwise, the task will be cancelled.',
                    $this->getPrice(),
                    $this->getPaymentAddress(),
                    $this->paymentWindow,
                ),
                TaskStatus::Canceled => 'This task was cancelled. Please create a new one.',
                TaskStatus::Prepaid => 'This task is waiting for a worker to carry it out. Please, wait.',
                TaskStatus::Assigned => 'This task was assigned to the worker who will carry it out. Please, wait.',
                TaskStatus::Processed => 'This task was processed by the worker. Please, verify it, then accept or reject the results.',
                TaskStatus::Accepted => 'The task result has been accepted. The money was released to the worker.',
                TaskStatus::Rejected => 'The task result has been rejected. The money was returned to the owner.',
                TaskStatus::Finished => 'The task has been finished. Please create a new one.',
                default => 'Unknown status error.',
            };
            $this->setHint($hint);
        }
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function setId(?Uuid $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getTaskStatus(): ?TaskStatus
    {
        return $this->taskStatus;
    }


    public function setTaskStatus(?TaskStatus $taskStatus): self
    {
        $this->taskStatus = $taskStatus;
        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPriceSun(string $price): self
    {
        $this->price = $price;
        return $this;
    }

    public function getPaymentAddress(): string
    {
        return $this->paymentAddress;
    }

    public function setPaymentAddress(string $paymentAddress): self
    {
        $this->paymentAddress = $paymentAddress;
        return $this;
    }

    public function getHint(): string
    {
        return $this->hint;
    }

    public function setHint(string $hint): self
    {
        $this->hint = $hint;
        return $this;
    }
}
