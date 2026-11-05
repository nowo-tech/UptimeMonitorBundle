<?php

declare(strict_types=1);

/*
 * Stands in for a host's bin/console in the apply-monitors process tests (contract §11, argv): SQLite in memory with
 * one tenant, and the real command reading the real argv and stdin. With UPTIME_CMDLINE_OUT set, it copies
 * /proc/self/cmdline to that file while the command runs (from the MonitorsAppliedEvent listener).
 */

use Nowo\UptimeMonitorBundle\Apply\MonitorApplier;
use Nowo\UptimeMonitorBundle\Apply\MonitorFileParser;
use Nowo\UptimeMonitorBundle\Apply\PushTokenFileReader;
use Nowo\UptimeMonitorBundle\Command\ApplyMonitorsCommand;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Event\MonitorsAppliedEvent;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Tests\Support\SingleManagerRegistry;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use Nowo\UptimeMonitorBundle\Tests\Unit\Apply\MonitorFileParserTest;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$em = SqliteEntityManagerFactory::create();
$em->persist(new Tenant(MonitorFileParserTest::TENANT, 'Infra'));
$em->flush();
$registry   = new SingleManagerRegistry($em);
$dispatcher = new EventDispatcher();
$cmdlineOut = getenv('UPTIME_CMDLINE_OUT');
if (is_string($cmdlineOut) && $cmdlineOut !== '') {
    $dispatcher->addListener(MonitorsAppliedEvent::class, static function () use ($cmdlineOut): void {
        file_put_contents($cmdlineOut, (string) @file_get_contents('/proc/self/cmdline'));
    });
}

$application = new Application('apply-monitors fixture');
$application->setAutoExit(false);
$application->addCommand(new ApplyMonitorsCommand(
    new MonitorFileParser(),
    new PushTokenFileReader(),
    new MonitorApplier($em, new MonitorRepository($registry), new TenantRepository($registry), null, $dispatcher),
));
$application->setDefaultCommand('nowo:uptime:apply-monitors', true);

// A CLI fixture, never a FrankenPHP worker: the exit code is what the process tests read.
exit($application->run(new ArgvInput(), new ConsoleOutput())); // @phpstan-ignore frankenphp.classic.noExitOrDie
