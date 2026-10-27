<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A tenant: an isolated set of monitors with its own settings, addressed by slug.
 */
#[ORM\Entity]
#[ORM\Table(name: 'uptime_tenant')]
#[ORM\UniqueConstraint(name: 'uniq_uptime_tenant_slug', columns: ['slug'])]
class Tenant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType (Doctrine sets id via reflection) */
    private ?int $id = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    /** @var array<string, mixed> Uptime Kuma–style tenant settings (General, Appearance, etc.) */
    #[ORM\Column(type: Types::JSON)]
    private array $settings = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * Creates an enabled tenant.
     *
     * @param string $slug URL slug (lowercase letters, digits, dashes)
     * @param string $name Display name
     */
    public function __construct(#[ORM\Column(type: Types::STRING, length: 64)]
        private string $slug, #[ORM\Column(type: Types::STRING, length: 255)]
        private string $name)
    {
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * Returns the database id (null until persisted).
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Returns the URL slug.
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * Returns the display name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Whether the tenant checks run (a disabled tenant keeps its data).
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enables or disables the tenant checks.
     */
    public function setEnabled(bool $enabled): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->enabled = $enabled;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /** @param array<string, mixed> $settings */
    public function setSettings(array $settings): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->settings = $settings;

        return $this;
    }

    /**
     * Returns the creation time.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
