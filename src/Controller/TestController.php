<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TestController extends AbstractController
{
    #[Route('/boky', name: 'app_tesita')]
    public function index(): Response
    {
        return $this->render('test/index.html.twig', [
            'controller_name' => 'TestController',
        ]);
    }

    #[Route('/boky/preter', name: 'app_preter')]
    public function preter(): Response
    {
        return $this->render('test/livre_preter.html.twig', [
            'controller_name' => 'TestController',
        ]);
    }

    #[Route('/boky/reserver', name: 'app_reserver')]
    public function reserver(): Response
    {
        return $this->render('test/livre_reserver.html.twig', [
            'controller_name' => 'TestController',
        ]);
    }
}
