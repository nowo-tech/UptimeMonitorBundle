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
    /** @phpstan-ignore property.unusedType (Doctrine sets id via reflection) */
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $color = null;

    /**
     * Creates a tag of a tenant.
     *
     * @param string $name Tag name (trimmed)
     */
    public function __construct(#[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant, string $name)
    {
        $this->name = trim($name);
    }

    /**
     * Returns the database id (null until persisted).
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Returns the owning tenant.
     */
    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    /**
     * Returns the tag name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Sets the tag name (trimmed).
     */
    public function setName(string $name): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->name = trim($name);

        return $this;
    }

    /**
     * Returns the tag colour (CSS value), if any.
     */
    public function getColor(): ?string
    {
        return $this->color;
    }

    /**
     * Sets the tag colour (trimmed; an empty value clears it).
     */
    public function setColor(?string $color): self
    {
        $trimmed = $color !== null ? trim($color) : null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->color = $trimmed === '' ? null : $trimmed;

        return $this;
    }
}
