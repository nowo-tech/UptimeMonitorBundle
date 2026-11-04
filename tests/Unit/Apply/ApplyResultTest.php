<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Apply;

use Nowo\UptimeMonitorBundle\Apply\ApplyError;
use Nowo\UptimeMonitorBundle\Apply\ApplyFailedException;
use Nowo\UptimeMonitorBundle\Apply\ApplyRefusedException;
use Nowo\UptimeMonitorBundle\Apply\ApplyResult;
use Nowo\UptimeMonitorBundle\Event\MonitorsAppliedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ApplyResult::class)]
#[CoversClass(ApplyError::class)]
#[CoversClass(ApplyRefusedException::class)]
#[CoversClass(ApplyFailedException::class)]
#[CoversClass(MonitorsAppliedEvent::class)]
final class ApplyResultTest extends TestCase
{
    private const A = '00000000-0000-4000-8000-000000000000';
    private const B = '00000000-0000-4000-8000-000000000001';

    public function testTheOutputAndThePerTenantView(): void
    {
        $result = new ApplyResult(
            false,
            [['tenant' => self::A, 'tenant_enabled' => true]],
            [['tenant' => self::A, 'key' => 'one', 'id' => 1, 'token' => 'set'], ['tenant' => self::B, 'key' => 'two', 'id' => 2, 'token' => 'absent']],
            [['tenant' => self::A, 'key' => 'three', 'id' => 3, 'changed' => ['token'], 'token' => 'rotated']],
            [['tenant' => self::A, 'key' => 'four', 'id' => 4, 'token' => 'kept']],
            [['tenant' => self::B, 'key' => 'five', 'id' => 5]],
            [],
        );

        self::assertSame(2, $result->tokensChanged());
        $array = $result->toArray('sha');
        self::assertTrue($array['ok']);
        self::assertSame('sha', $array['file_sha256']);
        self::assertSame([], $array['errors']);
        self::assertArrayNotHasKey('quota_checked_unlocked', $array);

        $a = $result->forTenant(self::A);
        self::assertSame([['key' => 'one', 'id' => 1, 'token' => 'set']], $a['created']);
        self::assertSame([['key' => 'three', 'id' => 3, 'changed' => ['token'], 'token' => 'rotated']], $a['updated']);
        self::assertSame([], $a['pruned']);
        self::assertSame([['key' => 'five', 'id' => 5]], $result->forTenant(self::B)['pruned']);

        $event = new MonitorsAppliedEvent(self::A, $a['created'], $a['updated'], $a['unchanged'], $a['pruned']);
        self::assertSame(2, $event->tokensChanged());

        $dry = new ApplyResult(true, [], [], [], [], [], [['tenant' => self::A, 'key' => 'x', 'id' => 9]]);
        self::assertTrue($dry->toArray('sha')['quota_checked_unlocked']);
        self::assertSame(0, $dry->tokensChanged());
    }

    public function testExceptionsCarryCodesOnly(): void
    {
        $refused = new ApplyRefusedException([
            new ApplyError('/a', 'url_key_taken', ApplyError::EXIT_CONFLICT),
            new ApplyError('token-file:/k', 'token_reused', ApplyError::EXIT_TOKEN),
            new ApplyError('/b', 'url_key_taken', ApplyError::EXIT_CONFLICT),
        ]);
        self::assertSame('apply_refused: url_key_taken, token_reused', $refused->getMessage());
        self::assertSame(4, $refused->getExitCode());
        self::assertSame(['path' => '/a', 'code' => 'url_key_taken'], $refused->getErrors()[0]->toArray());

        $failed = ApplyFailedException::from(new RuntimeException('SQLSTATE: duplicate value (secret-hash)', 0, new RuntimeException('secret')));
        self::assertSame('apply_failed: ' . RuntimeException::class, $failed->getMessage());
        self::assertSame(RuntimeException::class, $failed->getCauseClass());
        self::assertNull($failed->getPrevious());
    }
}
