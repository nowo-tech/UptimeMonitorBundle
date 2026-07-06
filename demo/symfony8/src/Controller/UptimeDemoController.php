<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class UptimeDemoController extends AbstractController
{
    #[Route('/', name: 'demo_home')]
    public function home(): RedirectResponse
    {
        return $this->redirectToRoute('nowo_uptime_dashboard', ['tenantSlug' => 'main']);
    }
}
