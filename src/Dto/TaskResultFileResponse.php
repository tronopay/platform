<?php

namespace App\Dto;

use App\Entity\Task;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * A file attached to a task result, as exposed to the task owner.
 */
final readonly class TaskResultFileResponse
{
    public function __construct(
        #[Groups([Task::GROUP_READ])]
        public ?Uuid $id = null,

        #[Groups([Task::GROUP_READ])]
        public string $name = '',

        #[Groups([Task::GROUP_READ])]
        public string $mimeType = '',

        #[Groups([Task::GROUP_READ])]
        public int $size = 0,

        /** URL the owner can download the file from (task password required). */
        #[Groups([Task::GROUP_READ])]
        public string $url = '',
    ) {
    }
}
