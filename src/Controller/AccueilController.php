<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\DBAL\Connection;

final class AccueilController extends AbstractController
{
    #[Route('/accueil', name: 'app_accueil')]
    public function index(Request $request, Connection $connection, SessionInterface $session): Response
    {
        if ($request->isMethod('POST')) {
            $statut = $request->request->get('statut');
            $nom_utilisateur = trim($request->request->get('nom_utilisateur'));
            $mot_de_passe = $request->request->get('mot_de_passe');
            $error = null;

            if ($statut === 'administrateur') {
                $admin = $connection->fetchAssociative(
                    "SELECT * FROM administrateur WHERE nom_utilisateur = ?",
                    [$nom_utilisateur]
                );

                if (!$admin) {
                    $error = "Nom d'utilisateur administrateur incorrect.";
                } elseif ($admin['mot_de_passe'] !== $mot_de_passe) {
                    $error = "Mot de passe administrateur incorrect.";
                } else {
                    $session->set('admin', $admin);
                    return $this->redirectToRoute('app_dashboard'); // Page d'accueil admin
                }
            } elseif ($statut === 'membre') {
                $membre = $connection->fetchAssociative(
                    "SELECT * FROM membre_bibliotheque WHERE nom = ?",
                    [$nom_utilisateur]
                );

                if (!$membre) {
                    $error = "Nom du membre incorrect.";
                } elseif (!password_verify($mot_de_passe, $membre['mot_de_passe'])) {
                    $error = "Mot de passe incorrect.";
                } elseif ($membre['etat'] == 0) {
                    // Compte désactivé
                    $this->addFlash('danger', 'Compte désactivé. Veuillez contacter l’administrateur.');
                    return $this->redirectToRoute('app_accueil');
                } else {
                    $session->set('membre', $membre);
                    return $this->redirectToRoute('membre_livres'); // Page membre
                }
            } else {
                $error = "Statut invalide.";
            }

            return $this->render('accueil/index.html.twig', [
                'login_error' => $error,
                'nom_utilisateur' => $nom_utilisateur,
            ]);
        }

        return $this->render('accueil/index.html.twig');
    }

    #[Route('/inscription/membre', name: 'app_inscription_membre', methods: ['POST'])]
    public function inscriptionMembre(Request $request, Connection $connection): Response
    {
        $nom = $request->request->get('nom');
        $prenom = $request->request->get('prenom');
        $type = $request->request->get('type_personne');
        $matricule = $request->request->get('matricule');
        $mdp = $request->request->get('mdp');
        // Vérifie si ces informations existent déjà dans membre_bibliotheque
        $dejaInscrit = $connection->fetchAssociative(
            "SELECT * FROM membre_bibliotheque 
            WHERE nom = ? AND prenom = ? AND type_personne = ? AND matricule = ?",
            [$nom, $prenom, $type, $matricule]
        );

        if ($dejaInscrit) {
            $this->addFlash('danger', 'Cette personne est déjà inscrite.');
            return $this->redirectToRoute('app_accueil');
        }

        // Vérification dans la base
        $sql = "SELECT * FROM personne_autorisee 
                WHERE BINARY nom = ? AND BINARY prenom = ? AND BINARY type_personne = ? AND BINARY matricule = ?";
        $result = $connection->fetchAssociative($sql, [$nom, $prenom, $type, $matricule]);

        if (!$result) {
            $this->addFlash('danger', 'Inscription échouée : les données ne correspondent pas.');
            return $this->redirectToRoute('app_accueil');
        }

        // Vérifie si déjà inscrit
        $existe = $connection->fetchAssociative("SELECT * FROM membre_bibliotheque WHERE matricule = ?", [$matricule]);
        if ($existe) {
            $this->addFlash('danger', 'Ce matricule est déjà inscrit.');
            return $this->redirectToRoute('app_accueil');
        }

        // Insertion
        $connection->insert('membre_bibliotheque', [
            'nom' => $nom,
            'prenom' => $prenom,
            'type_personne' => $type,
            'matricule' => $matricule,
            'mot_de_passe' => password_hash($mdp, PASSWORD_BCRYPT),
            'date_inscription' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);

        $this->addFlash('success', 'Inscription réussie !');
        return $this->redirectToRoute('app_accueil');
    }
    
    #[Route('/logout', name: 'app_logout')]
    public function logout(SessionInterface $session): Response
    {
        $session->clear(); // Supprime toutes les données de session
        return $this->redirectToRoute('app_accueil');
    }
}
