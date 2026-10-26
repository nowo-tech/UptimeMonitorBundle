<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Form;

use Nowo\UptimeMonitorBundle\Form\TenantFormType;
use Nowo\UptimeMonitorBundle\Tests\Support\FormKitTestSupport;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @covers \Nowo\UptimeMonitorBundle\Form\TenantFormType
 */
final class TenantFormTypeTest extends TypeTestCase
{
    /**
     * @return list<object>
     */
    protected function getTypes(): array
    {
        return [
            FormKitTestSupport::withMerger(new TenantFormType()),
        ];
    }

    /**
     * @return array<int, object>
     */
    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension($this->validatorWithBundleCatalogue()),
        ];
    }

    public function testSubmitValidTenantData(): void
    {
        $form = $this->factory->create(TenantFormType::class, ['slug' => '', 'name' => '']);
        $form->submit(['slug' => 'acme-corp', 'name' => 'Acme Corp']);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertSame(['slug' => 'acme-corp', 'name' => 'Acme Corp'], $form->getData());
    }

    public function testInvalidSlugMessageComesFromTheBundleDomain(): void
    {
        $form = $this->factory->create(TenantFormType::class, ['slug' => '', 'name' => '']);
        $form->submit(['slug' => 'Not A Slug!', 'name' => 'Acme Corp']);

        self::assertFalse($form->isValid());
        $errors = $form->get('slug')->getErrors();
        self::assertCount(1, $errors);
        self::assertSame('Bundle domain: slug', $errors[0]->getMessage());
        self::assertSame('form.tenant.slug_regex', $errors[0]->getMessageTemplate());
    }

    public function testBlankSlugOnlyReportsNotBlank(): void
    {
        $form = $this->factory->create(TenantFormType::class, ['slug' => '', 'name' => '']);
        $form->submit(['slug' => '', 'name' => 'Acme Corp']);

        $errors = $form->get('slug')->getErrors();
        self::assertCount(1, $errors);
        self::assertNotSame('form.tenant.slug_regex', $errors[0]->getMessageTemplate());
    }

    private function validatorWithBundleCatalogue(): ValidatorInterface
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['form.tenant.slug_regex' => 'Bundle domain: slug'], 'en', 'NowoUptimeMonitorBundle');
        // Same key in the global validators domain must NOT be used any more.
        $translator->addResource('array', ['form.tenant.slug_regex' => 'Validators domain: slug'], 'en', 'validators');

        return Validation::createValidatorBuilder()
            ->setTranslator($translator)
            ->setTranslationDomain('validators')
            ->getValidator();
    }
}
