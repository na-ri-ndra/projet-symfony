<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Doctrine\DBAL\Connection;

final class NotificationAdminController extends AbstractController
{
    #[Route('/notification/admin', name: 'app_notification_admin')]
    public function index(SessionInterface $session, Connection $connection): Response
    {
        $admin = $session->get('admin');
        return $this->render('notification_admin/index.html.twig', [
            'controller_name' => 'NotificationAdminController',
            'admin' => $admin,
            'page_title' => 'Liste des membre bibliothèque',
        ]);
    }
}
