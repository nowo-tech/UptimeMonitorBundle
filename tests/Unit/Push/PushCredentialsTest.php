<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Push;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Push\PushCredentials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function hash;
use function hash_hmac;
use function strtoupper;

#[CoversClass(PushCredentials::class)]
final class PushCredentialsTest extends TestCase
{
    private function monitor(): Monitor
    {
        return new Monitor(new Tenant('main', 'Main'), 'web-01', MonitorType::Push, 'push');
    }

    public function testEnsureKeyGivesAnOpaqueKeyOnce(): void
    {
        $monitor = $this->monitor();
        PushCredentials::ensureKey($monitor);
        $key = $monitor->getPushKey();

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $key);
        PushCredentials::ensureKey($monitor);
        self::assertSame($key, $monitor->getPushKey());
    }

    public function testIssuedTokenIsStoredOnlyAsItsHashAndVerifies(): void
    {
        $credentials = new PushCredentials('kernel-secret');
        $monitor     = $this->monitor();
        $token       = $credentials->issueToken($monitor);

        self::assertStringStartsWith(PushCredentials::TOKEN_PREFIX, $token);
        self::assertSame(hash('sha256', $token), $monitor->getPushTokenHash());
        self::assertNotNull($monitor->getPushKey());
        self::assertTrue($credentials->verifyToken($monitor, $token));
        self::assertFalse($credentials->verifyToken($monitor, $token . 'x'));
        self::assertFalse($credentials->verifyToken($monitor, ''));
        self::assertFalse($credentials->verifyToken(null, $token));
    }

    public function testRotationAndRevocationStopTheOldToken(): void
    {
        $credentials = new PushCredentials('kernel-secret');
        $monitor     = $this->monitor();
        $old         = $credentials->issueToken($monitor);
        $new         = $credentials->issueToken($monitor);

        self::assertNotSame($old, $new);
        self::assertFalse($credentials->verifyToken($monitor, $old));
        self::assertTrue($credentials->verifyToken($monitor, $new));

        $credentials->revoke($monitor);
        self::assertNull($monitor->getPushTokenHash());
        self::assertFalse($credentials->verifyToken($monitor, $new));
        self::assertNull($credentials->signingSecret($monitor));
    }

    public function testTheSigningSecretDependsOnTheKernelSecretAndTheToken(): void
    {
        $monitor = $this->monitor();
        $a       = new PushCredentials('secret-a');
        $a->issueToken($monitor);
        $secret = $a->signingSecret($monitor);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $secret);
        self::assertNotSame($secret, (new PushCredentials('secret-b'))->signingSecret($monitor));
        $a->issueToken($monitor);
        self::assertNotSame($secret, $a->signingSecret($monitor));
    }

    public function testSignatureMustMatchAndBeFresh(): void
    {
        $credentials = new PushCredentials('kernel-secret');
        $monitor     = $this->monitor();
        $credentials->issueToken($monitor);
        $secret    = (string) $credentials->signingSecret($monitor);
        $body      = '{"cpu_pct":1}';
        $signature = hash_hmac('sha256', '1000.' . $body, $secret);

        self::assertTrue($credentials->verifySignature($monitor, $body, '1000', $signature, 1100, 300));
        self::assertTrue($credentials->verifySignature($monitor, $body, '1000', strtoupper($signature), 1100, 300));
        self::assertFalse($credentials->verifySignature($monitor, $body, '1000', $signature, 1400, 300), 'outside the window');
        self::assertFalse($credentials->verifySignature($monitor, $body . ' ', '1000', $signature, 1100, 300), 'other body');
        self::assertFalse($credentials->verifySignature($monitor, $body, 'abc', $signature, 1100, 300), 'not a timestamp');

        $credentials->revoke($monitor);
        self::assertFalse($credentials->verifySignature($monitor, $body, '1000', $signature, 1100, 300), 'no token');
    }
}
