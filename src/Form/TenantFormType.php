<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * @extends AbstractUptimeFormType<array{slug: string, name: string}>
 */
#[FormKitConfig('uptime_monitor')]
final class TenantFormType extends AbstractUptimeFormType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'slug', [
            'label'       => 'form.tenant.slug',
            'constraints' => [
                new NotBlank(),
                new Regex(pattern: '/^[a-z0-9\-]+$/', message: 'form.tenant.slug_regex'),
            ],
            'disabled' => $options['edit_slug'] ?? false,
        ]);
        $this->addText($builder, 'name', [
            'label'       => 'form.tenant.name',
            'constraints' => [new NotBlank()],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'edit_slug' => false,
        ]);
    }
}
