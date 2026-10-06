<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\DBAL\Connection;

final class MembreBiblioController extends AbstractController
{
    #[Route('/membre', name: 'app_membre_biblio')]
    public function index(SessionInterface $session, Connection $connection): Response
    {
        $admin = $session->get('admin');
        $membre_bibliotheque = $connection->fetchAllAssociative('
            SELECT id, nom, prenom, type_personne, matricule, date_inscription, etat FROM membre_bibliotheque;');

        return $this->render('membre_biblio/index.html.twig', [
            'page_title' => 'Liste des membre bibliothèque',
            'membre_bibliotheques' => $membre_bibliotheque,
            'admin' => $admin,
        ]);
    }

    #[Route('/membre/{id}/desactiver', name: 'desactiver_membre', methods: ['POST'])]
    public function desactiverMembre($id, Connection $connection): Response
    {
        $connection->executeStatement("UPDATE membre_bibliotheque SET etat = 0 WHERE id = ?", [$id]);

        $this->addFlash('success', 'Le compte a été désactivé avec succès.');
        return $this->redirectToRoute('app_membre_biblio');
    }

    #[Route('/membre/{id}/reactiver', name: 'reactiver_membre', methods: ['POST'])]
    public function reactiverMembre(int $id, Connection $connection): Response
    {
        $connection->executeStatement("UPDATE membre_bibliotheque SET etat = 1 WHERE id = ?", [$id]);

        $this->addFlash('success', 'Le compte a été réactivé avec succès.');
        return $this->redirectToRoute('app_membre_biblio');
    }


    

}
