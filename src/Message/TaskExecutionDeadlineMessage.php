<?php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Dispatched with a 2-hour delay when a task is taken by an executor.
 * The handler only finalizes the task when it is still "assigned" and past
 * its deadline, so both this message and the lazy finalizer are safe to run.
 */
final class TaskExecutionDeadlineMessage
{
    public function __construct(public readonly Uuid $taskId)
    {
    }
}