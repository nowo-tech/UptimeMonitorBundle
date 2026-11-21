<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Share;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\UptimeMonitorBundle\Security\MonitorAwareUptimeMonitorAccessCheckerInterface;

/**
 * Bundle-owned monitor shares (since 1.35, `shares.enabled: true`): which host users may read a monitor outside its
 * tenant. The host keeps the access decision ({@see MonitorAwareUptimeMonitorAccessCheckerInterface},
 * typically `canAccessTenant() || isSharedWith()`); this service only stores the pairs. {@see MonitorShares} is the
 * implementation on the bundle's own entity manager.
 *
 * User identifiers are the host's own ids, 1 to 128 characters without control characters, compared exactly.
 */
interface MonitorSharesInterface
{
    /**
     * Shares the monitor with the user. Idempotent: when the pair already exists (a repeated submit, or the loser of two
     * concurrent submits whose insert the unique key refused) the existing share is returned with `created` false; a
     * unique violation never escapes.
     *
     * @throws MonitorShareRefusedException When the monitor is not in that tenant, the policy refuses, or the identifier is invalid
     * @throws TransientStorageFailure On a lock conflict (deadlock, lock wait timeout): nothing of this call is
     *                                 committed; 409 + Retry-After + CONFLICT_001 (CONFLICT_002 when earlier work of the
     *                                 request was committed) through the host's or the bundle's conflict answer
     */
    public function create(string $tenantSlug, int $monitorId, string $userIdentifier): MonitorShareView;

    /**
     * Removes the share; false when there was none (idempotent). An invalid identifier removes nothing.
     *
     * @throws TransientStorageFailure On a lock conflict
     */
    public function delete(string $tenantSlug, int $monitorId, string $userIdentifier): bool;

    /**
     * The user identifiers the monitor of that tenant is shared with, sorted; empty when the monitor is not in the tenant.
     *
     * @return list<string>
     */
    public function usersOf(string $tenantSlug, int $monitorId): array;

    /**
     * The shares made to the user, every tenant included (the caller filters what it still allows), oldest first.
     *
     * @return list<MonitorShareView>
     */
    public function sharedWith(string $userIdentifier): array;

    /**
     * Whether the monitor of that tenant is shared with the user.
     */
    public function isSharedWith(string $tenantSlug, int $monitorId, string $userIdentifier): bool;

    /**
     * Removes every share made to the user (erasure of a host account); returns how many were removed.
     *
     * @throws TransientStorageFailure On a lock conflict
     */
    public function deleteAllForUser(string $userIdentifier): int;
}
