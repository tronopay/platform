<?php

namespace App\Exception;

/**
 * Raised when a task operation (cancel/accept/reject) cannot be applied because
 * the task is not in a state that allows the transition.
 */
final class InvalidTaskTransitionException extends \RuntimeException
{
}
