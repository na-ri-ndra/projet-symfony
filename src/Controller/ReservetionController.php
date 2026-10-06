<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\DBAL\Connection;

class ReservetionController extends AbstractController
{
    #[Route('/membre/reserver/{livre_id}', name: 'app_reservation')]
public function reserver(
    int $livre_id, 
    Request $request, 
    SessionInterface $session, 
    Connection $connection
): Response {
    $membre = $session->get('membre');
    if (!$membre) {
        return $this->redirectToRoute('app_login_membre');
    }

    // Récupérer les infos du livre
    $livre = $connection->fetchAssociative("SELECT * FROM livre WHERE id = ?", [$livre_id]);

    if (!$livre) {
        throw $this->createNotFoundException('Livre introuvable.');
    }

    if ($livre['nombre_exemplaire'] <= 0) {
        $this->addFlash('error', 'Ce livre est actuellement indisponible.');
        return $this->redirectToRoute('app_membre_livre');
    }

    // Si formulaire soumis
    if ($request->isMethod('POST')) {
        $dateAccueil = $request->request->get('date_accueil');
        $dateRetour = $request->request->get('date_retour');

        // Enregistrer le message de réservation
        $contenu = "Demande de réservation\n\n"
            . "Nom du membre : " . $membre['nom'] . " " . $membre['prenom'] . "\n"
            . "Titre du livre : " . $livre['titre'] . "\n"
            . "Date d’accueil souhaitée : " . $dateAccueil . "\n"
            . "Date de retour prévue : " . $dateRetour;

        $connection->insert('message_membre_vers_admin', [
            'membre_id' => $membre['id'],
            'contenu' => $contenu,
            'date_envoi' => date('Y-m-d H:i:s'),
            'livre_id' => $livre_id,
            'date_accueil' => $dateAccueil,
            'date_retour' => $dateRetour,
        ]);

        // 🔻 Décrémenter le stock du livre
        $nouveauStock = $livre['nombre_exemplaire'] - 1;
        $dispo = $nouveauStock > 0 ? 'oui' : 'non';

        $connection->executeStatement(
            "UPDATE livre SET nombre_exemplaire = ?, disponibilite = ? WHERE id = ?",
            [$nouveauStock, $dispo, $livre_id]
        );

        $this->addFlash('success', 'Votre réservation a été envoyée et le livre vous sera bientôt attribué.');
        return $this->redirectToRoute('app_membre_livre');
    }

    return $this->render('reservetion/index.html.twig', [
        'livre_id' => $livre_id,
        'membre' => $membre,
        'livre' => $livre,
    ]);
}

}
