<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\UptimeMonitorBundle\Translation\UptimeTranslation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Base form type: all labels and help texts use the {@see UptimeTranslation::DOMAIN} catalogue.
 */
abstract class AbstractUptimeFormType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain'        => UptimeTranslation::DOMAIN,
            'choice_translation_domain' => UptimeTranslation::DOMAIN,
        ]);
    }
}
