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
        self::assertSame(12, $back->requestTimeoutSeconds);
        self::assertSame(2, $back->resendNotificationAfterDown);
        self::assertSame('Main API', $back->description);
        self::assertSame(3, $back->maxRedirects);
        self::assertTrue($back->ignoreTls);
        self::assertTrue($back->upsideDown);
        self::assertTrue($back->checkCertExpiry);
        self::assertSame('ok', $back->keyword);
        self::assertSame('{"ping":1}', $back->httpBody);
        self::assertSame('xml', $back->bodyEncoding);
        self::assertSame("X-One: ***\nX-Two: ***", $back->httpHeaders, 'write-only (1.25.1): header values are masked');
        self::assertSame('http://proxy.test:3128', $back->proxy);
        self::assertSame('basic', $back->authMethod);
        self::assertSame('user', $back->authUsername);
        self::assertSame('', $back->authPassword, 'write-only: the stored password is never sent back');
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

    /** Since 1.25: the stored password is write-only; the clear box removes it, a typed one replaces it. */
    public function testThePasswordIsWriteOnlyAndCanBeClearedOrReplaced(): void
    {
        $monitor = new Monitor($this->tenant, 'API', MonitorType::Http, 'https://api.test');
        $monitor->setConfig(['auth_method' => 'basic', 'auth_username' => 'user', 'auth_password' => 'stored']);

        $data = $this->factory()->toFormData($monitor);
        self::assertSame('', $data->authPassword);
        self::assertFalse($data->authPasswordClear);

        $data->authPasswordClear = true;
        $this->factory()->applyFormData($monitor, $data);
        self::assertArrayNotHasKey('auth_password', $monitor->getConfig());

        $data                    = $this->factory()->toFormData($monitor);
        $data->authPassword      = 'fresh';
        $data->authPasswordClear = true;
        $this->factory()->applyFormData($monitor, $data);
        self::assertSame('fresh', $monitor->getConfig()['auth_password']);
    }

    /** Since 1.25.1: URL / proxy userinfo, credential-like query values and header values are write-only. */
    public function testUrlProxyAndHeaderSecretsAreMaskedAndAnUnchangedMaskKeepsThem(): void
    {
        $monitor = new Monitor($this->tenant, 'API', MonitorType::Https, 'https://u:pw@api.test/health?token=t1&page=2');
        $monitor->setConfig([
            'url'     => 'https://u:pw@api.test/health?token=t1&page=2',
            'proxy'   => 'http://pu:pp@proxy.test:3128',
            'headers' => ['Authorization' => 'Bearer abc', 'X-Empty' => ''],
        ]);

        $data = $this->factory()->toFormData($monitor);
        self::assertSame('https://***@api.test/health?token=***&page=2', $data->url);
        self::assertSame('http://***@proxy.test:3128', $data->proxy);
        self::assertSame("Authorization: ***\nX-Empty: ", $data->httpHeaders);

        // Saved unchanged (and with the header name in another case): every stored secret is kept.
        $data->httpHeaders = "authorization: ***\nX-Empty: \nX-New: ***";
        $data->url         = 'https://***@api.test/v2?token=***&page=3';
        $this->factory()->applyFormData($monitor, $data);
        $config = $monitor->getConfig();
        self::assertSame('https://u:pw@api.test/v2?token=t1&page=3', $config['url']);
        self::assertSame('https://u:pw@api.test/v2?token=t1&page=3', $monitor->getTarget());
        self::assertSame('http://pu:pp@proxy.test:3128', $config['proxy']);
        self::assertSame(['authorization' => 'Bearer abc', 'X-Empty' => '', 'X-New' => '***'], $config['headers'], 'a mask with no stored header stays as typed');
    }

    /** Since 1.25.1: a typed secret replaces the stored one; removing it from the form removes it. */
    public function testTypedSecretsReplaceAndRemovedOnesAreDropped(): void
    {
        $monitor = new Monitor($this->tenant, 'API', MonitorType::Https, 'https://api.test');
        $monitor->setConfig([
            'url'     => 'https://u:pw@api.test/?token=t1',
            'proxy'   => 'pu:pp@proxy.test:3128',
            'headers' => ['Authorization' => 'Bearer abc', 'X-Api-Key' => 'k1'],
        ]);

        $data = $this->factory()->toFormData($monitor);
        self::assertSame('***@proxy.test:3128', $data->proxy, 'a proxy without a scheme is masked too');
        $data->url         = 'https://other:new@api.test/?token=t2';
        $data->proxy       = '***@proxy.test:3128';
        $data->httpHeaders = 'Authorization: Bearer fresh';
        $this->factory()->applyFormData($monitor, $data);
        $config = $monitor->getConfig();
        self::assertSame('https://other:new@api.test/?token=t2', $config['url']);
        self::assertSame('pu:pp@proxy.test:3128', $config['proxy']);
        self::assertSame(['Authorization' => 'Bearer fresh'], $config['headers']);

        $data              = $this->factory()->toFormData($monitor);
        $data->url         = 'https://api.test/';
        $data->proxy       = '';
        $data->httpHeaders = '';
        $this->factory()->applyFormData($monitor, $data);
        $config = $monitor->getConfig();
        self::assertSame('https://api.test/', $config['url']);
        self::assertArrayNotHasKey('proxy', $config);
        self::assertArrayNotHasKey('headers', $config);
    }

    /** Since 1.25.1: a monitor saved without `url` in its config keeps the userinfo of its target. */
    public function testALegacyMonitorWithoutAStoredUrlKeepsTheUserinfoOfItsTarget(): void
    {
        $monitor = new Monitor($this->tenant, 'API', MonitorType::Http, 'http://u:pw@legacy.test/');

        $data = $this->factory()->toFormData($monitor);
        self::assertSame('http://***@legacy.test/', $data->url);
        $this->factory()->applyFormData($monitor, $data);
        self::assertSame('http://u:pw@legacy.test/', $monitor->getConfig()['url']);
    }

    /** A new monitor has nothing stored: a typed mask is saved as typed. */
    public function testANewMonitorSavesATypedMaskAsIs(): void
    {
        $data              = new MonitorFormData();
        $data->name        = 'API';
        $data->type        = MonitorType::Https;
        $data->url         = 'https://***@api.test/';
        $data->httpHeaders = 'X-Key: ***';

        $monitor = $this->factory()->createFromFormData($this->tenant, $data);
        self::assertSame('https://***@api.test/', $monitor->getConfig()['url']);
        self::assertSame(['X-Key' => '***'], $monitor->getConfig()['headers']);
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

    public function testFormDataKeepsRangesCompactAndTimeoutsWhole(): void
    {
        $factory                   = $this->factory();
        $data                      = new MonitorFormData();
        $data->name                = 'API';
        $data->url                 = 'https://api.test';
        $data->expectedStatusCodes = '200-299, 301';
        $monitor                   = $factory->createFromFormData($this->tenant, $data);

        self::assertCount(101, $monitor->getConfig()['expected_status_codes']);
        self::assertSame(48, $monitor->getConfig()['request_timeout_seconds']);
        self::assertSame('200-299,301', $factory->toFormData($monitor)->expectedStatusCodes);

        // Decimal timeouts saved by older versions: rounded up, never below one second.
        foreach ([[12.0, 12], [2.5, 3], [0.2, 1], ['30', 30]] as [$stored, $whole]) {
            $monitor->setConfig(['request_timeout_seconds' => $stored, 'timeout' => 99]);
            self::assertSame($whole, $factory->toFormData($monitor)->requestTimeoutSeconds);
        }
        $monitor->setConfig(['request_timeout_seconds' => 'n/a', 'timeout' => 9.5]);
        self::assertSame(10, $factory->toFormData($monitor)->requestTimeoutSeconds);
    }

    public function testFormDataFallsBackWhenConfigIsMissing(): void
    {
        $factory = $this->factory();

        foreach ([MonitorType::Ping, MonitorType::Tcp, MonitorType::Dns, MonitorType::Ssl, MonitorType::Http] as $type) {
            $monitor = new Monitor($this->tenant, 'm', $type, 'target.test');
            $monitor->setConfig(['timeout' => 7, 'expected_status_codes' => 'x', 'headers' => 'x', 'tags' => 'x']);
            $data = $factory->toFormData($monitor);

            self::assertSame(7, $data->requestTimeoutSeconds);
            self::assertSame(0, $data->resendNotificationAfterDown);
        }

        $bare = $factory->toFormData(new Monitor($this->tenant, 'm', MonitorType::Http, 'https://t.test'));
        self::assertSame(48, $bare->requestTimeoutSeconds);
        self::assertSame('https://t.test', $bare->url);
        self::assertSame('200', $bare->expectedStatusCodes);
        self::assertSame('', $bare->httpHeaders);
    }
}
