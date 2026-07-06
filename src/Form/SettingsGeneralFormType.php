<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\UptimeMonitorBundle\Form\Model\SettingsGeneralData;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractUptimeFormType<SettingsGeneralData>
 */
final class SettingsGeneralFormType extends AbstractUptimeFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $half = ['row_attr' => ['class' => 'uptime-settings-field--half']];
        $full = ['row_attr' => ['class' => 'uptime-settings-field--full']];

        $builder
            ->add('displayTimezone', TextType::class, [
                'label' => 'form.settings.display_timezone',
                'help'  => 'form.settings.display_timezone_help',
            ] + $half)
            ->add('serverTimezone', TextType::class, [
                'label' => 'form.settings.server_timezone',
                'help'  => 'form.settings.server_timezone_help',
            ] + $half)
            ->add('searchEngineIndex', CheckboxType::class, [
                'label'    => 'form.settings.search_index',
                'required' => false,
            ] + $full)
            ->add('entryPage', ChoiceType::class, [
                'label'   => 'form.settings.entry_page',
                'choices' => [
                    'form.settings.entry_page.dashboard' => 'dashboard',
                    'form.settings.entry_page.status'    => 'status',
                ],
            ] + $full)
            ->add('primaryBaseUrl', UrlType::class, [
                'label'      => 'form.settings.base_url',
                'required'   => false,
                'empty_data' => '',
                'help'       => 'form.settings.base_url_help',
            ] + $full)
            ->add('steamApiKey', TextType::class, [
                'label'      => 'form.settings.steam_api',
                'required'   => false,
                'empty_data' => '',
            ] + $full)
            ->add('nscdEnabled', CheckboxType::class, [
                'label'    => 'form.settings.nscd',
                'required' => false,
            ] + $half)
            ->add('httpDnsCache', CheckboxType::class, [
                'label'    => 'form.settings.dns_cache',
                'required' => false,
            ] + $half)
            ->add('chromiumExecutable', TextType::class, [
                'label' => 'form.settings.chromium',
                'help'  => 'form.settings.chromium_help',
            ] + $full);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults(['data_class' => SettingsGeneralData::class]);
    }
}
