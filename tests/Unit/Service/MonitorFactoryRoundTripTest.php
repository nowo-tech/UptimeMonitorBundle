<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\MonitorFactory;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonitorFactory::class)]
final class MonitorFactoryRoundTripTest extends TestCase
{
    use EntityIdTrait;

    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->tenant = new Tenant('main', 'Main');
    }

    private function factory(?Monitor $found = null): MonitorFactory
    {
        $repository = $this->createStub(MonitorRepository::class);
        $repository->method('find')->willReturn($found);

        return new MonitorFactory($repository);
    }

    public function testHttpFormRoundTripsEveryOption(): void
    {
        $data                              = new MonitorFormData();
        $data->name                        = 'API';
        $data->type                        = MonitorType::Https;
        $data->url                         = 'https://api.test/health';
        $data->method                      = 'POST';
        $data->expectedStatusCodes         = '200-201';
        $data->requestTimeoutSeconds       = 12;
        $data->maxRedirects                = 3;
        $data->resendNotificationAfterDown = 2;
        $data->description                 = 'Main API';
        $data->ignoreTls                   = true;
        $data->upsideDown                  = true;
        $data->checkCertExpiry             = true;
        $data->keyword                     = 'ok';
        $data->httpBody                    = '{"ping":1}';
        $data->bodyEncoding                = 'xml';
        $data->httpHeaders                 = "X-One: 1\nX-Two: 2";
        $data->proxy                       = 'http://proxy.test:3128';
        $data->authMethod                  = 'basic';
        $data->authUsername                = 'user';
        $data->authPassword                = 'secret';
        $data->tags                        = 'prod, , eu';
        $data->project                     = 'Shop';

        $factory = $this->factory();
        $monitor = $factory->createFromFormData($this->tenant, $data);

        self::assertSame('https://api.test/health', $monitor->getTarget());
        self::assertSame('Shop', $monitor->getProject());
        self::assertSame(['prod', 'eu'], $monitor->getConfig()['tags']);
        self::assertSame(['X-One' => '1', 'X-Two' => '2'], $monitor->getConfig()['headers']);

        $back = $factory->toFormData($monitor);
        self::assertSame('POST', $back->method);
        self::assertSame('200,201', $back->expectedStatusCodes);
        self::assertSame(12.0, $back->requestTimeoutSeconds);
        self::assertSame(2, $back->resendNotificationAfterDown);
        self::assertSame('Main API', $back->description);
        self::assertSame(3, $back->maxRedirects);
        self::assertTrue($back->ignoreTls);
        self::assertTrue($back->upsideDown);
        self::assertTrue($back->checkCertExpiry);
        self::assertSame('ok', $back->keyword);
        self::assertSame('{"ping":1}', $back->httpBody);
        self::assertSame('xml', $back->bodyEncoding);
        self::assertSame("X-One: 1\nX-Two: 2", $back->httpHeaders);
        self::assertSame('http://proxy.test:3128', $back->proxy);
        self::assertSame('basic', $back->authMethod);
        self::assertSame('user', $back->authUsername);
        self::assertSame('secret', $back->authPassword);
        self::assertSame('prod, eu', $back->tags);
    }

    public function testEditingKeepsInternalCountersAndTheStoredPassword(): void
    {
        $monitor = new Monitor($this->tenant, 'API', MonitorType::Http, 'https://api.test');
        $monitor->setConfig(['failure_streak' => 3, 'down_notify_streak' => 1, 'auth_password' => 'stored']);

        $data               = new MonitorFormData();
        $data->name         = 'API';
        $data->type         = MonitorType::Http;
        $data->url          = 'https://api.test';
        $data->authMethod   = 'basic';
        $data->authUsername = 'user';

        $this->factory()->applyFormData($monitor, $data);

        self::assertSame(3, $monitor->getConfig()['failure_streak']);
        self::assertSame(1, $monitor->getConfig()['down_notify_streak']);
        self::assertSame('stored', $monitor->getConfig()['auth_password']);
    }

    public function testParentMustBeAGroupAndGroupsHaveNoParentOrProject(): void
    {
        $group = new Monitor($this->tenant, 'G', MonitorType::Group, 'G');
        $this->setEntityId($group, 7);

        $data           = new MonitorFormData();
        $data->name     = 'child';
        $data->type     = MonitorType::Tcp;
        $data->host     = 'db.test';
        $data->port     = 5432;
        $data->parentId = 7;
        $data->project  = 'ignored with a parent';

        $child = $this->factory($group)->createFromFormData($this->tenant, $data);
        self::assertSame($group, $child->getParent());
        self::assertSame('G', $child->getProject(), 'a child inherits its group name as project');
        self::assertSame('db.test:5432', $child->getTarget());

        $notGroup = new Monitor($this->tenant, 'H', MonitorType::Http, 'https://h.test');
        self::assertNull($this->factory($notGroup)->createFromFormData($this->tenant, $data)->getParent());

        $groupData       = new MonitorFormData();
        $groupData->name = '';
        $groupData->type = MonitorType::Group;
        $groupMonitor    = $this->factory()->createFromFormData($this->tenant, $groupData);
        self::assertSame('group', $groupMonitor->getTarget());
        self::assertNull($groupMonitor->getProject());

        $back = $this->factory()->toFormData($child);
        self::assertSame(7, $back->parentId);
        self::assertSame('db.test', $back->host);
        self::assertSame(5432, $back->port);
        self::assertSame('G', $this->factory()->toFormData($group)->name);
    }

    public function testNonHttpTypesRoundTrip(): void
    {
        $factory = $this->factory();

        $ping       = new MonitorFormData();
        $ping->type = MonitorType::Ping;
        $ping->host = '1.1.1.1';
        self::assertSame('1.1.1.1', $factory->toFormData($factory->createFromFormData($this->tenant, $ping))->host);

        $dns                   = new MonitorFormData();
        $dns->type             = MonitorType::Dns;
        $dns->hostname         = 'example.test';
        $dns->recordType       = 'MX';
        $dns->expectedDnsValue = 'mail';
        $dnsBack               = $factory->toFormData($factory->createFromFormData($this->tenant, $dns));
        self::assertSame(['example.test', 'MX', 'mail'], [$dnsBack->hostname, $dnsBack->recordType, $dnsBack->expectedDnsValue]);

        $ssl                   = new MonitorFormData();
        $ssl->type             = MonitorType::Ssl;
        $ssl->host             = 'secure.test';
        $ssl->port             = 8443;
        $ssl->daysBeforeExpiry = 30;
        $sslBack               = $factory->toFormData($factory->createFromFormData($this->tenant, $ssl));
        self::assertSame(['secure.test', 8443, 30], [$sslBack->host, $sslBack->port, $sslBack->daysBeforeExpiry]);
    }

    public function testFormDataFallsBackWhenConfigIsMissing(): void
    {
        $factory = $this->factory();

        foreach ([MonitorType::Ping, MonitorType::Tcp, MonitorType::Dns, MonitorType::Ssl, MonitorType::Http] as $type) {
            $monitor = new Monitor($this->tenant, 'm', $type, 'target.test');
            $monitor->setConfig(['timeout' => 7, 'expected_status_codes' => 'x', 'headers' => 'x', 'tags' => 'x']);
            $data = $factory->toFormData($monitor);

            self::assertSame(7.0, $data->requestTimeoutSeconds);
            self::assertSame(0, $data->resendNotificationAfterDown);
        }

        $bare = $factory->toFormData(new Monitor($this->tenant, 'm', MonitorType::Http, 'https://t.test'));
        self::assertSame(48.0, $bare->requestTimeoutSeconds);
        self::assertSame('https://t.test', $bare->url);
        self::assertSame('200', $bare->expectedStatusCodes);
        self::assertSame('', $bare->httpHeaders);
    }
}
