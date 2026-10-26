<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Form;

use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Form\MonitorFormType;
use Nowo\UptimeMonitorBundle\Tests\Support\FormKitTestSupport;
use Symfony\Component\Form\Test\TypeTestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Form\MonitorFormType
 */
final class MonitorFormTypeTest extends TypeTestCase
{
    /**
     * @return list<object>
     */
    protected function getTypes(): array
    {
        return [
            FormKitTestSupport::withMerger(new MonitorFormType()),
        ];
    }

    public function testSubmitValidData(): void
    {
        $data                  = new MonitorFormData();
        $data->name            = 'API';
        $data->type            = MonitorType::Https;
        $data->url             = 'https://example.test';
        $data->intervalSeconds = 60;
        $data->project         = '';

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
        ], false);

        self::assertTrue($form->isSynchronized());
        self::assertSame(90, $data->intervalSeconds);
        self::assertTrue($data->paused);
    }

    /** Optional text fields left empty keep '' (a `string` model property refuses the null Symfony would submit). */
    public function testEmptyOptionalTextFieldsSubmitAnEmptyString(): void
    {
        $data               = new MonitorFormData();
        $data->project      = 'legacy';
        $data->description  = 'old';
        $data->url          = 'https://example.test';
        $data->httpBody     = '{}';
        $data->authPassword = 'secret';

        $form = $this->factory->create(MonitorFormType::class, $data);
        $form->submit(['project' => '', 'description' => '', 'url' => '', 'httpBody' => '', 'authPassword' => ''], false);

        self::assertTrue($form->isSynchronized());
        self::assertSame('', $data->project);
        self::assertSame('', $data->description);
        self::assertSame('', $data->url);
        self::assertSame('', $data->httpBody);
        self::assertSame('', $data->authPassword);
    }

    public function testTheTypeOptionsAreTheTypeNames(): void
    {
        $view = $this->factory->create(MonitorFormType::class, new MonitorFormData())->createView();

        self::assertSame(
            ['group', 'http', 'https', 'tcp', 'dns', 'ssl', 'ping'],
            array_map(static fn ($choice): string => $choice->value, array_values($view['type']->vars['choices'])),
        );
    }
}
