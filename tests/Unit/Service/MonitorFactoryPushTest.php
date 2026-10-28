<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Push\PushThresholds;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\MonitorFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonitorFactory::class)]
final class MonitorFactoryPushTest extends TestCase
{
    private function factory(): MonitorFactory
    {
        return new MonitorFactory($this->createMock(MonitorRepository::class));
    }

    private function pushData(): MonitorFormData
    {
        $data                  = new MonitorFormData();
        $data->name            = 'web-01';
        $data->type            = MonitorType::Push;
        $data->intervalSeconds = 120;
        $data->description     = 'Main web server';

        return $data;
    }

    public function testAPushMonitorGetsAKeyItsThresholdsAndOneIntervalBetweenRetries(): void
    {
        $data                                 = $this->pushData();
        $data->pushSigned                     = true;
        $data->pushGraceSeconds               = 30;
        $data->pushThresholds['diskCritical'] = 90.0;
        $data->pushThresholds['swapWarn']     = null;
        $monitor                              = $this->factory()->createFromFormData(new Tenant('main', 'Main'), $data);

        self::assertSame('push', $monitor->getTarget());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $monitor->getPushKey());
        self::assertSame(120, $monitor->getRetryIntervalSeconds());
        $config = $monitor->getConfig();
        self::assertTrue($config['push_signed']);
        self::assertSame(30, $config['push_grace_seconds']);
        self::assertSame('Main web server', $config['description']);
        self::assertSame([85.0, 90.0], $config['push_thresholds']['disk']);
        self::assertSame([null, 80.0], $config['push_thresholds']['swap']);
    }

    public function testTheFormDataRoundTrips(): void
    {
        $factory                            = $this->factory();
        $data                               = $this->pushData();
        $data->pushThresholds['cpuWarn']    = 70.0;
        $data->pushThresholds['memoryWarn'] = null;
        $monitor                            = $factory->createFromFormData(new Tenant('main', 'Main'), $data);
        $back                               = $factory->toFormData($monitor);

        self::assertSame(MonitorType::Push, $back->type);
        self::assertFalse($back->pushSigned);
        self::assertNull($back->pushGraceSeconds);
        self::assertSame(70.0, $back->pushThresholds['cpuWarn']);
        self::assertNull($back->pushThresholds['memoryWarn']);
        self::assertSame(PushThresholds::DEFAULTS['load'][1], $back->pushThresholds['loadCritical']);
    }

    public function testLeavingThePushTypeDropsTheKeyAndToken(): void
    {
        $factory = $this->factory();
        $monitor = $factory->createFromFormData(new Tenant('main', 'Main'), $this->pushData());
        $monitor->setPushTokenHash('hash');

        $data       = $factory->toFormData($monitor);
        $data->type = MonitorType::Https;
        $data->url  = 'https://example.com';
        $factory->applyFormData($monitor, $data);

        self::assertNull($monitor->getPushKey());
        self::assertNull($monitor->getPushTokenHash());
        self::assertSame('https://example.com', $monitor->getTarget());
    }
}
