<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\UptimeMonitorBundle\Translation\UptimeTranslation;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function is_string;
use function preg_match;

/**
 * @extends AbstractUptimeFormType<array{slug: string, name: string}>
 */
#[FormKitConfig('uptime_monitor')]
final class TenantFormType extends AbstractUptimeFormType
{
    public const SLUG_PATTERN = '/^[a-z0-9\-]+$/';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'slug', [
            'label'       => 'form.tenant.slug',
            'constraints' => [
                new NotBlank(),
                // Bundle-owned constraint message: translated from the NowoUptimeMonitorBundle domain, not the
                // global `validators` catalogue (REQ-I18N-003).
                new Callback(self::validateSlug(...)),
            ],
            'disabled' => $options['edit_slug'] ?? false,
        ]);
        $this->addText($builder, 'name', [
            'label'       => 'form.tenant.name',
            'constraints' => [new NotBlank()],
        ]);
    }

    /**
     * Adds a `form.tenant.slug_regex` violation (domain {@see UptimeTranslation::DOMAIN}) when the slug is not
     * lowercase letters, digits and hyphens. Empty values are left to {@see NotBlank}.
     */
    public static function validateSlug(mixed $value, ExecutionContextInterface $context): void
    {
        if (!is_string($value) || $value === '' || preg_match(self::SLUG_PATTERN, $value) === 1) {
            return;
        }

        $context->buildViolation('form.tenant.slug_regex')
            ->setTranslationDomain(UptimeTranslation::DOMAIN)
            ->setInvalidValue($value)
            ->setCode(Regex::REGEX_FAILED_ERROR)
            ->addViolation();
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'edit_slug' => false,
        ]);
    }
}
