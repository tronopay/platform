<?php

namespace App\Dto;

use App\Entity\Task;
use App\Entity\TaskStatus;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

class GetTaskResultResponse
{
    #[Groups([Task::GROUP_READ])]
    public ?Uuid $id = null;

    #[Groups([Task::GROUP_READ])]
    public ?TaskStatus $taskStatus = null;

    #[Groups([Task::GROUP_READ])]
    public ?string $result = null;

    /** @var list<TaskResultFileResponse> Files uploaded by the worker. */
    #[Groups([Task::GROUP_READ])]
    public array $files = [];

    #[Groups([Task::GROUP_READ])]
    public string $hint = 'Check the results, then accept or reject them.';
}
