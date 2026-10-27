<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsHistoryData;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractUptimeFormType<SettingsHistoryData>
 */
#[FormKitConfig('uptime_monitor')]
final class SettingsHistoryFormType extends AbstractUptimeFormType
{
    /**
     * Adds the Monitor history settings fields.
     *
     * @param array<string, mixed> $options Form options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $full = ['row_attr' => ['class' => 'uptime-settings-field--full']];

        $this->addCheckbox($builder, 'useGlobalDefault', [
            'label'    => 'form.settings.history_use_global',
            'required' => false,
        ] + $full);
        $this->addInteger($builder, 'detailDays', [
            'label'                       => 'form.settings.history_days',
            'attr'                        => ['min' => 0],
            'help'                        => 'form.settings.history_days_help',
            'help_translation_parameters' => [
                '%days%' => (string) $options['global_detail_days'],
            ],
        ] + $full);
    }

    /**
     * Configures the Monitor history settings options.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'data_class'         => SettingsHistoryData::class,
            'global_detail_days' => 30,
        ]);
        $resolver->setAllowedTypes('global_detail_days', 'int');
    }
}
