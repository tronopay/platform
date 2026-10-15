<?php

namespace App\Dto;

use App\Entity\Task;
use App\Entity\TaskType;
use App\Validator\ValidCoordinates;
use App\Validator\ValidTronAddress;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class CreateTaskRequest
{
    #[Groups([Task::GROUP_CREATE])]
    public TaskType $type = TaskType::Other;

    #[Assert\NotBlank]
    #[Groups([Task::GROUP_CREATE])]
    public string $statement = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 8, max: 64)]
    #[Groups([Task::GROUP_CREATE])]
    public string $password = '';

    #[Assert\NotBlank(allowNull: true)]
    #[ValidCoordinates]
    #[Groups([Task::GROUP_CREATE])]
    private ?string $location = null;

    #[Assert\Positive]
    #[Assert\GreaterThanOrEqual(value: Task::TRX_PRICE_MIN)]
    #[Groups([Task::GROUP_CREATE])]
    public string $price = (string) Task::TRX_PRICE_MIN;

    #[ValidTronAddress]
    #[Groups([Task::GROUP_CREATE])]
    public ?string $refundAddress = null;

    #[Assert\Url]
    #[Groups([Task::GROUP_CREATE])]
    public ?string $webhook = null;

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): self
    {
        $this->location = $location;

        return $this;
    }
}
