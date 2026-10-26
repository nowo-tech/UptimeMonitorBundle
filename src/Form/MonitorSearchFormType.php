<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Override;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Dashboard sidebar monitor filter (REQ-TWIG-005).
 *
 * Client-side only: the dashboard script filters the sidebar on input via {@code [data-monitor-search]}; the form is
 * never submitted (GET, no CSRF, unmapped field). The translated {@code aria-label} is merged in the template.
 *
 * @extends AbstractUptimeFormType<mixed>
 */
#[FormKitConfig('uptime_monitor')]
final class MonitorSearchFormType extends AbstractUptimeFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('q', SearchType::class, [
            'label'    => false,
            'required' => false,
            'mapped'   => false,
            'attr'     => [
                'class'               => 'uptime-search-input',
                'placeholder'         => 'dashboard.search_placeholder',
                'data-monitor-search' => '',
                'autocomplete'        => 'off',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'method'          => 'GET',
            'csrf_protection' => false,
        ]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
