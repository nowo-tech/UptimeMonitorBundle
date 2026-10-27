<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Form;

use Nowo\UptimeMonitorBundle\Form\Model\TagFormData;
use Nowo\UptimeMonitorBundle\Form\TagFormType;
use Nowo\UptimeMonitorBundle\Tests\Support\FormKitTestSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Test\TypeTestCase;

#[CoversClass(TagFormType::class)]
final class TagFormTypeTest extends TypeTestCase
{
    /**
     * @return list<object>
     */
    protected function getTypes(): array
    {
        return [FormKitTestSupport::withMerger(new TagFormType())];
    }

    public function testAnEmptyNameOrColourSubmitsAnEmptyString(): void
    {
        $data        = new TagFormData();
        $data->name  = 'Production';
        $data->color = '#16a34a';

        $form = $this->factory->create(TagFormType::class, $data);
        $form->submit(['name' => '', 'color' => '']);

        self::assertTrue($form->isSynchronized());
        self::assertSame('', $data->name);
        self::assertSame('', $data->color);
    }
}
