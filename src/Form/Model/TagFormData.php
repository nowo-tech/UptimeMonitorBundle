<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form\Model;

/** Form model for creating a tenant tag. */
final class TagFormData
{
    public string $name  = '';
    public string $color = '';
}
