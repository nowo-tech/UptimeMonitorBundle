<?php

declare(strict_types=1);

/*
 * 1.29 (Beacon spec 239): a second process of the real-server tests (tests/Database). Usage:
 *
 *   php worker.php check <url> <monitor id> <checks> <start at>      checks one monitor N times
 *   php worker.php scheduler <url> <start at>                        runs one scan of the due monitors
 *   php worker.php ensure <url> <tenants> <start at>                 provisions tenants t-0 … t-(N-1)
 *   php worker.php peer <url> <tenant id> <monitor id> <other id>    holds a monitor row, then (after a line on stdin)
 *                                                                    asks for the tenant row
 *
 * Every mode prints one JSON line. The modes that race wait until <start at> (a Unix time with microseconds), so the
 * processes start together.
 */

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\DueChecksRunner;
use Nowo\UptimeMonitorBundle\Service\TenantProvisioner;
use Nowo\UptimeMonitorBundle\Tests\Database\Support\DatabaseSupport;
use Symfony\Component\Clock\NativeClock;

require __DIR__ . '/../../../vendor/autoload.php';

$mode = $argv[1] ?? '';
$url  = $argv[2] ?? '';

$waitUntil = static function (string $at): void {
    while (microtime(true) < (float) $at) {
        usleep(1000);
    }
};

$errors = [];

switch ($mode) {
    case 'check':
        $em       = DatabaseSupport::privateManager($url);
        /** @var int $i the closure below reads it by reference while the loop advances it */
        $i        = 0;
        $executor = DatabaseSupport::executor($em, static function () use (&$i): CheckStatus {
            // Down, down, up, up, …: the status changes every two checks of this process.
            return intdiv($i, 2) % 2 === 0 ? CheckStatus::Down : CheckStatus::Up;
        });
        $waitUntil($argv[5]);
        for (; $i < (int) $argv[4]; ++$i) {
            try {
                $monitor = $em->find(Monitor::class, (int) $argv[3]);
                $executor->execute($monitor ?? throw new RuntimeException('no monitor'));
            } catch (Throwable $e) {
                $errors[] = $e::class;
            }
            $em->clear();
        }
        echo json_encode(['errors' => $errors]), "\n";
        break;

    case 'scheduler':
        $em       = DatabaseSupport::privateManager($url);
        $executor = DatabaseSupport::executor($em, static fn (): CheckStatus => CheckStatus::Up);
        $runner   = new DueChecksRunner(new MonitorRepository(DatabaseSupport::registry($em)), $executor, true, new NativeClock(), 0);
        $waitUntil($argv[3]);
        echo json_encode(['checked' => $runner->runDueChecks()]), "\n";
        break;

    case 'ensure':
        $em          = DatabaseSupport::privateManager($url);
        $provisioner = new TenantProvisioner(new TenantRepository(DatabaseSupport::registry($em)), $em);
        $waitUntil($argv[4]);
        for ($i = 0; $i < (int) $argv[3]; ++$i) {
            try {
                $provisioner->ensure('t-' . $i, 'Tenant ' . $i);
            } catch (Throwable $e) {
                $errors[] = $e::class;
            }
        }
        echo json_encode(['errors' => $errors]), "\n";
        break;

    case 'peer':
        $connection = DatabaseSupport::connection($url);
        $postgres   = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if ($postgres) {
            // The other session detects the deadlock first (after its own 1 s): it is the victim.
            $connection->executeStatement("SET deadlock_timeout = '20s'");
        }
        $connection->beginTransaction();
        if (!$postgres) {
            // Heavier than the other session: MySQL rolls back the transaction that changed fewer rows. Rows of another
            // monitor only (on PostgreSQL repeated updates of one monitor row re-check its foreign key with FOR KEY SHARE
            // on the tenant row, and the other session's tenant lock would queue behind this one).
            for ($i = 0; $i < 100; ++$i) {
                $connection->insert('uptime_check_result', ['monitor_id' => (int) $argv[5], 'status' => 'up', 'latency_ms' => 1, 'checked_at' => date('Y-m-d H:i:s')]);
            }
        }
        $connection->executeStatement("UPDATE uptime_monitor SET name = 'peer' WHERE id = ?", [(int) $argv[4]]);
        echo json_encode(['ready' => true]), "\n";
        // The other session holds the tenant row now: queue on it.
        fgets(\STDIN);
        $result = 'locked';
        try {
            $connection->fetchOne('SELECT id FROM uptime_tenant WHERE id = ? FOR UPDATE', [(int) $argv[3]]);
        } catch (Throwable $e) {
            $result = $e::class;
        }
        try {
            $connection->rollBack();
        } catch (Throwable) {
        }
        echo json_encode(['peer' => $result]), "\n";
        break;

    default:
        throw new InvalidArgumentException('Unknown mode.');
}
