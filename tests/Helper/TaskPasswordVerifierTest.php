<?php

namespace App\Tests\Helper;

use App\Entity\Task;
use App\Helper\TaskPasswordVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TaskPasswordVerifierTest extends TestCase
{
    public function testAcceptsValidHeaderPassword(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects($this->once())
            ->method('isPasswordValid')
            ->with($this->isInstanceOf(Task::class), 'secret')
            ->willReturn(true);

        $request = Request::create('/api/tasks/x', server: ['HTTP_X_TASK_PASSWORD' => 'secret']);

        (new TaskPasswordVerifier($hasher))->verify(new Task(), $request);
    }

    public function testRejectsInvalidPassword(): void
    {
        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturn(false);

        $request = Request::create('/api/tasks/x', server: ['HTTP_X_TASK_PASSWORD' => 'wrong']);

        $this->expectException(AccessDeniedHttpException::class);
        (new TaskPasswordVerifier($hasher))->verify(new Task(), $request);
    }

    public function testRejectsMissingHeader(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects($this->never())->method('isPasswordValid');

        $this->expectException(AccessDeniedHttpException::class);
        (new TaskPasswordVerifier($hasher))->verify(new Task(), Request::create('/api/tasks/x'));
    }

    public function testRejectsEmptyHeader(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects($this->never())->method('isPasswordValid');

        $request = Request::create('/api/tasks/x', server: ['HTTP_X_TASK_PASSWORD' => '']);

        $this->expectException(AccessDeniedHttpException::class);
        (new TaskPasswordVerifier($hasher))->verify(new Task(), $request);
    }

    public function testRejectsNullRequest(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects($this->never())->method('isPasswordValid');

        $this->expectException(AccessDeniedHttpException::class);
        (new TaskPasswordVerifier($hasher))->verify(new Task(), null);
    }
}
