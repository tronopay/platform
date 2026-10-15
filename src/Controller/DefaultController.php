<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DefaultController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'APP_DONATE')]
        private string $donate = '',
    ) {}

    #[Route('/', name: 'app_index')]
    public function index(): Response
    {
        return $this->render('index.html.twig', ['donate' => $this->donate]);
    }
}
