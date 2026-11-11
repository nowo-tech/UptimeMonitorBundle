<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Fixtures\Host\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An application entity of the both-host tests (1.27.0, REQ-ARCH-001 rule 6): the bundle must never flush it. Mapped
 * by attributes for the standard host and by config/doctrine/HostNote.orm.xml for the DDD host.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_note')]
class HostNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $body,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
