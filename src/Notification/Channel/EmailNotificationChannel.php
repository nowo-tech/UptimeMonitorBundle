<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Notification\Channel;

use Nowo\UptimeMonitorBundle\Notification\NotificationChannelInterface;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

use function is_array;

final class EmailNotificationChannel implements NotificationChannelInterface
{
    /**
     * @param array<string, mixed> $notificationsConfig
     */
    public function __construct(
        #[Autowire('%nowo_uptime_monitor.notifications%')]
        private readonly array $notificationsConfig,
        private readonly ?MailerInterface $mailer = null,
    ) {
    }

    public function getName(): string
    {
        return 'email';
    }

    public function isEnabled(): bool
    {
        $config = $this->notificationsConfig['email'] ?? [];

        return ($this->notificationsConfig['enabled'] ?? false)
            && ($config['enabled'] ?? false)
            && $this->mailer !== null;
    }

    public function send(UptimeAlert $alert): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $config = $this->notificationsConfig['email'] ?? [];
        $from   = (string) ($config['from'] ?? '');
        $to     = $config['to'] ?? [];

        if ($from === '' || !is_array($to) || $to === [] || $this->mailer === null) {
            return false;
        }

        $email = (new Email())
            ->from($from)
            ->to(...array_map('strval', $to))
            ->subject($alert->getSubject())
            ->text($alert->getMessage());

        $this->mailer->send($email);

        return true;
    }
}
