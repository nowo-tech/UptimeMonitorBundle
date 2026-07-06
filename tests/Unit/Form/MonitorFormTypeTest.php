<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Form;

use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Form\MonitorFormType;
use Symfony\Component\Form\Test\TypeTestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Form\MonitorFormType
 */
final class MonitorFormTypeTest extends TypeTestCase
{
    public function testSubmitValidData(): void
    {
        $data                  = new MonitorFormData();
        $data->name            = 'API';
        $data->type            = MonitorType::Https;
        $data->url             = 'https://example.test';
        $data->intervalSeconds = 60;

        $form = $this->factory->create(MonitorFormType::class, $data);
        $form->submit([
            'name'                => 'API',
            'type'                => MonitorType::Https->value,
            'url'                 => 'https://example.test',
            'method'              => 'GET',
            'expectedStatusCodes' => '200',
            'keyword'             => 'ok',
            'host'                => 'localhost',
            'port'                => 443,
            'hostname'            => 'example.com',
            'recordType'          => 'A',
            'expectedDnsValue'    => 'value',
            'daysBeforeExpiry'    => 14,
            'intervalSeconds'     => 90,
            'paused'              => true,
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame(90, $data->intervalSeconds);
        self::assertTrue($data->paused);
    }
}
