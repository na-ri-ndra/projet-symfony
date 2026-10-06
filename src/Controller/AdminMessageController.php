<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\DBAL\Connection;

final class AdminMessageController extends AbstractController
{
    #[Route('/message/admin', name: 'app_admin_message')]
    public function index(Request $request, Connection $connection, SessionInterface $session): Response
    {
        
        $admin = $session->get('admin');
    if (!$admin) {
        return $this->redirectToRoute('app_accueil');
    }

    $membres = $connection->fetchAllAssociative("SELECT * FROM membre_bibliotheque");
    $membre_id = $request->query->get('membre_id');
    $historique = [];

    if ($membre_id) {
        $historique = $connection->fetchAllAssociative("
            SELECT 'membre' AS sender, contenu, date_envoi 
            FROM message_membre_vers_admin
            WHERE admin_id = ? AND membre_id = ?
            UNION
            SELECT 'admin' AS sender, contenu, date_envoi 
            FROM message_admin_vers_membre
            WHERE admin_id = ? AND membre_id = ?
            ORDER BY date_envoi ASC
        ", [$admin['id'], $membre_id, $admin['id'], $membre_id]);
    }

    $membre_id = $request->query->get('membre_id'); // C'est déjà là, parfait

        return $this->render('admin_message/index.html.twig', [
            'membres' => $membres,
            'historique' => $historique,
            'membre_id_selectionne' => $membre_id, // C'est essentiel pour le champ caché du formulaire
            'admin' => $admin,
        ]);
    }

    #[Route('/admin/messages', name: 'app_admin_messages')]
public function showMemberMessages(Request $request, Connection $connection, SessionInterface $session): Response
{
    $admin = $session->get('admin');
    if (!$admin) {
        return $this->redirectToRoute('app_accueil');
    }

    // Récupérer tous les membres
    $membres = $connection->fetchAllAssociative('SELECT id, nom, prenom FROM membre_bibliotheque');

    // Récupérer le membre sélectionné si présent dans la requête GET (ou POST)
    $membreId = $request->query->get('membre_id');

    // Messages à afficher
    $messages = [];

    if ($membreId) {
        $messages = $connection->fetchAllAssociative(
            'SELECT contenu, date_envoi, NULL AS admin FROM message_membre_vers_admin WHERE membre_id = ?
             UNION
             SELECT contenu, date_envoi, 1 AS admin FROM message_admin_vers_membre WHERE membre_id = ?
             ORDER BY date_envoi ASC',
            [$membreId, $membreId]
        );
    }

    return $this->render('admin_message/index.html.twig', [
        'messages' => $messages,
        'membres' => $membres,
        'membre_id_selectionne' => $membreId, 
        'admin' => $admin,
    ]);
}



    #[Route('/admin/message/{id}/mark_as_read', name: 'app_admin_message_mark_as_read', methods: ['POST'])]
    public function markMessageAsRead(int $id, Connection $connection): Response
    {
        // Mettre à jour le statut 'lu' du message
        $connection->update('message_membre_vers_admin', ['lu' => 1], ['id' => $id]);

        $this->addFlash('success', 'Le message a été marqué comme lu.');

        // Rediriger l'administrateur vers la liste des messages
        return $this->redirectToRoute('app_admin_message');
    }

    #[Route('/admin/messages/envoyer', name: 'admin_envoyer_message', methods: ['POST'])]
public function envoyerMessage(Request $request, Connection $connection, SessionInterface $session): Response
{
    $membreId = $request->request->get('membre_id');
    $contenu = $request->request->get('contenu');

    // ⚠️ Récupérer l'admin connecté depuis la session
    $admin = $session->get('admin'); // Assure-toi que tu as stocké l'admin en session

    if (!$membreId || !$contenu || !$admin) {
        $this->addFlash('error', 'Tous les champs sont obligatoires.');
        return $this->redirectToRoute('app_admin_message');
    }

    // Envoi du message
    $connection->insert('message_admin_vers_membre', [
        'admin_id' => $admin['id'], 
        'membre_id' => $membreId,
        'contenu' => $contenu,
        'date_envoi' => (new \DateTime())->format('Y-m-d H:i:s'),
        'lu' => 0
    ]);

    $this->addFlash('success', 'Message envoyé avec succès.');

    return $this->redirectToRoute('app_admin_message');
}

#[Route('/admin/messages/ajax', name: 'admin_messages_ajax')]
public function getMessagesAjax(Connection $connection, SessionInterface $session): JsonResponse
{
    $admin = $session->get('admin');
    if (!$admin) {
        return new JsonResponse(['error' => 'Non connecté'], 403);
    }

    $nbMessagesNonLus = $connection->fetchOne(
        "SELECT COUNT(*) FROM message_membre_vers_admin WHERE admin_id = ? AND lu = 0",
        [$admin['id']]
    );

    return new JsonResponse(['unread' => (int) $nbMessagesNonLus]);
}
    
    #[Route('/admin/messages/unread-count', name: 'admin_unread_message_count', methods: ['GET'])]
    public function getUnreadMessageCount(EntityManagerInterface $entityManager): JsonResponse
    {
        $count = $entityManager->getRepository(MessageMembreVersAdmin::class)
            ->createQueryBuilder('m')
            ->select('count(m.id)')
            ->where('m.lu = 0')
            ->getQuery()
            ->getSingleScalarResult();

        return new JsonResponse(['unreadCount' => (int)$count]);
    }



}
