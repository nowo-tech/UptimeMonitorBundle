<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * @extends AbstractUptimeFormType<array{slug: string, name: string}>
 */
final class TenantFormType extends AbstractUptimeFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('slug', TextType::class, [
                'label'       => 'form.tenant.slug',
                'constraints' => [
                    new NotBlank(),
                    new Regex(pattern: '/^[a-z0-9\-]+$/', message: 'form.tenant.slug_regex'),
                ],
                'disabled' => $options['edit_slug'] ?? false,
            ])
            ->add('name', TextType::class, [
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
