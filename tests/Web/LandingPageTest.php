<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the public landing page (GET /).
 *
 * Guards the landing-page fixes agreed in docs/proposals.md §2:
 * the correct create endpoint, the API docs link target, the worker entry
 * point, and the Latin brand spelling (regression: Cyrillic U+0420).
 */
final class LandingPageTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testLandingPageRendersWithCorrectLinks(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Tronopay');

        $html = (string) $client->getResponse()->getContent();

        // §2.5: the brand must use the Latin "Platform", not the Cyrillic homoglyph U+0420.
        self::assertStringNotContainsString('Рlatform', $html);
        self::assertStringContainsString('Tronopay Human‑in‑the‑Loop Task Platform', $html);

        // §2.2: the real create endpoint is POST /api/tasks, not /api/tasks/create.
        self::assertStringContainsString('POST /api/tasks</code>', $html);
        self::assertStringNotContainsString('tasks/create', $html);

        // §2.3: "API docs" points at the docs UI, not the JSON-LD entrypoint.
        self::assertStringContainsString('href="/api/docs', $html);

        // §2.4: the worker side is reachable from the landing page.
        self::assertStringContainsString('href="/cabinet"', $html);

        // §2.6: the "coming soon" leftover is gone.
        self::assertStringNotContainsString('coming soon', $html);
    }

    public function testDonateSectionTracksConfiguredWallet(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        // The donate block renders only when the environment defines a wallet;
        // when it does, the wallet address must be shown.
        $donate = (string) getenv('APP_DONATE');
        $html = (string) $client->getResponse()->getContent();

        if ('' !== $donate) {
            self::assertStringContainsString('>DONATE<', $html);
            self::assertStringContainsString($donate, $html);
        } else {
            self::assertStringNotContainsString('>DONATE<', $html);
        }
    }
}
