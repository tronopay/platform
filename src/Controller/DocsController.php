<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the static, developer/agent-facing documentation pages.
 */
final class DocsController extends AbstractController
{
    #[Route('/mcp/docs', name: 'app_docs_mcp')]
    public function mcp(): Response
    {
        return $this->render('docs/mcp.html.twig');
    }
}
