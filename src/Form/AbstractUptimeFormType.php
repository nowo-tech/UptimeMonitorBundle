<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\UptimeMonitorBundle\Translation\UptimeTranslation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @template TData
 *
 * @extends AbstractType<TData>
 */
#[FormKitConfig('uptime_monitor')]
abstract class AbstractUptimeFormType extends AbstractType
{
    use FormOptionsTrait;

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain'        => UptimeTranslation::DOMAIN,
            'choice_translation_domain' => UptimeTranslation::DOMAIN,
        ]);
    }
}
