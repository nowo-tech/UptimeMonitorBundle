<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Notification;

use Nowo\UptimeMonitorBundle\Notification\NotificationDeliveryException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NotificationDeliveryException::class)]
final class NotificationDeliveryExceptionTest extends TestCase
{
    public function testRedactUrlKeepsOnlyTheOrigin(): void
    {
        self::assertSame('https://hooks.slack.com', NotificationDeliveryException::redactUrl('https://user:pw@hooks.slack.com/services/T0/B0/secret?x=1#f'));
        self::assertSame('http://hooks.example.test:8080', NotificationDeliveryException::redactUrl('http://hooks.example.test:8080/token'));
        self::assertSame('https://hooks.example.test', NotificationDeliveryException::redactUrl('//hooks.example.test/path'));
        self::assertSame('[invalid url]', NotificationDeliveryException::redactUrl('not a url'));
        self::assertSame('[invalid url]', NotificationDeliveryException::redactUrl('http:///nohost'));
    }
}
