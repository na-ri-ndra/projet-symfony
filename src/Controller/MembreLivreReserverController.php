<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class MembreLivreReserverController extends AbstractController
{
    #[Route('/membre/livre/reserver', name: 'app_membre_livre_reserver')]
    public function index(SessionInterface $session, Connection $connection): Response
    {
        $membreSession = $session->get('membre');

        // Vérifie si le membre est connecté
        if (!$membreSession || !isset($membreSession['id'])) {
            $this->addFlash('error', 'Vous devez être connecté pour accéder à vos réservations.');
            return $this->redirectToRoute('app_accueil'); // Redirige vers la page d'accueil ou de connexion
        }

        $membreId = $membreSession['id'];

        // Récupère les réservations du membre avec les détails des livres
        $livresReserves = $connection->fetchAllAssociative('
            SELECT
                r.id AS reservation_id,
                r.date_reservation,
                r.statut AS reservation_statut,
                r.date_retrait,
                l.id AS livre_id,
                l.titre,
                l.auteur,
                l.isbn,
                l.nombre_exemplaire,
                e.code_etagere
            FROM reservation r
            JOIN livre_reservation lr ON r.id = lr.reservation_id
            JOIN livre l ON lr.livre_id = l.id
            LEFT JOIN etagere e ON l.etagere_id = e.id
            WHERE r.membre_id = :membre_id
            ORDER BY r.date_reservation DESC, l.titre ASC
        ', [':membre_id' => $membreId]);

        // Grouper les livres par réservation pour un affichage structuré
        $reservationsGroupees = [];
        foreach ($livresReserves as $item) {
            $reservationId = $item['reservation_id'];
            if (!isset($reservationsGroupees[$reservationId])) {
                $reservationsGroupees[$reservationId] = [
                    'id' => $item['reservation_id'],
                    'date_reservation' => $item['date_reservation'],
                    'statut' => $item['reservation_statut'],
                    'date_retrait' => $item['date_retrait'],
                    'livres' => []
                ];
            }
            $reservationsGroupees[$reservationId]['livres'][] = [
                'id' => $item['livre_id'],
                'titre' => $item['titre'],
                'auteur' => $item['auteur'],
                'isbn' => $item['isbn'],
                'nombre_exemplaire' => $item['nombre_exemplaire'],
                'code_etagere' => $item['code_etagere']
            ];
        }

        return $this->render('membre_livre_reserver/index.html.twig', [
            'page_title' => 'Mes Réservations', // Un titre pour la page
            'membre' => $membreSession, // Passe le membre de la session, utile pour le nav
            'reservations_groupees' => $reservationsGroupees, // Les données principales à afficher
        ]);
    }

    // Tu peux réutiliser ici la méthode d'annulation si tu veux qu'elle soit accessible directement depuis cette page
    #[Route('/membre/reservation/{id}/annuler', name: 'app_membre_annuler_reservation', methods: ['POST'])]
    public function annulerReservation(int $id, Connection $connection, SessionInterface $session): Response
    {
        $membre = $session->get('membre');
        if (!$membre || !isset($membre['id'])) {
            $this->addFlash('error', 'Vous devez être connecté.');
            return $this->redirectToRoute('app_accueil');
        }

        $connection->beginTransaction();
        try {
            $reservation = $connection->fetchAssociative(
                "SELECT id, membre_id, statut FROM reservation WHERE id = ? AND membre_id = ?",
                [$id, $membre['id']]
            );

            if (!$reservation || $reservation['statut'] !== 'en attente') {
                throw new \Exception('Réservation introuvable ou ne peut pas être annulée.');
            }

            $connection->update('reservation', ['statut' => 'annulée'], ['id' => $id]);

            $hasOtherActiveReservations = $connection->fetchOne(
                "SELECT COUNT(*) FROM reservation WHERE membre_id = ? AND statut IN ('en attente', 'emprunté')",
                [$membre['id']]
            );

            if ($hasOtherActiveReservations == 0) {
                $connection->update(
                    'membre_bibliotheque',
                    ['a_reservation_active' => FALSE],
                    ['id' => $membre['id']]
                );
                $membre['a_reservation_active'] = false; // Met à jour la session
                $session->set('membre', $membre);
            }

            $connection->commit();
            $this->addFlash('success', 'Votre réservation a été annulée avec succès.');

        } catch (\Exception $e) {
            $connection->rollBack();
            $this->addFlash('error', 'Erreur lors de l\'annulation de la réservation : ' . $e->getMessage());
        }

        return $this->redirectToRoute('membre_mes_reservations'); // Redirige vers la nouvelle page de réservations
    }
}
