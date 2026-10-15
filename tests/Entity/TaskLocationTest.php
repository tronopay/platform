<?php

namespace App\Tests\Entity;

use App\Entity\Task;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class TaskLocationTest extends TestCase
{
    public function testConvertsShorthandToGeoJson(): void
    {
        $task = new Task();
        $task->setLocation('55.7558, 37.6173');

        self::assertSame('{"type":"Point","coordinates":[37.6173,55.7558]}', $task->getLocation());
    }

    public function testConstructorNormalizesLocation(): void
    {
        // The serializer passes promoted constructor arguments directly (no setter call).
        $task = new Task(location: '12.34567, 23.45678');

        self::assertSame('{"type":"Point","coordinates":[23.45678,12.34567]}', $task->getLocation());
    }

    public function testKeepsGeoJsonUnchanged(): void
    {
        $geoJson = '{"type":"Point","coordinates":[37.6173,55.7558]}';

        $task = new Task();
        $task->setLocation($geoJson);

        self::assertSame($geoJson, $task->getLocation());
    }

    public function testNullStaysNull(): void
    {
        $task = new Task();
        $task->setLocation(null);

        self::assertNull($task->getLocation());
    }

    public function testKeepsUnparseableValueForValidation(): void
    {
        $task = new Task();
        $task->setLocation('garbage');

        self::assertSame('garbage', $task->getLocation());
    }

    public function testEmptyLocationFailsValidation(): void
    {
        $task = $this->validTask();
        $task->setLocation('');

        $violations = $this->validator()->validate($task, null, ['Default', Task::GROUP_CREATE]);

        self::assertCount(1, $violations);
        self::assertSame('location', $violations->get(0)->getPropertyPath());
    }

    public function testMissingLocationPassesValidation(): void
    {
        self::assertCount(0, $this->validator()->validate($this->validTask(), null, ['Default', Task::GROUP_CREATE]));
    }

    public function testInvalidCoordinatesFailValidation(): void
    {
        $task = $this->validTask();
        $task->setLocation('not-a-location');

        $violations = $this->validator()->validate($task, null, ['Default', Task::GROUP_CREATE]);

        self::assertCount(1, $violations);
        self::assertSame('location', $violations->get(0)->getPropertyPath());
    }

    public function testValidCoordinatesPassValidation(): void
    {
        $task = $this->validTask();
        $task->setLocation('55.7558, 37.6173');

        self::assertCount(0, $this->validator()->validate($task, null, ['Default', Task::GROUP_CREATE]));
    }

    private function validTask(): Task
    {
        $task = new Task();
        $task->setStatement('move the box');

        return $task;
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
