<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\UptimeMonitorBundle\Form\Model\SettingsHistoryData;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractUptimeFormType<SettingsHistoryData>
 */
final class SettingsHistoryFormType extends AbstractUptimeFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $full = ['row_attr' => ['class' => 'uptime-settings-field--full']];

        $builder
            ->add('useGlobalDefault', CheckboxType::class, [
                'label'    => 'form.settings.history_use_global',
                'required' => false,
            ] + $full)
            ->add('detailDays', IntegerType::class, [
                'label'                       => 'form.settings.history_days',
                'attr'                        => ['min' => 0],
                'help'                        => 'form.settings.history_days_help',
                'help_translation_parameters' => [
                    '%days%' => (string) $options['global_detail_days'],
                ],
            ] + $full);
    }

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
