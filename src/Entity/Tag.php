<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Tenant-scoped label for organizing monitors (Uptime Kuma tags). */
#[ORM\Entity]
#[ORM\Table(name: 'uptime_tag')]
#[ORM\UniqueConstraint(name: 'uniq_uptime_tag_tenant_name', columns: ['tenant_id', 'name'])]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $color = null;

    public function __construct(Tenant $tenant, string $name)
    {
        $this->tenant = $tenant;
        $this->name   = trim($name);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $trimmed     = $color !== null ? trim($color) : null;
        $this->color = $trimmed === '' ? null : $trimmed;

        return $this;
    }
}
