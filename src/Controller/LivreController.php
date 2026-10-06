<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;



final class LivreController extends AbstractController
{
    #[Route('/livre', name: 'app_livre')]
    public function index(Connection $connection, SessionInterface $session): Response
    {
        $admin = $session->get('admin');
        $livre = $connection->fetchAllAssociative('
        SELECT l.*, e.code_etagere 
        FROM livre l
        LEFT JOIN etagere e ON l.etagere_id = e.id
    ');

    $etageres = $connection->fetchAllAssociative('SELECT * FROM etagere');
    $genres = $connection->fetchAllAssociative('SELECT * FROM genre_livre');

    return $this->render('livre/index.html.twig', [
        'page_title' => 'Liste des livre',
        'livres' => $livre,
        'etageres' => $etageres,
        'genres' => $genres,
        'admin' => $admin,
    ]);  
    }
    
    #[Route('/livre/modifier/{id}', name: 'livre_modifier', methods: ['POST'])]
public function modifierLivre(int $id, Request $request, Connection $connection): Response
{
    // Récupérer les données du formulaire
    $titre = $request->request->get('titre');
    $auteur = $request->request->get('auteur');
    $isbn = $request->request->get('isbn');
    $etat = $request->request->get('etat');
    $disponibilite = $request->request->get('disponibilite');
    $nombreExemplaire = (int) $request->request->get('nombre_exemplaire');
    $etagereId = (int) $request->request->get('etagere_id');
    $editeur = $request->request->get('editeur');
    $resumer = $request->request->get('resumer');
    $genreId = (int) $request->request->get('genre_id');

    // Récupérer les infos du livre actuel
    $livre = $connection->fetchAssociative('SELECT * FROM livre WHERE id = ?', [$id]);
    if (!$livre) {
        throw new NotFoundHttpException('Livre non trouvé');
    }

    $ancienEtagereId = (int) $livre['etagere_id'];
    $ancienNombreExemplaire = (int) $livre['nombre_exemplaire'];

    // Récupérer la capacité max et le nombre actuel de la nouvelle étagère
    $etagere = $connection->fetchAssociative('SELECT * FROM etagere WHERE id = ?', [$etagereId]);
    if (!$etagere) {
        throw new NotFoundHttpException("Étagère non trouvée");
    }

    $capaciteMax = (int) $etagere['capacite_max'];
    $nombreActuel = (int) $etagere['nombre_livres_actuel'];

    if ($etagereId === $ancienEtagereId) {
        $diff = $nombreExemplaire - $ancienNombreExemplaire;
        $nouveauTotal = $nombreActuel + $diff;
    } else {
        // Recalculer nombre actuel pour les deux étagères
        // 1. retirer les anciens exemplaires de l’ancienne étagère
        $connection->executeStatement(
            'UPDATE etagere SET nombre_livres_actuel = nombre_livres_actuel - ? WHERE id = ?',
            [$ancienNombreExemplaire, $ancienEtagereId]
        );
        // 2. ajouter les nouveaux exemplaires à la nouvelle étagère
        $nouveauTotal = $nombreActuel + $nombreExemplaire;
    }

    // Vérification de dépassement de capacité
    if ($nouveauTotal > $capaciteMax) {
        $this->addFlash('error', 'Erreur : la capacité maximale de l’étagère est dépassée.');
        return $this->redirectToRoute('app_livre');
    }

    // Mise à jour du livre
    $connection->executeStatement(
        'UPDATE livre SET titre = ?, auteur = ?, isbn = ?, etat = ?, disponibilite = ?, 
         nombre_exemplaire = ?, etagere_id = ?, editeur = ?, resumer = ?, genre_id = ? WHERE id = ?',
        [$titre, $auteur, $isbn, $etat, $disponibilite, $nombreExemplaire, $etagereId, $editeur, $resumer, $genreId, $id]
    );

    // Mise à jour du nombre de livres actuel dans la nouvelle étagère (si même étagère)
    if ($etagereId === $ancienEtagereId) {
        $connection->executeStatement(
            'UPDATE etagere SET nombre_livres_actuel = ? WHERE id = ?',
            [$nouveauTotal, $etagereId]
        );
    }

    $this->addFlash('success', 'Livre modifié avec succès');
    return $this->redirectToRoute('app_livre');
}
    
    #[Route('/livre/supprimer/{id}', name: 'livre_supprimer')]
public function supprimerLivre(int $id, Connection $connection): Response
{
    // 1. Récupération des infos du livre
    $livre = $connection->fetchAssociative('SELECT nombre_exemplaire, etagere_id FROM livre WHERE id = ?', [$id]);

    if (!$livre) {
        $this->addFlash('error', 'Livre introuvable.');
        return $this->redirectToRoute('app_livre');
    }

    $nombreExemplaire = (int) $livre['nombre_exemplaire'];
    $etagereId = (int) $livre['etagere_id'];

    // 2. Récupérer le nombre actuel de livres dans l’étagère
    $etagere = $connection->fetchAssociative('SELECT nombre_livres_actuel FROM etagere WHERE id = ?', [$etagereId]);

    if ($etagere) {
        $nombreActuel = (int) $etagere['nombre_livres_actuel'];
        $nouveauNombre = max(0, $nombreActuel - $nombreExemplaire); // Jamais négatif

        // 3. Mise à jour du nombre de livres actuels
        $connection->executeStatement(
            'UPDATE etagere SET nombre_livres_actuel = ? WHERE id = ?',
            [$nouveauNombre, $etagereId]
        );
    }

    // 4. Suppression du livre
    $connection->executeStatement('DELETE FROM livre WHERE id = ?', [$id]);

    $this->addFlash('success', 'Livre supprimé avec succès.');
    return $this->redirectToRoute('app_livre');
} 

    #[Route('/livre/ajouter', name: 'livre_ajouter', methods: ['POST'])]
    public function ajouter(Request $request, Connection $connection): Response
    {
        // Récupération des données du formulaire
        $titre = $request->request->get('titre');
        $auteur = $request->request->get('auteur');
        $editeur = $request->request->get('editeur');
        $isbn = $request->request->get('isbn');
        $genreId = $request->request->get('genre_id');
        $nouveauGenre = $request->request->get('nouveau_genre');
        $nbExemplaire = $request->request->get('nb_exemplaire');
        $etagereId = $request->request->get('etagere_id');
        $etat = $request->request->get('etat');
        $disponibilite = $request->request->get('disponibilite');
        $resume = $request->request->get('resume');

        // Si "autre" est sélectionné, insérer le nouveau genre et récupérer son ID
        if ($genreId === 'autre' && !empty($nouveauGenre)) {
            $connection->insert('genre_livre', [
                'genre' => $nouveauGenre
            ]);

            // Récupérer l'ID du nouveau genre
            $genreId = $connection->lastInsertId();
        }

        // Insertion du livre
        $connection->insert('livre', [
            'titre' => $titre,
            'auteur' => $auteur,
            'editeur' => $editeur,
            'isbn' => $isbn,
            'etat' => $etat,
            'disponibilite' => $disponibilite,
            'nombre_exemplaire' => $nbExemplaire,
            'etagere_id' => $etagereId,
            'resumer' => $resume,
            'genre_id' => $genreId
        ]);

        // Mise à jour du nombre de livres dans l'étagère
        $connection->executeStatement("UPDATE etagere SET nombre_livres_actuel = nombre_livres_actuel + 1 WHERE id = ?",
    [$etagereId], [
            'nb' => $nbExemplaire,
            'etagereId' => $etagereId
        ]);

        // Message flash + redirection
        $this->addFlash('success', 'Livre ajouté avec succès !');
        return $this->redirectToRoute('app_livre'); // page de liste des livres
    }

}
