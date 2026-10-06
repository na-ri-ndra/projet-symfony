<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class NotificationMembreController extends AbstractController
{
    #[Route('/notification/membre', name: 'app_notification_membre')]
    public function index(SessionInterface $session): Response
    {
        $membre = $session->get('membre');

        // Tu peux ajouter un test si nécessaire
        if (!$membre) {
            return $this->redirectToRoute('app_accueil');
        }

        return $this->render('notification_membre/index.html.twig', [
            'controller_name' => 'NotificationMembreController',
            'membre' => $membre,
            'nonLus' => $nonLus,
        ]);
    }
}
