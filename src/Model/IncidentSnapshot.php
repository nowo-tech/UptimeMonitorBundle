<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Model;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Event\MonitorRecoveredEvent;
use Nowo\UptimeMonitorBundle\Event\MonitorWentDownEvent;

/**
 * Scalar view of an incident and its monitor (since 1.25): what a host anti-corruption layer copies out of
 * {@see MonitorWentDownEvent} / {@see MonitorRecoveredEvent}
 * without touching the bundle's entities.
 */
final readonly class IncidentSnapshot
{
    /**
     * Creates the snapshot.
     *
     * @param string $tenantSlug Tenant of the monitor (a project UUID in Beacon)
     * @param int|null $monitorId Monitor id (null only for a monitor that was never persisted)
     * @param string $monitorName Monitor name
     * @param MonitorType $monitorType Monitor type
     * @param int|null $incidentId Incident id (null only for an incident that was never persisted)
     * @param CheckStatus $triggerStatus Status that opened the incident
     * @param string|null $message Message of the check that opened the incident
     * @param DateTimeImmutable $startedAt When the incident opened
     * @param DateTimeImmutable|null $endedAt When it resolved (null while open)
     */
    public function __construct(
        public string $tenantSlug,
        public ?int $monitorId,
        public string $monitorName,
        public MonitorType $monitorType,
        public ?int $incidentId,
        public CheckStatus $triggerStatus,
        public ?string $message,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endedAt,
    ) {
    }

    /**
     * Snapshot of the incident as it is now.
     */
    public static function fromIncident(Incident $incident): self
    {
        $monitor = $incident->getMonitor();

        return new self(
            $monitor->getTenant()->getSlug(),
            $monitor->getId(),
            $monitor->getName(),
            $monitor->getType(),
            $incident->getId(),
            $incident->getTriggerStatus(),
            $incident->getMessage(),
            $incident->getStartedAt(),
            $incident->getEndedAt(),
        );
    }
}
