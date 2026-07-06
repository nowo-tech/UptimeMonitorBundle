<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Service\MonitorFactory
 */
final class MonitorFactoryTest extends TestCase
{
    use SyncDispatcherTestTrait;

    public function testCreateFromFormDataBuildsConfig(): void
    {
        $factory                   = $this->monitorFactory();
        $tenant                    = new Tenant('main', 'Main');
        $data                      = new MonitorFormData();
        $data->name                = 'API';
        $data->type                = MonitorType::Https;
        $data->url                 = 'https://example.test/health';
        $data->expectedStatusCodes = '200,204';
        $data->keyword             = 'ok';
        $data->intervalSeconds     = 45;

        $monitor = $factory->createFromFormData($tenant, $data);

        self::assertSame('API', $monitor->getName());
        self::assertSame('https://example.test/health', $monitor->getTarget());
        self::assertSame([200, 204], $monitor->getConfig()['expected_status_codes']);
        self::assertSame('ok', $monitor->getConfig()['keyword']);
        self::assertSame(45, $monitor->getIntervalSeconds());
    }

    public function testCreateTcpMonitor(): void
    {
        $factory    = $this->monitorFactory();
        $tenant     = new Tenant('main', 'Main');
        $data       = new MonitorFormData();
        $data->name = 'DB port';
        $data->type = MonitorType::Tcp;
        $data->host = 'db.internal';
        $data->port = 5432;

        $monitor = $factory->createFromFormData($tenant, $data);

        self::assertSame(MonitorType::Tcp, $monitor->getType());
        self::assertSame('db.internal:5432', $monitor->getTarget());
        self::assertSame(5432, $monitor->getConfig()['port']);
    }

    public function testCreatePingMonitor(): void
    {
        $factory    = $this->monitorFactory();
        $tenant     = new Tenant('main', 'Main');
        $data       = new MonitorFormData();
        $data->name = 'Gateway';
        $data->type = MonitorType::Ping;
        $data->host = '8.8.8.8';

        $monitor = $factory->createFromFormData($tenant, $data);

        self::assertSame(MonitorType::Ping, $monitor->getType());
        self::assertSame('8.8.8.8', $monitor->getTarget());
        self::assertSame('8.8.8.8', $monitor->getConfig()['host']);
    }

    public function testCreateDnsAndSslMonitors(): void
    {
        $factory = $this->monitorFactory();
        $tenant  = new Tenant('main', 'Main');

        $dns                   = new MonitorFormData();
        $dns->name             = 'DNS';
        $dns->type             = MonitorType::Dns;
        $dns->hostname         = 'example.com';
        $dns->recordType       = 'TXT';
        $dns->expectedDnsValue = 'v=spf1';

        $dnsMonitor = $factory->createFromFormData($tenant, $dns);
        self::assertSame('example.com', $dnsMonitor->getTarget());
        self::assertSame('TXT', $dnsMonitor->getConfig()['record_type']);

        $ssl                   = new MonitorFormData();
        $ssl->name             = 'Cert';
        $ssl->type             = MonitorType::Ssl;
        $ssl->host             = 'example.com';
        $ssl->port             = 443;
        $ssl->daysBeforeExpiry = 7;

        $sslMonitor = $factory->createFromFormData($tenant, $ssl);
        self::assertSame('example.com:443', $sslMonitor->getTarget());
        self::assertSame(7, $sslMonitor->getConfig()['days_before_expiry']);
    }

    public function testToFormDataMapsAllMonitorTypes(): void
    {
        $factory = $this->monitorFactory();
        $tenant  = new Tenant('main', 'Main');

        $http = new Monitor($tenant, 'HTTP', MonitorType::Http, 'http://x.test');
        $http->setConfig(['url' => 'http://x.test', 'method' => 'HEAD', 'expected_status_codes' => [201], 'keyword' => 'ok']);
        $httpData = $factory->toFormData($http);
        self::assertSame('HEAD', $httpData->method);
        self::assertSame('201', $httpData->expectedStatusCodes);

        $dns = new Monitor($tenant, 'DNS', MonitorType::Dns, 'host.test');
        $dns->setConfig(['hostname' => 'host.test', 'record_type' => 'MX', 'expected_value' => 'mx.test']);
        $dnsData = $factory->toFormData($dns);
        self::assertSame('MX', $dnsData->recordType);
        self::assertSame('mx.test', $dnsData->expectedDnsValue);

        $ssl = new Monitor($tenant, 'SSL', MonitorType::Ssl, 'host:443');
        $ssl->setConfig(['host' => 'host', 'port' => 8443, 'days_before_expiry' => 3]);
        $sslData = $factory->toFormData($ssl);
        self::assertSame(8443, $sslData->port);
        self::assertSame(3, $sslData->daysBeforeExpiry);
    }

    public function testCreateHttpMonitorUsesDefaultStatusCodeWhenEmpty(): void
    {
        $factory                   = $this->monitorFactory();
        $tenant                    = new Tenant('main', 'Main');
        $data                      = new MonitorFormData();
        $data->name                = 'HTTP';
        $data->type                = MonitorType::Http;
        $data->url                 = 'http://x.test';
        $data->expectedStatusCodes = ' , ';

        $monitor = $factory->createFromFormData($tenant, $data);

        self::assertSame([200], $monitor->getConfig()['expected_status_codes']);
    }

    public function testApplyFormDataEnforcesMinimumInterval(): void
    {
        $factory               = $this->monitorFactory();
        $monitor               = new Monitor(new Tenant('main', 'Main'), 'API', MonitorType::Http, 'http://x.test');
        $data                  = new MonitorFormData();
        $data->name            = 'API';
        $data->type            = MonitorType::Http;
        $data->url             = 'http://x.test';
        $data->intervalSeconds = 10;

        $factory->applyFormData($monitor, $data);

        self::assertSame(30, $monitor->getIntervalSeconds());
    }
}
