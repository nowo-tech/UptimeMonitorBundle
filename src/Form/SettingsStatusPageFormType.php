<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsStatusPageData;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Settings → Status page (since 1.13): who may open the public status page and which monitors it shows.
 *
 * @extends AbstractUptimeFormType<SettingsStatusPageData>
 */
#[FormKitConfig('uptime_monitor')]
final class SettingsStatusPageFormType extends AbstractUptimeFormType
{
    /**
     * Adds the visibility and the monitors to show.
     *
     * @param array<string, mixed> $options Form options (`monitor_choices`: monitor name => id)
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $full = ['row_attr' => ['class' => 'uptime-settings-field--full']];

        $this->addChoice($builder, 'visibility', [
            'label'   => 'form.settings.status_page_visibility',
            'help'    => 'form.settings.status_page_visibility_help',
            'choices' => [
                'form.settings.status_page_visibility.default' => 'default',
                'form.settings.status_page_visibility.public'  => 'public',
                'form.settings.status_page_visibility.private' => 'private',
            ],
        ] + $full);
        $this->addChoice($builder, 'monitorIds', [
            'label'                     => 'form.settings.status_page_monitors',
            'help'                      => 'form.settings.status_page_monitors_help',
            'choices'                   => $options['monitor_choices'],
            'choice_translation_domain' => false,
            'multiple'                  => true,
            'expanded'                  => true,
            'required'                  => false,
        ] + $full);
    }

    /**
     * Configures the Status page settings options.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'data_class'      => SettingsStatusPageData::class,
            'monitor_choices' => [],
        ]);
        $resolver->setAllowedTypes('monitor_choices', 'array');
    }
}
