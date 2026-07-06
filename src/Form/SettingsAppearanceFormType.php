<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\UptimeMonitorBundle\Form\Model\SettingsAppearanceData;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractUptimeFormType<SettingsAppearanceData>
 */
final class SettingsAppearanceFormType extends AbstractUptimeFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $full = ['row_attr' => ['class' => 'uptime-settings-field--full']];

        $builder
            ->add('theme', ChoiceType::class, [
                'label'   => 'form.settings.theme',
                'choices' => [
                    'form.settings.theme.light' => 'light',
                    'form.settings.theme.dark'  => 'dark',
                    'form.settings.theme.auto'  => 'auto',
                ],
                'expanded' => true,
            ] + $full)
            ->add('heartbeatBarTheme', ChoiceType::class, [
                'label'   => 'form.settings.heartbeat_bar',
                'choices' => [
                    'form.settings.heartbeat_bar.normal' => 'normal',
                    'form.settings.heartbeat_bar.bottom' => 'bottom',
                    'form.settings.heartbeat_bar.none'   => 'none',
                ],
                'expanded' => true,
            ] + $full)
            ->add('elapsedTime', ChoiceType::class, [
                'label'   => 'form.settings.elapsed_time',
                'choices' => [
                    'form.settings.elapsed_time.show'      => 'show',
                    'form.settings.elapsed_time.show_line' => 'show_line',
                    'form.settings.elapsed_time.none'      => 'none',
                ],
                'expanded' => true,
            ] + $full)
            ->add('uiFramework', ChoiceType::class, [
                'label'   => 'form.settings.ui_framework',
                'help'    => 'form.settings.ui_framework_help',
                'choices' => [
                    'form.settings.ui_framework.default'   => 'default',
                    'form.settings.ui_framework.custom'    => 'custom',
                    'form.settings.ui_framework.bootstrap' => 'bootstrap',
                    'form.settings.ui_framework.tailwind'  => 'tailwind',
                ],
            ] + $full);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults(['data_class' => SettingsAppearanceData::class]);
    }
}
