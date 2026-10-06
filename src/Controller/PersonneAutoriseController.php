<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Doctrine\DBAL\Connection;

final class PersonneAutoriseController extends AbstractController
{
    #[Route('/personne/autorise', name: 'app_personne_autorise')]
    public function index(Connection $connection,Request $request, SessionInterface $session): Response
    {
        $admin = $session->get('admin');
        // Requête SQL : personnes autorisées non inscrites
        $sql = "
            SELECT pa.*
            FROM personne_autorisee pa
            WHERE pa.matricule NOT IN (
                SELECT mb.matricule FROM membre_bibliotheque mb
            )
        ";

        $personnesNonInscrites = $connection->fetchAllAssociative($sql);

        return $this->render('personne_autorise/index.html.twig', [
            'personnes' => $personnesNonInscrites,
            'page_title' => 'Liste des livre',
            'admin' => $admin,
        ]);
    }
    #[Route('/personne/ajouter', name: 'app_personne_ajouter', methods: ['POST'])]
    public function ajouter(Request $request, Connection $connection): Response
    {
        $nom = $request->request->get('nom');
        $prenom = $request->request->get('prenom');
        $type = $request->request->get('type_personne');
        $matricule = $request->request->get('matricule');

        // Requête SQL d'insertion
        $sql = "INSERT INTO personne_autorisee (nom, prenom, type_personne, matricule) 
                VALUES (:nom, :prenom, :type_personne, :matricule)";

        try {
            $connection->executeStatement($sql, [
                'nom' => $nom,
                'prenom' => $prenom,
                'type_personne' => $type,
                'matricule' => $matricule,
            ]);

            $this->addFlash('success', 'Personne ajoutée avec succès.');
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_personne_autorise');
    }

    #[Route('/personne-autorisee/modifier/{id}', name: 'personne_autorisee_modifier', methods: ['POST'])]
    public function modifier(Connection $connection, int $id, Request $request)
    {
        $connection->executeStatement(
            "UPDATE personne_autorisee SET nom = ?, prenom = ?, type_personne = ?, matricule = ? WHERE id = ?",
            [
                $request->request->get('nom'),
                $request->request->get('prenom'),
                $request->request->get('type_personne'),
                $request->request->get('matricule'),
                $id
            ]
        );

        $this->addFlash('success', 'Modification réussie.');
        return $this->redirectToRoute('app_personne_autorise');
    }

    #[Route('/personne-autorisee/supprimer/{id}', name: 'personne_autorisee_supprimer', methods: ['POST'])]
    public function supprimer(Connection $connection, int $id)
    {
        $connection->executeStatement("DELETE FROM personne_autorisee WHERE id = ?", [$id]);

        $this->addFlash('success', 'Suppression réussie.');
        return $this->redirectToRoute('app_personne_autorise');
    }


}
