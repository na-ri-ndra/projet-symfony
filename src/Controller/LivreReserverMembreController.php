<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LivreReserverMembreController extends AbstractController
{
    #[Route('/livre/reserver/membre', name: 'app_livre_reserver_membre')]
    public function livresReserves(SessionInterface $session, Connection $connection)
    {
        $membre = $session->get('membre'); // récupère le membre connecté

        if (!$membre) {
            return $this->redirectToRoute('app_login');
        }

        $idMembre = $membre['id'];

        $livresReserves = $connection->fetchAllAssociative(
            "SELECT l.titre, r.date_reservation, r.date_accueil, r.date_retour
             FROM reservation_livre r
             JOIN livre l ON l.id = r.livre_id
             WHERE r.membre_id = ?",
            [$idMembre]
        );

        return $this->render('livre_reserver_membre/index.html.twig', [
            'livres' => $livresReserves,
            'membre' => $membre,
        ]);
    }
}
