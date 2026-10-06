<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class LivreIndisponibleController extends AbstractController
{
    #[Route('/admin/livre-indisponible', name: 'app_livre_indisponible')]
    public function index(Connection $connection, SessionInterface $session): Response
    {
        $admin = $session->get('admin');

        // ---- Récupération uniquement des prêts ----
        $sql = "
            SELECT p.date_pret, p.date_retour_effectif, m.nom, m.prenom, l.titre, l.id AS livre_id
            FROM pret_physique p
            JOIN membre_bibliotheque m ON p.membre_bibliotheque_id = m.id
            JOIN livre_pret lp ON lp.pret_physique_id = p.id
            JOIN livre l ON lp.livre_id = l.id
            ORDER BY p.date_pret DESC
        ";

        $prets = $connection->fetchAllAssociative($sql);

        return $this->render('livre_indisponible/index.html.twig', [
            'page_title' => 'Livres en prêt',
            'admin' => $admin,
            'prets' => $prets,
        ]);
    }
    #[Route('/admin/livre-indisponible/retourner', name: 'app_livre_indisponible_retourner', methods: ['POST'])]
    public function retournerLivrePret(Connection $connection, Request $request): JsonResponse
    {
        $livreId = $request->request->get('livre_id');
        $datePret = $request->request->get('date_pret'); // si besoin pour identifier le prêt

        if (!$livreId) {
            return new JsonResponse(['success' => false, 'message' => 'Livre introuvable']);
        }

        // 1️⃣ Supprimer ou marquer le prêt comme retourné
        $connection->executeStatement("
            UPDATE pret_physique p
            JOIN livre_pret lp ON lp.pret_physique_id = p.id
            SET p.date_retour_effectif = NOW()
            WHERE lp.livre_id = ?
            AND p.date_retour_effectif IS NULL
            LIMIT 1
        ", [$livreId]);

        // 2️⃣ Augmenter le nombre d'exemplaire du livre
        $connection->executeStatement("
            UPDATE livre
            SET nombre_exemplaire = nombre_exemplaire + 1
            WHERE id = ?
        ", [$livreId]);

        return new JsonResponse(['success' => true]);
    }





    
    #[Route('/admin/livre-indisponible/reservation', name: 'app_livre_indisponible_reservation')]
    public function reservation(Connection $connection, SessionInterface $session): Response
    {
        $admin = $session->get('admin');

        // ---- Récupération uniquement des réservations ----
        $sql = "
            SELECT rl.id AS reservation_livre_id,
                l.id AS livre_id,
                l.titre,
                m.nom,
                m.prenom,
                r.date_reservation,
                m.id AS membre_id
            FROM reserver_livre rl
            JOIN reservation r ON rl.reservation_id = r.id
            JOIN membre_bibliotheque m ON r.membre_id = m.id
            JOIN livre l ON rl.livre_id = l.id
            WHERE rl.etat = 'réservé'
        ";

        $reserves = [];
        $stmt = $connection->query($sql);
        while ($row = $stmt->fetchAssociative()) {
            // Vérifier si un message contient une date de retrait
            $message = $connection->fetchOne("
                SELECT contenu
                FROM message_membre_vers_admin
                WHERE membre_id = ?
                  AND contenu LIKE '%Date de retrait%'
                ORDER BY date_envoi DESC
                LIMIT 1
            ", [$row['membre_id']]);

            $dateRetrait = null;
            if ($message && preg_match('/Date de retrait\s*:\s*(\d{4}-\d{2}-\d{2})/', $message, $matches)) {
                $dateRetrait = $matches[1];
            }

            $row['statut'] = 'Réservé';
            $row['date_action'] = $row['date_reservation'];
            $row['date_retrait'] = $dateRetrait;

            $reserves[] = $row;
        }

        return $this->render('livre_indisponible/reservation.html.twig', [
            'page_title' => 'Réservations de livres',
            'admin' => $admin,
            'reservations' => $reserves,
        ]);
    }


#[Route('/admin/reservation/{id}/cancel', name: 'app_admin_reservation_cancel', methods: ['POST'])]
    public function cancelReservation(int $id, Connection $connection): Response
    {
        $connection->beginTransaction();
        try {
            // Mettre à jour le statut de la réservation à 'annulée'
            $connection->update(
                'reservation',
                ['statut' => 'annulée'],
                ['id' => $id]
            );

            // Récupérer l'ID du membre associé
            $reservation = $connection->fetchAssociative("SELECT membre_id FROM reservation WHERE id = ?", [$id]);
            $membreId = $reservation['membre_id'];

            // --- NOUVEAU : Désactiver 'a_reservation_active' pour le membre ---
            // Vérifier s'il n'y a pas d'autres réservations "actives" pour ce membre
            $hasOtherActiveReservations = $connection->fetchOne(
                "SELECT COUNT(*) FROM reservation WHERE membre_id = ? AND statut IN ('en attente', 'emprunté')",
                [$membreId]
            );

            if ($hasOtherActiveReservations == 0) {
                $connection->update(
                    'membre_bibliotheque',
                    ['a_reservation_active' => FALSE],
                    ['id' => $membreId]
                );
            }

            $connection->commit();
            $this->addFlash('success', 'Réservation annulée et statut du membre mis à jour.');
        } catch (\Exception $e) {
            $connection->rollBack();
            $this->addFlash('error', 'Erreur lors de l\'annulation de la réservation : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_reservations');
    }

    #[Route('/reservation/confirm', name: 'app_reservation_confirm', methods: ['POST'])]
    public function confirmReservation(Request $request, Connection $connection, SessionInterface $session): Response // Ajout de SessionInterface
    {
        // --- Vérifier l'état de réservation active AVANT de permettre une nouvelle réservation ---
        $membreSession = $session->get('membre'); // Récupère le membre de la session, qui est un tableau
        if (!$membreSession || !isset($membreSession['id'])) {
            $this->addFlash('error', 'Vous devez être connecté en tant que membre pour effectuer une réservation.');
            return $this->redirectToRoute('app_accueil'); // Redirige vers la page de connexion
        }

        // Récupérer l'état a_reservation_active le plus récent depuis la base de données
        // pour s'assurer que l'état en session est à jour
        $currentMembreDb = $connection->fetchAssociative(
            "SELECT id, nom, prenom, a_reservation_active FROM membre_bibliotheque WHERE id = ?",
            [$membreSession['id']]
        );

        if (!$currentMembreDb) {
            $this->addFlash('error', 'Membre introuvable.'); // Ou log l'erreur et déconnecte.
            return $this->redirectToRoute('app_accueil');
        }

        // Mettre à jour l'objet membre en session avec les données de la base
        $session->set('membre', $currentMembreDb);
        $membre = $currentMembreDb; // Utilise la version à jour du membre

        // Bloquer si une réservation est déjà active
        if ($membre['a_reservation_active']) {
            $this->addFlash('error', 'Vous avez déjà une réservation en cours. Veuillez la finaliser avant d\'en faire une nouvelle.');
            return $this->redirectToRoute('membre_livres'); // Rediriger vers la page des livres du membre
        }

        // Ancienne logique de récupération du membre si tu utilisais $this->getUser() avec des entités Doctrine
        // $membre = $this->getUser(); // Cette ligne n'est plus nécessaire si tu gères le membre via la session comme dans ton code.
        // if (!$membre instanceof \App\Entity\MembreBibliotheque) { ... }

        $membreId = $membre['id']; // Utilise l'ID du membre du tableau de session
        $membreNom = $membre['nom'] . ' ' . $membre['prenom']; // Nom complet du membre

        $selectedBookIds = $request->request->all('selected_books');
        $dateRetrait = new \DateTime($request->request->get('date_retrait'));
        $dateRetour = new \DateTime($request->request->get('date_retour'));

        if (empty($selectedBookIds) || !$dateRetrait || !$dateRetour) {
            $this->addFlash('error', 'Veuillez sélectionner au moins un livre et remplir les dates de retrait et de retour.');
            return $this->redirectToRoute('membre_livres'); // Assurez-vous que cette route existe
        }

        $connection->beginTransaction();

        try {
            // Création de l'entrée dans la table 'reservation'
            $connection->insert('reservation', [
                'membre_id' => $membreId,
                'date_reservation' => (new \DateTime())->format('Y-m-d H:i:s'),
                'nb_livre' => count($selectedBookIds),
                'statut' => 'en attente'
            ]);
            $reservationId = $connection->lastInsertId();

            // ... (ton code existant pour récupérer les titres des livres et construire le message) ...
            $titresLivres = [];
            if (!empty($selectedBookIds)) {
                $placeholders = implode(', ', array_fill(0, count($selectedBookIds), '?'));
                $stmt = $connection->prepare("SELECT titre FROM livre WHERE id IN ({$placeholders})");
                $stmt->executeStatement($selectedBookIds);
                $booksData = $stmt->fetchAllAssociative();
                foreach ($booksData as $book) {
                    $titresLivres[] = $book['titre'];
                }
            }

            $contenuMessage = sprintf(
                "Nouvelle demande de réservation:\n" .
                "----------------------------------------\n" .
                "**ID Réservation:** %d\n" .
                "**Membre:** %s (ID: %d)\n" .
                "**Date de la demande:** %s\n" .
                "**Nombre de livres:** %d\n" .
                "**Livres demandés:**\n - %s\n" .
                "**Date de retrait souhaitée:** %s\n" .
                "**Date de retour souhaitée:** %s\n" .
                "----------------------------------------\n" .
                "Statut actuel: En attente d'approbation.",
                $reservationId,
                $membreNom,
                $membreId,
                (new \DateTime())->format('d/m/Y H:i:s'),
                count($selectedBookIds),
                implode("\n - ", $titresLivres),
                $dateRetrait->format('d/m/Y'),
                $dateRetour->format('d/m/Y')
            );

            // Enregistrer le message dans la table `message_membre_vers_admin`
            $adminId = $connection->fetchOne('SELECT id FROM administrateur LIMIT 1');
            if (!$adminId) {
                throw new \Exception('Aucun administrateur trouvé pour envoyer le message. Veuillez en créer un.');
            }

            $connection->insert('message_membre_vers_admin', [
                'membre_id' => $membreId,
                'admin_id' => $adminId,
                'contenu' => $contenuMessage,
                'date_envoi' => (new \DateTime())->format('Y-m-d H:i:s'),
                'lu' => 0,
                'est_lu' => 0
            ]);

            // --- NOUVEAU : Mettre à jour l'état du membre à 'a_reservation_active = TRUE' ---
            $connection->update(
                'membre_bibliotheque',
                ['a_reservation_active' => TRUE],
                ['id' => $membreId]
            );

            // Mettre à jour la session du membre pour que l'état soit instantanément reflété
            $membre['a_reservation_active'] = true;
            $session->set('membre', $membre);

            $connection->commit();

            $this->addFlash('success', 'Votre réservation a été confirmée et un message a été envoyé à l\'administrateur.');
            return $this->redirectToRoute('membre_livres'); // Rediriger vers la page des livres du membre
        } catch (\Exception $e) {
            $connection->rollBack();
            $this->addFlash('error', 'Une erreur est survenue lors de la confirmation de votre réservation: ' . $e->getMessage());
            // Loggez l'erreur pour le débogage: $this->logger->error($e->getMessage());
            return $this->redirectToRoute('membre_livres'); // Assurez-vous que cette route existe
        }
    }

}
