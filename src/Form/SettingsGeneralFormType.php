<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsGeneralData;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractUptimeFormType<SettingsGeneralData>
 */
#[FormKitConfig('uptime_monitor')]
final class SettingsGeneralFormType extends AbstractUptimeFormType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $half = ['row_attr' => ['class' => 'uptime-settings-field--half']];
        $full = ['row_attr' => ['class' => 'uptime-settings-field--full']];

        $this->addText($builder, 'displayTimezone', [
            'label' => 'form.settings.display_timezone',
            'help'  => 'form.settings.display_timezone_help',
        ] + $half);
        $this->addText($builder, 'serverTimezone', [
            'label' => 'form.settings.server_timezone',
            'help'  => 'form.settings.server_timezone_help',
        ] + $half);
        $this->addCheckbox($builder, 'searchEngineIndex', [
            'label'    => 'form.settings.search_index',
            'required' => false,
        ] + $full);
        $this->addChoice($builder, 'entryPage', [
            'label'   => 'form.settings.entry_page',
            'choices' => [
                'form.settings.entry_page.dashboard' => 'dashboard',
                'form.settings.entry_page.status'    => 'status',
            ],
        ] + $full);
        $this->addUrl($builder, 'primaryBaseUrl', [
            'label'      => 'form.settings.base_url',
            'required'   => false,
            'empty_data' => '',
            'help'       => 'form.settings.base_url_help',
        ] + $full);
        $this->addText($builder, 'steamApiKey', [
            'label'      => 'form.settings.steam_api',
            'required'   => false,
            'empty_data' => '',
        ] + $full);
        $this->addCheckbox($builder, 'nscdEnabled', [
            'label'    => 'form.settings.nscd',
            'required' => false,
        ] + $half);
        $this->addCheckbox($builder, 'httpDnsCache', [
            'label'    => 'form.settings.dns_cache',
            'required' => false,
        ] + $half);
        $this->addText($builder, 'chromiumExecutable', [
            'label' => 'form.settings.chromium',
            'help'  => 'form.settings.chromium_help',
        ] + $full);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults(['data_class' => SettingsGeneralData::class]);
    }
}
