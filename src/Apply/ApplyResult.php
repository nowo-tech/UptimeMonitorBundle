<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Apply;

use Nowo\UptimeMonitorBundle\Event\MonitorsAppliedEvent;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function in_array;

/**
 * What `nowo:uptime:apply-monitors` did (or, on a dry run, would do) (since 1.18). Entries carry tenant slugs, entry
 * keys, monitor ids, changed field names and token states only: there is no field that could hold a token or a hash.
 */
final readonly class ApplyResult
{
    /**
     * @param list<array{tenant: string, tenant_enabled: bool}> $tenants
     * @param list<array{tenant: string, key: string, id: int|null, token: string}> $created
     * @param list<array{tenant: string, key: string, id: int|null, changed: list<string>, token: string}> $updated
     * @param list<array{tenant: string, key: string, id: int|null, token: string}> $unchanged
     * @param list<array{tenant: string, key: string, id: int|null}> $pruned
     * @param list<array{tenant: string, key: string, id: int|null}> $pruneCandidates file monitors missing from the file, kept (no `--prune`)
     * @param list<array{tenant: string, code: string, error: string}> $warnings problems after the commit (the changes
     *                                                                           stay applied): `event_listener_failed`
     *                                                                           when a {@see MonitorsAppliedEvent}
     *                                                                           listener threw (`error` is its class,
     *                                                                           logged, not printed)
     */
    public function __construct(
        public bool $dryRun,
        public array $tenants,
        public array $created,
        public array $updated,
        public array $unchanged,
        public array $pruned,
        public array $pruneCandidates,
        public array $warnings = [],
    ) {
    }

    /**
     * A copy with one more post-commit warning.
     */
    public function withWarning(string $tenant, string $code, string $error): self
    {
        return new self($this->dryRun, $this->tenants, $this->created, $this->updated, $this->unchanged, $this->pruned, $this->pruneCandidates, [
            ...$this->warnings,
            ['tenant' => $tenant, 'code' => $code, 'error' => $error],
        ]);
    }

    /**
     * Monitors whose push token was set or rotated.
     */
    public function tokensChanged(): int
    {
        return count(array_filter([...$this->created, ...$this->updated], static fn (array $entry): bool => in_array($entry['token'], ['set', 'rotated'], true)));
    }

    /**
     * The entries of one tenant, without the tenant field (for {@see MonitorsAppliedEvent}).
     *
     * @return array{created: list<array<string, mixed>>, updated: list<array<string, mixed>>, unchanged: list<array<string, mixed>>, pruned: list<array<string, mixed>>}
     */
    public function forTenant(string $tenant): array
    {
        $pick = static fn (array $entries): array => array_values(array_map(
            static function (array $entry): array {
                unset($entry['tenant']);

                return $entry;
            },
            array_filter($entries, static fn (array $entry): bool => $entry['tenant'] === $tenant),
        ));

        /* @var array{created: list<array{key: string, id: int|null, token: string}>, updated: list<array{key: string, id: int|null, changed: list<string>, token: string}>, unchanged: list<array{key: string, id: int|null, token: string}>, pruned: list<array{key: string, id: int|null}>} */
        return [
            'created'   => $pick($this->created),
            'updated'   => $pick($this->updated),
            'unchanged' => $pick($this->unchanged),
            'pruned'    => $pick($this->pruned),
        ];
    }

    /**
     * The command output (stdout JSON) of a successful run.
     *
     * @return array<string, mixed>
     */
    public function toArray(string $fileSha256): array
    {
        return [
            'ok'               => true,
            'dry_run'          => $this->dryRun,
            'file_sha256'      => $fileSha256,
            'tenants'          => $this->tenants,
            'created'          => $this->created,
            'updated'          => $this->updated,
            'unchanged'        => $this->unchanged,
            'pruned'           => $this->pruned,
            'prune_candidates' => $this->pruneCandidates,
            'warnings'         => array_map(static fn (array $warning): array => ['tenant' => $warning['tenant'], 'code' => $warning['code']], $this->warnings),
            'errors'           => [],
        ] + ($this->dryRun ? ['quota_checked_unlocked' => true] : []);
    }
}
