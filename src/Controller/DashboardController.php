<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\DBAL\Connection;
use DateTime;
use DateInterval;

final class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(Connection $connection, SessionInterface $session): Response
{
    $admin = $session->get('admin');
    if (!$admin) {
        return $this->redirectToRoute('app_accueil');
    }

    // Données statistiques
    $totalLivres = $connection->fetchOne('SELECT SUM(nombre_exemplaire) FROM livre');
    $totalEtagere = $connection->fetchOne('SELECT COUNT(*) FROM etagere');
    $totalMembre = $connection->fetchOne('SELECT COUNT(*) FROM membre_bibliotheque');
    $personneAutorisee = $connection->fetchOne('SELECT COUNT(*) FROM personne_autorisee');
    $totalLivreReserver = $connection->fetchOne('SELECT COUNT(*) FROM reserver_livre');
    $totalLivreEmprunte = $connection->fetchOne('SELECT COUNT(*) FROM livre_pret');

    $nonMembresAutorises = $personneAutorisee - $totalMembre;

    // Préparer les données pour Chart.js
    $datesPret = $connection->fetchAllAssociative("
        SELECT DATE(pp.date_pret) as date_p, COUNT(*) as nb_pret
        FROM livre_pret lp
        JOIN pret_physique pp ON lp.pret_physique_id = pp.id
        WHERE pp.date_pret BETWEEN DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND CURDATE()
        GROUP BY DATE(pp.date_pret)
        ORDER BY DATE(pp.date_pret) ASC
    ");

    // Générer toutes les 14 dates
    $allDates = [];
    $dataPret = [];
    for ($i = 13; $i >= 0; $i--) {
        $date = (new \DateTime())->sub(new \DateInterval("P{$i}D"))->format('Y-m-d');
        $allDates[] = $date;
        $found = false;
        foreach ($datesPret as $d) {
            if ($d['date_p'] === $date) {
                $dataPret[] = (int)$d['nb_pret'];
                $found = true;
                break;
            }
        }
        if (!$found) {
            $dataPret[] = 0;
        }
    }
    $labelsPret = $allDates;

    // Evolution des réservations (14 derniers jours)
    $datesReserves = $connection->fetchAllAssociative("
        SELECT DATE(r.date_reservation) as date_res, COUNT(*) as nb_reserves
        FROM reserver_livre rl
        JOIN reservation r ON rl.reservation_id = r.id
        WHERE rl.etat = 'réservé'
        AND r.date_reservation BETWEEN DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND CURDATE()
        GROUP BY DATE(r.date_reservation)
        ORDER BY DATE(r.date_reservation) ASC
    ");

    $dataReserves = [];
    foreach ($allDates as $date) {
        $found = false;
        foreach ($datesReserves as $d) {
            if ($d['date_res'] === $date) {
                $dataReserves[] = (int)$d['nb_reserves'];
                $found = true;
                break;
            }
        }
        if (!$found) {
            $dataReserves[] = 0;
        }
    }

    return $this->render('dashboard/index.html.twig', [
        'totalLivres' => $totalLivres,
        'totalEtagere' => $totalEtagere,
        'totalMembre' => $totalMembre,
        'personneAutorisee' => $personneAutorisee,
        'totalLivreReserver' => $totalLivreReserver,
        'totalLivreEmprunte' => $totalLivreEmprunte,
        'admin' => $admin,
        'labelsPret' => $labelsPret,
        'dataPret' => $dataPret,
        'dataReserves' => $dataReserves,
        'nonMembresAutorises' => $nonMembresAutorises,
    ]);
    }
}
