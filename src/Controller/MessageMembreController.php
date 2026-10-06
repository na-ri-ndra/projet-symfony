<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class MessageMembreController extends AbstractController
{
    #[Route('membres/message', name: 'app_message_membre')]
    public function index(SessionInterface $session, Connection $connection, Request $request): Response
    {
        // Juste après avoir récupéré $membre :
        $membre = $session->get('membre');
        if (!$membre) {
            return $this->redirectToRoute('app_accueil'); // Redirige si pas connecté
        }
        $membreId = $membre['id'];
        $connection->update(
            'message_admin_vers_membre',
            ['est_lu' => 1],
            ['membre_id' => $membreId, 'est_lu' => 0]
        );
        
        $success = null;
        $error = null;


        // Récupérer les admins (si on veut permettre de choisir un admin)
        $admins = $connection->fetchAllAssociative("SELECT * FROM administrateur");

        if ($request->isMethod('POST')) {
            $admin_id = $request->request->get('admin_id');
            $contenu = $request->request->get('contenu');
            $date_envoi = date('Y-m-d H:i:s');

            try {
                $connection->insert('message_membre_vers_admin', [
                    'membre_id' => $membre['id'],
                    'admin_id' => $admin_id,
                    'contenu' => $contenu,
                    'date_envoi' => $date_envoi,
                    'lu' => 0
                ]);
                $success = "Message envoyé à l’administrateur.";
            } catch (\Exception $e) {
                $error = "Erreur lors de l’envoi.";
            }
        }

        // Affiche les messages précédents envoyés
        $messages = $connection->fetchAllAssociative(
            "SELECT m.*, a.nom_utilisateur AS nom_admin
             FROM message_membre_vers_admin m
             JOIN administrateur a ON m.admin_id = a.id
             WHERE m.membre_id = ?
             ORDER BY m.date_envoi DESC",
            [$membre['id']]
        );

        $sql = "
                SELECT m.id, m.admin_id, m.membre_id, m.contenu, m.date_envoi, m.est_lu,
                    a.nom_utilisateur AS nom_expediteur, 'admin' AS expediteur
                FROM message_admin_vers_membre m
                JOIN administrateur a ON m.admin_id = a.id
                WHERE m.membre_id = :id

                UNION ALL

                SELECT m.id, m.admin_id, m.membre_id, m.contenu, m.date_envoi, m.lu AS est_lu,
                    CONCAT(mb.nom, ' ', mb.prenom) AS nom_expediteur, 'membre' AS expediteur
                FROM message_membre_vers_admin m
                JOIN membre_bibliotheque mb ON m.membre_id = mb.id
                WHERE m.membre_id = :id

                ORDER BY date_envoi ASC";


            $statement = $connection->prepare($sql);
            $statement->bindValue(':id', $membreId);
            $result = $statement->executeQuery();
            $messages = $result->fetchAllAssociative();


        $connection->executeQuery(
            "UPDATE message_admin_vers_membre SET est_lu = TRUE WHERE membre_id = ? AND lu = FALSE",
            [$membreId]
        );

            return $this->render('message_membre/index.html.twig', [
                'membre' => $membre,
                'admins' => $admins,
                'messages' => $messages,
                'success' => $success,
                'error' => $error,
            ]);
    }

    #[Route('/membre/messages/ajax', name: 'app_membre_messages_ajax')]
    public function fetchMessages(Connection $connection, SessionInterface $session): JsonResponse
    {
        $membre = $session->get('membre');

        $sql = "
            SELECT m.id, m.admin_id, m.membre_id, m.contenu, m.date_envoi, m.est_lu,
                a.nom_utilisateur AS nom_expediteur, 'admin' AS expediteur
            FROM message_admin_vers_membre m
            JOIN administrateur a ON m.admin_id = a.id
            WHERE m.membre_id = :id

            UNION ALL

            SELECT m.id, m.admin_id, m.membre_id, m.contenu, m.date_envoi, m.lu AS est_lu,
                CONCAT(mb.nom, ' ', mb.prenom) AS nom_expediteur, 'membre' AS expediteur
            FROM message_membre_vers_admin m
            JOIN membre_bibliotheque mb ON m.membre_id = mb.id
            WHERE m.membre_id = :id

            ORDER BY date_envoi ASC";

        $statement = $connection->prepare($sql);
        $statement->bindValue(':id', $membre['id']);
        $result = $statement->executeQuery();
        $messages = $result->fetchAllAssociative();

        return new JsonResponse($messages);
    }
    #[Route('/membre/messages-non-lus', name: 'app_membre_messages_non_lus')]
    public function messagesNonLus(SessionInterface $session, Connection $connection): JsonResponse
    {
        $membre = $session->get('membre');
        if (!$membre) {
            return new JsonResponse(['nonLus' => 0]);
        }

        $count = $connection->fetchOne(
            "SELECT COUNT(*) FROM message_admin_vers_membre WHERE membre_id = ? AND est_lu = 0",
            [$membre['id']]
        );

        return new JsonResponse(['nonLus' => (int)$count]); // Retourne un entier
    }
    #[Route('/membre/message/reservation/{id}', name: 'app_message_reservation_livre')]
    public function reserverLivre(
        int $id,
        Connection $connection,
        SessionInterface $session,
        Request $request
    ): Response {
        
        $membre = $session->get('membre');
        if (!$membre) {
            return $this->redirectToRoute('app_accueil_membre');
        }

        $livre = $connection->fetchAssociative("SELECT * FROM livre WHERE id = ?", [$id]);
        if (!$livre) {
            throw $this->createNotFoundException('Livre non trouvé');
        }

        $admins = $connection->fetchAllAssociative("SELECT * FROM administrateur");
        $success = null;
        $error = null;

        if ($request->isMethod('POST')) {
            $admin_id = $request->request->get('admin_id');
            $date_accueil = $request->request->get('date_accueil');
            $date_retour = $request->request->get('date_retour');

            $contenu = "Demande de réservation de livre\n";
            $contenu .= "Membre : {$membre['nom']} {$membre['prenom']} (Matricule : {$membre['matricule']})\n";
            $contenu .= "Livre : {$livre['titre']}\n";
            $contenu .= "Date d'accueil : $date_accueil\n";
            $contenu .= "Date de retour : $date_retour";

            try {
                // 1. Vérifie s’il reste des exemplaires
                if ($livre['nombre_exemplaire'] <= 0) {
                    $error = "Aucun exemplaire disponible pour ce livre.";
                } else {
                    // 2. Insertion du message de réservation
                    $connection->insert('message_membre_vers_admin', [
                        'membre_id' => $membre['id'],
                        'admin_id' => $admin_id,
                        'contenu' => $contenu,
                        'date_envoi' => date('Y-m-d H:i:s'),
                        'lu' => 0,
                    ]);

                    // 3. Mise à jour du nombre d’exemplaire
                    $connection->executeStatement(
                        "UPDATE livre SET nombre_exemplaire = nombre_exemplaire - 1 WHERE id = ?",
                        [$livre['id']]
                    );

                    $success = "Demande de réservation envoyée à l’administrateur.";
                }
            } catch (\Exception $e) {
                $error = "Erreur lors de l’envoi de la réservation.";
            }
        }
        return $this->render('message_membre/reservation.html.twig', [
            'livre' => $livre,
            'admins' => $admins,
            'membre' => $membre,
            'success' => $success,
            'error' => $error,
        ]);
    }
    #[Route('/membre/reservation/envoyer/{reservationId}', name: 'membre_reservation_envoyer', methods: ['POST'])]
public function envoyerReservation(
    int $reservationId,
    Request $request,
    Connection $connection,
    SessionInterface $session
): Response {
    $membre = $session->get('membre');
    if (!$membre) {
        return $this->redirectToRoute('app_accueil_membre');
    }

    // Récupérer les livres sélectionnés
    $livreIds = $request->request->all('livres'); // tableau d’IDs
    $dateRetrait = $request->request->get('dateRetrait');
    $dateRetour = $request->request->get('dateRetour');

    // Récupération des infos livres
    $livres = $connection->fetchAllAssociative(
        "SELECT * FROM livre WHERE id IN (?)",
        [$livreIds],
        [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
    );

    // Générer le message
    $contenu = "Nouvelle demande de réservation de livres\n";
    $contenu .= "Membre : {$membre['nom']} {$membre['prenom']} (Matricule : {$membre['matricule']})\n";
    $contenu .= "Livres sélectionnés :\n";
    foreach ($livres as $livre) {
        $contenu .= "- {$livre['titre']} ({$livre['auteur']})\n";
    }
    $contenu .= "Date de retrait : $dateRetrait\n";
    $contenu .= "Date de retour : $dateRetour";

    // Choisir un admin (ici, admin ID fixe ou le premier dispo)
    $admin = $connection->fetchAssociative("SELECT * FROM administrateur LIMIT 1");

    // Envoi du message
    $connection->insert('message_membre_vers_admin', [
        'membre_id' => $membre['id'],
        'admin_id' => $admin['id'],
        'contenu' => $contenu,
        'date_envoi' => date('Y-m-d H:i:s'),
        'lu' => 0
    ]);

    // ➕ Enregistrer dans `reserver_livre`
    foreach ($livreIds as $livreId) {
        $connection->insert('reserver_livre', [
            'reservation_id' => $reservationId,
            'livre_id' => $livreId,
            'date_retrait' => $dateRetrait,
            'date_retour' => $dateRetour,
        ]);

        // Décrémenter le stock
        $connection->executeStatement(
            "UPDATE livre SET nombre_exemplaire = nombre_exemplaire - 1 WHERE id = ?",
            [$livreId]
        );
    }

    return $this->redirectToRoute('app_message_membre'); // ou une autre route de confirmation
}

#[Route('/membre/messages-ajax', name: 'app_membre_messages_ajax')]
    public function messagesAjax(SessionInterface $session, Connection $connection): JsonResponse
    {
        $membre = $session->get('membre');
        if (!$membre) {
            return new JsonResponse([]);
        }

        $membreId = $membre['id'];

        // Optionnel: Marquer comme lu ICI aussi si cette route est appelée pour l'affichage principal
        // $connection->update(
        //     'message_admin_vers_membre',
        //     ['est_lu' => 1],
        //     ['membre_id' => $membreId, 'est_lu' => 0]
        // );

        $messages = $connection->fetchAllAssociative("
            SELECT 'membre' AS expediteur, m.contenu, m.date_envoi, mb.nom AS nom_expediteur
            FROM message_membre_vers_admin m
            JOIN membre_bibliotheque mb ON m.membre_id = mb.id
            WHERE m.membre_id = ?
            UNION ALL
            SELECT 'admin' AS expediteur, ma.contenu, ma.date_envoi, 'Admin' AS nom_expediteur
            FROM message_admin_vers_membre ma
            WHERE ma.membre_id = ?
            ORDER BY date_envoi ASC
        ", [$membreId, $membreId]);

        return new JsonResponse($messages);
    }

}
