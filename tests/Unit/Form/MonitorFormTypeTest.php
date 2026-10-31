<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Form;

use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Form\MonitorFormType;
use Nowo\UptimeMonitorBundle\Tests\Support\FormKitTestSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

#[CoversClass(MonitorFormType::class)]
#[CoversClass(MonitorFormData::class)]
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

    /**
     * @return array<FormExtensionInterface>
     */
    protected function getExtensions(): array
    {
        return [...parent::getExtensions(), new ValidatorExtension(Validation::createValidator())];
    }

    public function testANameIsRequired(): void
    {
        $form = $this->factory->create(MonitorFormType::class, new MonitorFormData());
        $form->submit(['name' => '   ', 'type' => MonitorType::Https->value, 'url' => 'https://example.test'], false);

        self::assertFalse($form->get('name')->isValid());
        self::assertSame('form.monitor.name_required', $form->get('name')->getErrors()->current()->getMessage());
    }

    public function testTheIntervalHasTheTenantMinimumExceptForGroups(): void
    {
        $form = $this->factory->create(MonitorFormType::class, new MonitorFormData(), ['min_interval_seconds' => 120]);
        $form->submit(['name' => 'API', 'type' => MonitorType::Https->value, 'url' => 'https://example.test', 'intervalSeconds' => '60'], false);

        self::assertSame(120, $form->get('intervalSeconds')->getConfig()->getOption('attr')['min']);
        self::assertFalse($form->get('intervalSeconds')->isValid());
        self::assertSame('form.monitor.interval_min', $form->get('intervalSeconds')->getErrors()->current()->getMessageTemplate());

        $group = $this->factory->create(MonitorFormType::class, new MonitorFormData(), ['min_interval_seconds' => 120]);
        $group->submit(['name' => 'Team', 'type' => MonitorType::Group->value, 'intervalSeconds' => '60'], false);
        self::assertTrue($group->get('intervalSeconds')->isValid());

        $enough = $this->factory->create(MonitorFormType::class, new MonitorFormData(), ['min_interval_seconds' => 120]);
        $enough->submit(['name' => 'API', 'type' => MonitorType::Https->value, 'url' => 'https://example.test', 'intervalSeconds' => '180'], false);
        self::assertTrue($enough->get('intervalSeconds')->isValid());
    }

    public function testHiddenFieldsAreLeftOutAndKeepTheirValues(): void
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtensions($this->getExtensions())
            ->addTypes([FormKitTestSupport::withMerger(new MonitorFormType(['project', 'resendNotificationAfterDown']))])
            ->getFormFactory();
        $data          = new MonitorFormData();
        $data->project = 'legacy';
        $form          = $factory->create(MonitorFormType::class, $data);

        self::assertFalse($form->has('project'));
        self::assertFalse($form->has('resendNotificationAfterDown'));
        $form->submit(['name' => 'API', 'type' => MonitorType::Https->value, 'url' => 'https://example.test'], false);
        self::assertSame('legacy', $data->project);
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

    public function testTheUrlIsSubmittedAsTypedWithoutADefaultProtocol(): void
    {
        $data = new MonitorFormData();
        $form = $this->factory->create(MonitorFormType::class, $data);
        $form->submit(['name' => 'API', 'type' => MonitorType::Https->value, 'url' => 'status.example.test'], false);

        self::assertNull($form->get('url')->getConfig()->getOption('default_protocol'));
        self::assertSame('status.example.test', $data->url);
    }

    public function testHttpMonitorsNeedAFullHttpOrHttpsUrl(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $data      = new MonitorFormData();
        foreach (['status.example.test', 'ftp://files.example.test', 'https://', 'https://exa mple.test'] as $url) {
            $data->url  = $url;
            $violations = $validator->validate($data);

            self::assertCount(1, $violations, $url);
            self::assertSame('form.monitor.url_invalid', $violations->get(0)->getMessageTemplate());
            self::assertSame('url', $violations->get(0)->getPropertyPath());
        }

        $data->type = MonitorType::Http;
        $data->url  = 'http://intranet:8080/health';
        self::assertCount(0, $validator->validate($data), 'no TLD required');

        $data->type = MonitorType::Tcp;
        $data->url  = 'leftover';
        self::assertCount(0, $validator->validate($data), 'other types ignore the URL field');
    }

    public function testTheTypeOptionsAreTheTypeNames(): void
    {
        $view = $this->factory->create(MonitorFormType::class, new MonitorFormData())->createView();

        self::assertSame(
            ['group', 'http', 'https', 'tcp', 'dns', 'ssl', 'ping', 'push'],
            array_map(static fn ($choice): string => $choice->value, array_values($view['type']->vars['choices'])),
        );
    }

    public function testTimeoutIsCappedByTheGlobalMaximum(): void
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtensions($this->getExtensions())
            ->addTypes([FormKitTestSupport::withMerger(new MonitorFormType([], 30.0))])
            ->getFormFactory();

        $form = $factory->create(MonitorFormType::class, new MonitorFormData());
        $form->submit(['name' => 'API', 'type' => MonitorType::Https->value, 'url' => 'https://example.test', 'requestTimeoutSeconds' => '86400'], false);

        self::assertFalse($form->get('requestTimeoutSeconds')->isValid());
        self::assertSame(30.0, $form->get('requestTimeoutSeconds')->getConfig()->getOption('attr')['max']);

        // Whole seconds: the field renders "48", never "48.0" / "48,0".
        $view = $factory->create(MonitorFormType::class, new MonitorFormData())->createView();
        self::assertSame('48', $view['requestTimeoutSeconds']->vars['value']);
        self::assertContains('integer', $view['requestTimeoutSeconds']->vars['block_prefixes']);
    }

    public function testModelConstraintsRejectOutOfRangeValues(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $data      = new MonitorFormData();
        self::assertCount(0, $validator->validate($data));

        $data->retries      = -1;
        $data->method       = 'DELETE';
        $data->maxRedirects = 500;
        $data->port         = 70000;
        self::assertCount(4, $validator->validate($data));
    }
}
