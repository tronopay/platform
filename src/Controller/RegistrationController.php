<?php

namespace App\Controller;

use App\Entity\Worker;
use App\Form\WorkerRegistrationFormType;
use App\Service\WorkerRegistrationService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request, WorkerRegistrationService $registration): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_cabinet');
        }

        $worker = new Worker();
        $form = $this->createForm(WorkerRegistrationFormType::class, $worker);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $registration->register($worker, (string) $form->get('plainPassword')->getData());

                $this->addFlash('success', 'Account created. Please log in.');

                return $this->redirectToRoute('app_worker_login');
            } catch (UniqueConstraintViolationException) {
                $form->get('tronAddress')->addError(new FormError('A worker with this wallet address is already registered.'));
            }
        }

        return $this->render('register.html.twig', [
            'form' => $form,
        ]);
    }
}