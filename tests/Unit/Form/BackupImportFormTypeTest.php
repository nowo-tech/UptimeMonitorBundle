<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Form;

use Nowo\UptimeMonitorBundle\Form\BackupImportFormType;
use Nowo\UptimeMonitorBundle\Form\CsrfActionFormType;
use Nowo\UptimeMonitorBundle\Form\MonitorSearchFormType;
use Nowo\UptimeMonitorBundle\Tests\Support\FormKitTestSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Test\TypeTestCase;

#[CoversClass(BackupImportFormType::class)]
#[CoversClass(CsrfActionFormType::class)]
#[CoversClass(MonitorSearchFormType::class)]
final class BackupImportFormTypeTest extends TypeTestCase
{
    /**
     * @return list<object>
     */
    protected function getTypes(): array
    {
        return [
            FormKitTestSupport::withMerger(new BackupImportFormType()),
            FormKitTestSupport::withMerger(new CsrfActionFormType()),
            FormKitTestSupport::withMerger(new MonitorSearchFormType()),
        ];
    }

    public function testBackupImportKeepsFlatFieldNames(): void
    {
        $form = $this->factory->create(BackupImportFormType::class);
        $view = $form->createView();

        self::assertSame('', $form->getName());
        self::assertSame('action', $view['action']->vars['full_name']);
        self::assertSame('import_mode', $view['import_mode']->vars['full_name']);
        self::assertSame('backup', $view['backup']->vars['full_name']);
        self::assertTrue($view->vars['multipart']);
        self::assertSame('skip', $form->get('import_mode')->getData());

        $form->submit(['action' => 'import', 'import_mode' => 'overwrite']);

        self::assertTrue($form->isSynchronized());
        self::assertSame('overwrite', $form->get('import_mode')->getData());
    }

    public function testCsrfActionFormHasNoFields(): void
    {
        $form = $this->factory->create(CsrfActionFormType::class, null, ['csrf_token_id' => 'purge-history']);

        self::assertSame('', $form->getName());
        self::assertCount(0, $form);
    }

    public function testMonitorSearchFormIsClientSideOnly(): void
    {
        $form = $this->factory->create(MonitorSearchFormType::class);
        $view = $form->createView();

        self::assertSame('GET', $form->getConfig()->getMethod());
        self::assertSame('q', $view['q']->vars['full_name']);
        self::assertArrayHasKey('data-monitor-search', $view['q']->vars['attr']);
    }
}
