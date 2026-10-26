<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Form;

use Nowo\UptimeMonitorBundle\Form\CsrfActionFormType;
use Nowo\UptimeMonitorBundle\Form\MonitorSearchFormType;
use Nowo\UptimeMonitorBundle\Tests\Support\FormKitTestSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use SensitiveParameter;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\Exception\TokenNotFoundException;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;

#[CoversClass(CsrfActionFormType::class)]
#[CoversClass(MonitorSearchFormType::class)]
final class ActionFormTypesTest extends TypeTestCase
{
    private CsrfTokenManager $csrfTokenManager;

    protected function setUp(): void
    {
        $storage = new class implements TokenStorageInterface {
            /** @var array<string, string> */
            private array $tokens = [];

            public function getToken(string $tokenId): string
            {
                return $this->tokens[$tokenId] ?? throw new TokenNotFoundException();
            }

            public function setToken(string $tokenId, #[SensitiveParameter] string $token): void
            {
                $this->tokens[$tokenId] = $token;
            }

            public function removeToken(string $tokenId): ?string
            {
                $token = $this->tokens[$tokenId] ?? null;
                unset($this->tokens[$tokenId]);

                return $token;
            }

            public function hasToken(string $tokenId): bool
            {
                return isset($this->tokens[$tokenId]);
            }
        };
        $this->csrfTokenManager = new CsrfTokenManager(null, $storage);

        parent::setUp();
    }

    protected function getExtensions(): array
    {
        return [new CsrfExtension($this->csrfTokenManager)];
    }

    /**
     * @return list<object>
     */
    protected function getTypes(): array
    {
        return [
            FormKitTestSupport::withMerger(new CsrfActionFormType()),
            FormKitTestSupport::withMerger(new MonitorSearchFormType()),
        ];
    }

    public function testCsrfActionFormPostsAFlatTokenField(): void
    {
        $form = $this->factory->create(CsrfActionFormType::class, null, ['csrf_token_id' => 'purge_history']);

        self::assertSame('', $form->getName());
        self::assertSame('_token', $form->getConfig()->getOption('csrf_field_name'));

        $token = $this->csrfTokenManager->getToken('purge_history');
        $form->submit(['_token' => $token->getValue(), 'extra' => 'ignored']);

        self::assertTrue($form->isValid());
        self::assertTrue($this->csrfTokenManager->isTokenValid(new CsrfToken('purge_history', $token->getValue())));
    }

    public function testCsrfActionFormRejectsAWrongToken(): void
    {
        $form = $this->factory->create(CsrfActionFormType::class, null, ['csrf_token_id' => 'delete_tag']);
        $form->submit(['_token' => 'nope']);

        self::assertFalse($form->isValid());
    }

    public function testMonitorSearchIsAnUnmappedGetFilterWithoutCsrf(): void
    {
        $form = $this->factory->create(MonitorSearchFormType::class);

        self::assertSame('', $form->getName());
        self::assertSame('GET', $form->getConfig()->getMethod());
        self::assertFalse($form->getConfig()->getOption('csrf_protection'));
        self::assertFalse($form->get('q')->getConfig()->getMapped());
        self::assertSame('', $form->get('q')->getConfig()->getOption('attr')['data-monitor-search']);
    }
}
