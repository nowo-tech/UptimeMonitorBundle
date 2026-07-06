<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\UptimeMonitorBundle\Form\Model\SettingsReverseProxyData;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractUptimeFormType<SettingsReverseProxyData>
 */
final class SettingsReverseProxyFormType extends AbstractUptimeFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('trustedProxy', CheckboxType::class, [
            'label'    => 'form.settings.trusted_proxy',
            'required' => false,
            'help'     => 'form.settings.trusted_proxy_help',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults(['data_class' => SettingsReverseProxyData::class]);
    }
}
