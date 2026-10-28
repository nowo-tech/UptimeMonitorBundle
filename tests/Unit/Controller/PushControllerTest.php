<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Controller\PushController;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Push\PushCredentials;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function hash_hmac;
use function str_repeat;

#[CoversClass(PushController::class)]
final class PushControllerTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';

    private Monitor $monitor;
    private string $token;
    private PushCredentials $credentials;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->monitor = new Monitor(new Tenant('main', 'Main'), 'web', MonitorType::Push, 'push');
        $this->monitor->setIntervalSeconds(60)->setPushKey(self::KEY);
        $this->credentials = new PushCredentials('kernel-secret');
        $this->token       = $this->credentials->issueToken($this->monitor);
        $this->clock       = new MockClock(new DateTimeImmutable('2026-10-28 10:00:00'));
    }

    /**
     * @param array<string, mixed> $push
     */
    private function controller(?Monitor $found, bool $flushes = false, array $push = []): PushController
    {
        $repository = $this->createMock(MonitorRepository::class);
        $repository->method('findOneByPushKey')->willReturn($found);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($flushes ? self::once() : self::never())->method('flush');

        return new PushController($repository, $this->credentials, $em, $push + [
            'enabled'                  => true,
            'max_body_bytes'           => 8192,
            'signature_window_seconds' => 300,
            'min_push_seconds'         => 10,
        ], $this->clock);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $body, ?string $token = null, array $headers = []): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
        }

        return Request::create('/uptime-push/' . self::KEY, 'POST', server: $server, content: $body);
    }

    public function testAnAcceptedPushIsRecorded(): void
    {
        $response = $this->controller($this->monitor, true)(self::KEY, $this->request('{"cpu_pct":12}', $this->token));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('{"status":"accepted"}', $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame(['cpu_pct' => 12.0], $this->monitor->getPushSample());
        self::assertEquals($this->clock->now(), $this->monitor->getPushLastAt());
    }

    public function testAnEmptyHeartbeatNeedsNoContentType(): void
    {
        $request = Request::create('/uptime-push/' . self::KEY, 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);

        self::assertSame(202, $this->controller($this->monitor, true)(self::KEY, $request)->getStatusCode());
        self::assertSame([], $this->monitor->getPushSample());
    }

    public function testUnknownKeyWrongTokenAndNoTokenAreTheSame401(): void
    {
        $responses = [
            $this->controller(null)(self::KEY, $this->request('{}', $this->token)),
            $this->controller($this->monitor)(self::KEY, $this->request('{}', 'upm_wrong')),
            $this->controller($this->monitor)(self::KEY, $this->request('{}')),
            $this->controller($this->monitor)(self::KEY, $this->request('{}', $this->token . ' extra')),
        ];
        foreach ($responses as $response) {
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('{"error":"unauthorized"}', $response->getContent());
            self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
        }
        self::assertNull($this->monitor->getPushLastAt());
    }

    public function testARevokedTokenIs401(): void
    {
        $this->credentials->revoke($this->monitor);

        self::assertSame(401, $this->controller($this->monitor)(self::KEY, $this->request('{}', $this->token))->getStatusCode());
    }

    public function testPausedMonitorOrDisabledTenantIs403(): void
    {
        $this->monitor->setPaused(true);
        self::assertSame(403, $this->controller($this->monitor)(self::KEY, $this->request('{}', $this->token))->getStatusCode());

        $this->monitor->setPaused(false);
        $this->monitor->getTenant()->setEnabled(false);
        self::assertSame(403, $this->controller($this->monitor)(self::KEY, $this->request('{}', $this->token))->getStatusCode());
    }

    public function testTooLargeBodiesAre413WhateverTheDeclaredLength(): void
    {
        $declared = $this->request('{}', $this->token);
        $declared->headers->set('Content-Length', '9000');
        self::assertSame(413, $this->controller($this->monitor)(self::KEY, $declared)->getStatusCode());

        $real = $this->request('{"x":"' . str_repeat('a', 600) . '"}', $this->token);
        self::assertSame(413, $this->controller($this->monitor, push: ['max_body_bytes' => 512])(self::KEY, $real)->getStatusCode());
    }

    public function testPushesTooCloseTogetherAre429(): void
    {
        $this->monitor->recordPush($this->clock->now()->modify('-20 seconds'), []);
        $response = $this->controller($this->monitor)(self::KEY, $this->request('{}', $this->token));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('10', $response->headers->get('Retry-After'));
    }

    public function testABodyThatIsNotJsonIs415AndAnInvalidOne422(): void
    {
        $text = $this->request('cpu=1', $this->token);
        $text->headers->set('Content-Type', 'text/plain');
        self::assertSame(415, $this->controller($this->monitor)(self::KEY, $text)->getStatusCode());

        $invalid = $this->controller($this->monitor)(self::KEY, $this->request('{"cpu_pct":500}', $this->token));
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame('{"error":"invalid_payload","details":["cpu_pct: number between 0 and 100 expected"]}', $invalid->getContent());
    }

    public function testASigningMonitorNeedsAFreshSignature(): void
    {
        $this->monitor->setConfig(['push_signed' => true]);
        $secret = (string) $this->credentials->signingSecret($this->monitor);
        $body   = '{"cpu_pct":1}';
        $now    = (string) $this->clock->now()->getTimestamp();

        self::assertSame(401, $this->controller($this->monitor)(self::KEY, $this->request($body, $this->token))->getStatusCode());
        self::assertSame(401, $this->controller($this->monitor)(self::KEY, $this->request($body, $this->token, [
            'X-Uptime-Timestamp' => (string) ((int) $now - 600),
            'X-Uptime-Signature' => hash_hmac('sha256', ((int) $now - 600) . '.' . $body, $secret),
        ]))->getStatusCode());
        self::assertSame(202, $this->controller($this->monitor, true)(self::KEY, $this->request($body, $this->token, [
            'X-Uptime-Timestamp' => $now,
            'X-Uptime-Signature' => hash_hmac('sha256', $now . '.' . $body, $secret),
        ]))->getStatusCode());
    }

    public function testDisabledIngestIs404(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller($this->monitor, push: ['enabled' => false])(self::KEY, $this->request('{}', $this->token));
    }
}
