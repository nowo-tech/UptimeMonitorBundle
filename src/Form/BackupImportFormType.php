<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\UptimeMonitorBundle\Service\MonitorBackupService;
use Override;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Settings → Backup JSON import (REQ-TWIG-005).
 *
 * Field names stay flat ({@code action}, {@code import_mode}, {@code backup}, {@code _token}) and the CSRF token id
 * stays {@code backup-import}, so the posted payload is unchanged from the former hand-rolled form.
 *
 * @extends AbstractUptimeFormType<array<string, mixed>>
 */
#[FormKitConfig('uptime_monitor')]
final class BackupImportFormType extends AbstractUptimeFormType
{
    use FormOptionsTrait;

    public const CSRF_TOKEN_ID = 'backup-import';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('action', HiddenType::class, [
            'mapped' => false,
            'data'   => 'import',
        ]);
        $this->addWithDefaults($builder, 'import_mode', ChoiceType::class, [
            'label'    => 'settings.backup.import_mode',
            'expanded' => true,
            'multiple' => false,
            'required' => true,
            'data'     => MonitorBackupService::IMPORT_SKIP,
            'choices'  => [
                'settings.backup.import_skip'      => MonitorBackupService::IMPORT_SKIP,
                'settings.backup.import_keep'      => MonitorBackupService::IMPORT_KEEP,
                'settings.backup.import_overwrite' => MonitorBackupService::IMPORT_OVERWRITE,
            ],
        ]);
        $builder->add('backup', FileType::class, [
            'label'    => false,
            'required' => true,
            'attr'     => ['accept' => 'application/json,.json'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id'   => self::CSRF_TOKEN_ID,
        ]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
