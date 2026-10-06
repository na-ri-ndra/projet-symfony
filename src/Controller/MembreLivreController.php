<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Request;

final class MembreLivreController extends AbstractController
{

    #[Route('/membre/livres', name: 'membre_livres')]
    public function index(SessionInterface $session, Connection $connection): Response
    {
        $membre = $session->get('membre'); // Récupère le membre de la session
        if (!$membre || !isset($membre['id'])) {
            // Redirige si le membre n'est pas connecté
            $this->addFlash('error', 'Vous devez être connecté en tant que membre.');
            return $this->redirectToRoute('app_accueil');
        }
        if (!$membre) {
            return $this->redirectToRoute('app_accueil'); // Redirige si pas connecté
        }

        $nonLusCount = 0;
        if ($membre) {
            $nonLusCount = $connection->fetchOne(
                "SELECT COUNT(*) FROM message_admin_vers_membre WHERE membre_id = ? AND est_lu = 0",
                [$membre['id']]
            );
        }

        // --- NOUVEAU : Récupérer les informations du membre les plus récentes depuis la base de données ---
        $membreDataFromDb = $connection->fetchAssociative(
            "SELECT * FROM membre_bibliotheque WHERE id = ?",
            [$membre['id']]
        );

        if (!$membreDataFromDb) {
            // Cas rare : le membre n'existe plus en DB, invalide la session
            $session->invalidate();
            $this->addFlash('error', 'Votre compte n\'existe plus ou a été désactivé.');
            return $this->redirectToRoute('app_accueil');
        }

        // Mettre à jour l'objet membre en session avec les données les plus récentes de la DB
        // C'est crucial pour que 'a_reservation_active' soit toujours à jour
        $session->set('membre', $membreDataFromDb);
        $membre = $membreDataFromDb; // Utilise la version à jour du membre pour le template

        $nonLus = $connection->fetchOne(
            "SELECT COUNT(*) FROM message_admin_vers_membre WHERE membre_id = ? AND est_lu = 0",
            [$membre['id']]
        );

        $livre = $connection->fetchAllAssociative('
            SELECT l.*, e.code_etagere
            FROM livre l
            LEFT JOIN etagere e ON l.etagere_id = e.id
        ');

        $etageres = $connection->fetchAllAssociative('SELECT * FROM etagere');
        $genres = $connection->fetchAllAssociative('SELECT * FROM genre_livre');

        return $this->render('membre_livre/index.html.twig', [ // Assure-toi que c'est le bon chemin pour ton template
            'controller_name' => 'MembreLivreController',
            'membre' => $membre, // Passe l'objet membre MIS À JOUR au template
            'livres' => $livre,
            'etageres' => $etageres,
            'genres' => $genres,
            'nonLus' => (int)$nonLusCount,
            
        ]);
    }

    #[Route('/membre/reservation/nouvelle', name: 'membre_reservation_nouvelle', methods: ['POST'])]
    public function creerReservation(Request $request, Connection $connection): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $membre = $request->getSession()->get('membre');
        if (!$membre || !isset($membre['id'])) {
            // sécurité : redirection si le membre n'est pas connecté
            return $this->redirectToRoute('app_accueil');
        }
        $membreId = $membre['id'];
        $nbLivre = (int) $request->request->get('nb_livre');

        // Créer la réservation
        $connection->insert('reservation', [
            'membre_id' => $membreId,
            'nb_livre' => $nbLivre,
            'date_reservation' => date('Y-m-d H:i:s'),
            'statut' => 'en attente'
        ]);

        $reservationId = $connection->lastInsertId();

        // Rediriger vers la sélection de livres
        return $this->redirectToRoute('membre_reservation_selection', ['reservationId' => $reservationId]);
    }

    #[Route('/membre/reservation/{reservationId}/selection', name: 'membre_reservation_selection')]
    public function selectionLivre(int $reservationId, Request $request, Connection $connection): Response
    {
        $livres = $connection->fetchAllAssociative("SELECT * FROM livre WHERE nombre_exemplaire > 0");

        $reservation = $connection->fetchAssociative("SELECT * FROM reservation WHERE id = ?", [$reservationId]);

        // Récupération du membre depuis la session
        $membre = $request->getSession()->get('membre');

        return $this->render('membre_livre/reservation_selection.html.twig', [
            'livres' => $livres,
            'reservationId' => $reservationId,
            'nb_livre' => $reservation['nb_livre'],
            'membre' => $membre
        ]);
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

    

    #[Route('/membre/reservation/{reservationId}/envoyer', name: 'membre_reservation_envoyer', methods: ['POST'])]
public function envoyerReservation(int $reservationId, Request $request, Connection $connection): Response
{
    $livres = $request->request->all('livres');
    $dateRetrait = $request->request->get('dateRetrait');
    $dateRetour = $request->request->get('dateRetour');
    $membre = $this->getUser();

    if (!$membre || !$livres || !$dateRetrait || !$dateRetour) {
        $this->addFlash('error', 'Informations manquantes.');
        return $this->redirectToRoute('membre_livres');
    }

    foreach ($livres as $livreId) {
        $connection->insert('reserver_livre', [
            'reservation_id' => $reservationId,
            'livre_id' => $livreId,
            'etat' => 'en attente',
            'date_retrait' => $dateRetrait,
            'date_retoure' => $dateRetour,
        ]);
    }

    // Contenu du message
    $contenu = "Le membre {$membre->getNom()} {$membre->getPrenom()} (ID {$membre->getId()}) souhaite réserver les livres : " .
        implode(',', $livres) . " du $dateRetrait au $dateRetour.";

    $connection->insert('message', [
        'expediteur_id' => $membre->getId(),
        'destinataire_id' => 1,
        'contenu' => $contenu,
        'statut' => 'non lu',
        'date_envoi' => (new \DateTime())->format('Y-m-d H:i:s')
    ]);

    $this->addFlash('success', 'Votre demande de réservation a été envoyée à l’administrateur.');
    return $this->redirectToRoute('membre_livres');
}  



}