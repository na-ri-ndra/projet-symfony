<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\DBAL\Connection;

final class EtagereController extends AbstractController
{
    #[Route('/etagere', name: 'app_etagere')]
    public function index(Connection $connection, SessionInterface $session): Response
    {
        $etagereList = $connection->fetchAllAssociative("
            SELECT e.id, e.code_etagere, e.emplacement, e.capacite_max,
                COALESCE(SUM(l.nombre_exemplaire), 0) AS nombre_livres_actuel
            FROM etagere e
            LEFT JOIN livre l ON e.id = l.etagere_id
            GROUP BY e.id, e.code_etagere, e.emplacement, e.capacite_max
        ");
        $admin = $session->get('admin');

        return $this->render('etagere/index.html.twig', [
            'page_title' => 'Etagiere des livre',
            'etagereList' => $etagereList,
            'admin' => $admin,
        ]);
        
    }
    #[Route('/etagere/ajouter', name: 'app_etagere_ajouter', methods: ['POST'])]
    public function ajouter(Connection $connection, Request $request): Response
    {
        // Récupération des données du formulaire
        $code_etagere = $request->request->get('code_etagere');
        $emplacement = $request->request->get('emplacement');
        $capacite_max = (int) $request->request->get('capacite_max');

        // Préparation et exécution de la requête SQL
        $sql = "INSERT INTO etagere (code_etagere, emplacement, capacite_max, nombre_livres_actuel)
                VALUES (:code, :emplacement, :capacite, :actuel)";
        
        $stmt = $connection->prepare($sql);
        $stmt->execute([
            'code' => $code_etagere,
            'emplacement' => $emplacement,
            'capacite' => $capacite_max,
            'actuel' => 0, // initialisé à 0
        ]);

        // Redirection vers la liste
        return $this->redirectToRoute('app_etagere');
    }
    #[Route('/etagere/modifier/{id}', name: 'app_etagere_modifier', methods: ['POST'])]
    public function modifier(int $id, Connection $connection, Request $request): Response
    {
        $code = $request->request->get('code_etagere');
        $emplacement = $request->request->get('emplacement');
        $capacite = (int) $request->request->get('capacite_max');

        $sql = "UPDATE etagere 
                SET code_etagere = :code, emplacement = :emplacement, capacite_max = :capacite 
                WHERE id = :id";

        $stmt = $connection->prepare($sql);
        $stmt->execute([
            'code' => $code,
            'emplacement' => $emplacement,
            'capacite' => $capacite,
            'id' => $id
        ]);

        return $this->redirectToRoute('app_etagere');
    }

    #[Route('/etagere/supprimer/{id}', name: 'app_etagere_supprimer', methods: ['POST'])]
    public function supprimer(int $id, Connection $connection): Response
    {
        $sql = "DELETE FROM etagere WHERE id = :id";

        $stmt = $connection->prepare($sql);
        $stmt->execute(['id' => $id]);

        return $this->redirectToRoute('app_etagere');
    }
}
