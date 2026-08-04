<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\UptimeMonitorBundle\Form\Model\SettingsReverseProxyData;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractUptimeFormType<SettingsReverseProxyData>
 */
#[FormKitConfig('uptime_monitor')]
final class SettingsReverseProxyFormType extends AbstractUptimeFormType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addCheckbox($builder, 'trustedProxy', [
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
