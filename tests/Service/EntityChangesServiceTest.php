<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EntityChangesService;
use App\Tests\Helper\FakeEntityChangesListener;
use PHPUnit\Framework\TestCase;

/**
 * Guards the audit-diff formatting against raw value objects (regression for
 * the 500 on POST /api/tasks: DateTimeImmutable is not a DateTime).
 */
final class EntityChangesServiceTest extends TestCase
{
    public function testFormatsDateTimeImmutableFields(): void
    {
        $listener = new FakeEntityChangesListener();
        $listener->setChanges([
            'createdAt' => [null, new \DateTimeImmutable('2026-10-11 14:30:00')],
        ]);

        $service = new EntityChangesService($listener);

        [$isAdd, $changes] = $service->getChanges(
            new class {
                public \DateTimeImmutable $createdAt;

                public function __construct()
                {
                    $this->createdAt = new \DateTimeImmutable('2026-10-11 14:30:00');
                }

                /** @return array<string, string> */
                public function diffFormat(): array
                {
                    return ['createdAt' => \App\Helper\DiffHelper::FORMAT_DATE_TIME_FULL];
                }
            },
        );

        self::assertFalse($isAdd);
        self::assertSame(
            '<del></del> -&gt; <ins>11.10.2026 14:30:00</ins>',
            $changes['createdAt'],
        );
    }
}
