<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class PretPhysiqueController extends AbstractController
{
    #[Route('/pret/physique', name: 'app_pret_physique')]
    public function index(Connection $connection, SessionInterface $session): Response
    {
        $admin = $session->get('admin');
        // Requête SQL brute avec Doctrine DBAL
        $sql = "SELECT * FROM membre_bibliotheque";
        $membres = $connection->fetchAllAssociative($sql);

        return $this->render('pret_physique/index.html.twig', [
            'page_title' => 'Prêt physique',
            'membres' => $membres,
            'admin' => $admin,
        ]);
    }
    
    // POST du modal → redirection GET
    #[Route('/pret/selection-livre', name: 'pret_physique_selection_livre', methods: ['POST'])]
    public function selectionLivrePost(Request $request): Response
    {
        $membreId = $request->request->get('membre_id');
        $nombreLivres = (int) $request->request->get('nombre_livres');

        return $this->redirectToRoute('pret_physique_selection_livre_get', [
            'membre_id' => $membreId,
            'nombre_livres' => $nombreLivres
        ]);
    }

    // GET pour afficher la liste des livres
    #[Route('/pret/selection-livre/{membre_id}/{nombre_livres}', name: 'pret_physique_selection_livre_get', methods: ['GET'])]
    public function selectionLivreGet(Connection $connection, int $membre_id, int $nombre_livres): Response
    {
        $livres = $connection->fetchAllAssociative("SELECT * FROM livre WHERE nombre_exemplaire > 0");

        return $this->render('pret_physique/selection_livre.html.twig', [
            'membre_id' => $membre_id,
            'nombre_livres' => $nombre_livres,
            'livres' => $livres
        ]);
    }

    // Enregistrement du prêt
    #[Route('/pret/confirmer', name: 'pret_physique_confirmer', methods: ['POST'])]
    public function confirmerPret(Request $request, Connection $connection): Response
    {
        $membreId = $request->request->get('membre_id');
        $datePret = $request->request->get('date_pret');
        $dateRetour = $request->request->get('date_retour_effectif');
        $livresSelectionnes = $request->request->all('livres'); // tableau

        // Insert dans pret_physique
        $connection->insert('pret_physique', [
            'membre_bibliotheque_id' => $membreId,
            'date_pret' => $datePret,
            'date_retour_effectif' => $dateRetour,
            'nombre_livres' => count($livresSelectionnes)
        ]);

        $pretId = $connection->lastInsertId();

        // Insert dans livre_pret + mettre à jour le nombre_exemplaire
    foreach ($livresSelectionnes as $livreId) {
    // 1. Enregistrement du prêt du livre
    $connection->insert('livre_pret', [
        'pret_physique_id' => $pretId,
        'livre_id' => $livreId
    ]);

    // 2. Récupération du nombre_exemplaire actuel
    $exemplairesActuels = $connection->fetchOne(
        "SELECT nombre_exemplaire FROM livre WHERE id = ?",
        [$livreId]
    );

    // 3. Décrémentation du nombre_exemplaire
    $nouveauNombre = max(0, $exemplairesActuels - 1);

    // 4. Mise à jour de la table livre
    $connection->update('livre', [
        'nombre_exemplaire' => $nouveauNombre
    ], ['id' => $livreId]);

    // 5. Si plus d’exemplaire, rendre indisponible
    if ($nouveauNombre <= 0) {
        $connection->update('livre', [
            'disponibilite' => 0
        ], ['id' => $livreId]);
    }

        $this->addFlash('success', 'Prêt enregistré avec succès.');
        return $this->redirectToRoute('app_pret_physique');
    }

}
}
