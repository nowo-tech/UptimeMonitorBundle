<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;

/**
 * The monitors every monitor-search test (query, visibility, query budget, the SearchKit provider) searches: two
 * tenants, a shared name, a credential-bearing URL, wildcard characters, a group and a push monitor. Reused by the
 * provider tests, so the data and the expectations live in one place.
 */
final class MonitorSearchFixtures
{
    public Tenant $acme;
    public Tenant $secret;

    /** @var array<string, Monitor> */
    public array $monitors = [];

    /**
     * Persists the fixtures and returns them.
     */
    public static function load(EntityManagerInterface $em): self
    {
        $f         = new self();
        $f->acme   = new Tenant('acme', 'Acme');
        $f->secret = new Tenant('user-secret', 'Secret');
        $em->persist($f->acme);
        $em->persist($f->secret);

        $f->add($em, 'api', $f->acme, 'Billing API', MonitorType::Https, 'https://user:hunter2@billing.example.com/health?token=SECRETTOKEN#x', CheckStatus::Up);
        $f->add($em, 'web', $f->acme, 'Website', MonitorType::Https, 'https://www.example.com/', CheckStatus::Down);
        $f->add($em, 'pct', $f->acme, '100% uptime_check', MonitorType::Tcp, 'db.example.com:5432', null);
        $f->add($em, 'plain', $f->acme, '100X uptimeXcheck', MonitorType::Tcp, 'other.example.com:5432', null);
        $f->add($em, 'group', $f->acme, 'Billing group', MonitorType::Group, '', null);
        $f->add($em, 'push', $f->acme, 'Billing cron', MonitorType::Push, 'pushtarget-SECRETPUSH', null);
        $f->add($em, 'closed', $f->secret, 'Billing hidden', MonitorType::Https, 'https://hidden.example.com/', CheckStatus::Up);
        $f->add($em, 'Shared', $f->secret, 'Shared billing', MonitorType::Https, 'https://shared.example.com/', CheckStatus::Up);
        $em->flush();

        return $f;
    }

    private function add(EntityManagerInterface $em, string $key, Tenant $tenant, string $name, MonitorType $type, string $target, ?CheckStatus $status): void
    {
        $monitor = new Monitor($tenant, $name, $type, $target);
        if ($status !== null) {
            $monitor->setLastKnownStatus($status);
        }

        $em->persist($monitor);
        $this->monitors[$key] = $monitor;
    }
}
