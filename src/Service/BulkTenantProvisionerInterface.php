<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

/**
 * Bulk tenant switch (since 1.35), a separate interface so host implementations of {@see TenantProvisionerInterface}
 * keep compiling; {@see TenantProvisioner} implements both and the container aliases this interface to it.
 */
interface BulkTenantProvisionerInterface extends TenantProvisionerInterface
{
    /**
     * Enables or disables every existing tenant whose slug is listed, in ONE `UPDATE` statement on the bundle's manager
     * (in its own transaction, or a savepoint of the caller's). Idempotent: running it again changes nothing. Unknown
     * slugs are ignored, slugs that are not valid tenant slugs are dropped, and an empty list runs no statement.
     *
     * @param list<string> $slugs Tenant slugs
     * @param bool $enabled New state
     *
     * @return int Rows the database reports as affected (MySQL and MariaDB count only the rows whose value changed)
     */
    public function setEnabledMany(array $slugs, bool $enabled): int;
}
