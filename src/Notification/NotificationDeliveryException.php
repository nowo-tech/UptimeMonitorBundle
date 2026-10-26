<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Notification;

use RuntimeException;

use function is_array;

/**
 * Thrown by a notification channel when an alert could not be delivered.
 *
 * Its message is safe to log (REQ-OBS-001): it names the channel, the endpoint origin (scheme://host[:port]) and the
 * failure kind, never the URL path/query (webhook tokens), credentials, recipients or the payload. The original
 * exception is deliberately not chained, because transport errors usually embed the full URL.
 */
final class NotificationDeliveryException extends RuntimeException
{
    /**
     * Reduces a URL to its origin (`https://hooks.slack.com`), dropping user info, path, query and fragment.
     */
    public static function redactUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '[invalid url]';
        }

        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }
}
