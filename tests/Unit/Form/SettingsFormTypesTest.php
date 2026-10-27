<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Form;

use Nowo\UptimeMonitorBundle\Form\AbstractUptimeFormType;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsAppearanceData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsGeneralData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsHistoryData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsReverseProxyData;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsStatusPageData;
use Nowo\UptimeMonitorBundle\Form\SettingsAppearanceFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsGeneralFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsHistoryFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsReverseProxyFormType;
use Nowo\UptimeMonitorBundle\Form\SettingsStatusPageFormType;
use Nowo\UptimeMonitorBundle\Tests\Support\FormKitTestSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Test\TypeTestCase;

#[CoversClass(SettingsAppearanceFormType::class)]
#[CoversClass(SettingsGeneralFormType::class)]
#[CoversClass(SettingsHistoryFormType::class)]
#[CoversClass(SettingsReverseProxyFormType::class)]
#[CoversClass(SettingsStatusPageFormType::class)]
#[CoversClass(AbstractUptimeFormType::class)]
final class SettingsFormTypesTest extends TypeTestCase
{
    /**
     * @return list<object>
     */
    protected function getTypes(): array
    {
        return [
            FormKitTestSupport::withMerger(new SettingsAppearanceFormType()),
            FormKitTestSupport::withMerger(new SettingsGeneralFormType()),
            FormKitTestSupport::withMerger(new SettingsHistoryFormType()),
            FormKitTestSupport::withMerger(new SettingsReverseProxyFormType()),
            FormKitTestSupport::withMerger(new SettingsStatusPageFormType()),
        ];
    }

    public function testAppearanceSubmitMapsChoices(): void
    {
        $data = new SettingsAppearanceData();
        $form = $this->factory->create(SettingsAppearanceFormType::class, $data);
        $form->submit([
            'theme'             => 'dark',
            'heartbeatBarTheme' => 'bottom',
            'elapsedTime'       => 'none',
            'uiFramework'       => 'tailwind',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame('dark', $data->theme);
        self::assertSame('bottom', $data->heartbeatBarTheme);
        self::assertSame('none', $data->elapsedTime);
        self::assertSame('tailwind', $data->uiFramework);
        self::assertSame('NowoUptimeMonitorBundle', $form->getConfig()->getOption('translation_domain'));
    }

    public function testGeneralSubmitKeepsEmptyOptionalTextAsEmptyString(): void
    {
        $data                 = new SettingsGeneralData();
        $data->primaryBaseUrl = 'https://status.example.test';

        $form = $this->factory->create(SettingsGeneralFormType::class, $data);
        $form->submit([
            'displayTimezone'   => 'Europe/Madrid',
            'serverTimezone'    => 'UTC',
            'searchEngineIndex' => '1',
            'entryPage'         => 'status',
            'primaryBaseUrl'    => '',
            'httpDnsCache'      => '1',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame('Europe/Madrid', $data->displayTimezone);
        self::assertTrue($data->searchEngineIndex);
        self::assertSame('status', $data->entryPage);
        self::assertSame('', $data->primaryBaseUrl);
        self::assertFalse($data->nscdEnabled);
        self::assertTrue($data->httpDnsCache);
        self::assertFalse($form->has('statusPageVisibility'));
        self::assertFalse($form->has('steamApiKey'));
        self::assertFalse($form->has('chromiumExecutable'));
    }

    public function testStatusPageChoosesTheVisibilityAndTheMonitors(): void
    {
        $data = new SettingsStatusPageData();
        $form = $this->factory->create(SettingsStatusPageFormType::class, $data, ['monitor_choices' => ['API' => 3, 'Shop' => 7]]);
        $form->submit(['visibility' => 'public', 'monitorIds' => ['7']]);

        self::assertTrue($form->isValid());
        self::assertSame('public', $data->visibility);
        self::assertSame([7], $data->monitorIds);
    }

    public function testHistoryUsesGlobalDetailDaysInHelp(): void
    {
        $data = new SettingsHistoryData();
        $form = $this->factory->create(SettingsHistoryFormType::class, $data, ['global_detail_days' => 45]);

        self::assertSame(
            ['%days%' => '45'],
            $form->get('detailDays')->getConfig()->getOption('help_translation_parameters'),
        );

        $form->submit(['useGlobalDefault' => '1', 'detailDays' => '7']);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($data->useGlobalDefault);
        self::assertSame(7, $data->detailDays);
    }

    public function testReverseProxySubmitMapsCheckbox(): void
    {
        $data = new SettingsReverseProxyData();
        $form = $this->factory->create(SettingsReverseProxyFormType::class, $data);
        $form->submit(['trustedProxy' => '1']);

        self::assertTrue($data->trustedProxy);
    }
}
