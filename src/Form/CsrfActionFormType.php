<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Override;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CSRF-only form for single-action POSTs (purge history, clear statistics, delete tag) — REQ-TWIG-005.
 *
 * The empty block prefix keeps the posted field flat ({@code _token}), so the contract stays the same as the
 * former hand-rolled hidden input; pass the controller's {@code csrf_token_id} and {@code action} when creating it.
 *
 * @extends AbstractUptimeFormType<mixed>
 */
#[FormKitConfig('uptime_monitor')]
final class CsrfActionFormType extends AbstractUptimeFormType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // CSRF only — no application fields.
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'allow_extra_fields' => true,
        ]);
        $resolver->setRequired('csrf_token_id');
        $resolver->setAllowedTypes('csrf_token_id', 'string');
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
