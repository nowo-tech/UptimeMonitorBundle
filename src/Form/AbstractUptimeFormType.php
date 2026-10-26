<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\UptimeMonitorBundle\Translation\UptimeTranslation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
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

    /**
     * The form models hold non-null strings: an optional text field left empty submits '' (Symfony's text types turn
     * an empty submission into null, which a `string` property refuses — HTTP 500 on save).
     *
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    protected function addText(FormBuilderInterface $builder, string $name, array $options = []): void
    {
        $this->addWithDefaults($builder, $name, TextType::class, $options + ['empty_data' => '']);
    }

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    protected function addTextarea(FormBuilderInterface $builder, string $name, array $options = []): void
    {
        $this->addWithDefaults($builder, $name, TextareaType::class, $options + ['empty_data' => '']);
    }

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    protected function addUrl(FormBuilderInterface $builder, string $name, array $options = []): void
    {
        $this->addWithDefaults($builder, $name, UrlType::class, $options + ['empty_data' => '']);
    }

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    protected function addPassword(FormBuilderInterface $builder, string $name, array $options = []): void
    {
        $this->addWithDefaults($builder, $name, PasswordType::class, $options + ['empty_data' => '']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain'        => UptimeTranslation::DOMAIN,
            'choice_translation_domain' => UptimeTranslation::DOMAIN,
        ]);
    }
}
