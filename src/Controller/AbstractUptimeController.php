<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Controller;

use Nowo\UptimeMonitorBundle\Translation\UptimeTranslation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Base controller for bundle HTTP actions (Symfony 8+ removed {@see AbstractController::trans}).
 */
abstract class AbstractUptimeController extends AbstractController
{
    public function __construct(
        protected readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, float|int|string> $parameters
     */
    protected function transMessage(string $id, array $parameters = []): string
    {
        return $this->translator->trans($id, $parameters, UptimeTranslation::DOMAIN);
    }
}
