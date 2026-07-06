<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Form;

use Nowo\UptimeMonitorBundle\Form\TenantFormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

/**
 * @covers \Nowo\UptimeMonitorBundle\Form\TenantFormType
 */
final class TenantFormTypeTest extends TypeTestCase
{
    /**
     * @return array<int, object>
     */
    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidator()),
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
}
