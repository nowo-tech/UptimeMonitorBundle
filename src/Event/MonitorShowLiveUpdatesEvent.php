<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use InvalidArgumentException;
use Nowo\UptimeMonitorBundle\Entity\Monitor;

use function in_array;
use function is_bool;
use function preg_match;
use function sprintf;
use function trim;

/**
 * Live updates of one monitor page render (since 1.30): dispatched before the page renders, so a host.
 *
 * - turns the history polling off for this request ({@see self::setApiHistoryUrl()} with null: the script root then has
 *   no `data-api-history` and `uptime-monitor-detail.js` does not poll), e.g. when the user paused live updates;
 * - adds attributes to the script root `#uptime-monitor-detail-root` ({@see self::setRootAttribute()}), e.g. a peer
 *   controller of its own that watches {@see self::$historyUrl}.
 *
 * Without listeners the page is the 1.29 one. The event is a request-scoped object (no state in a service): listeners
 * decide from the request they are called for. Attribute names are restricted to `data-*`, `aria-*`, `role`, `title`,
 * `lang`, `dir` and `class` (appended to the root class), never `on*` or `style`, and never the attributes the bundle
 * renders on the root; every value is escaped by the template.
 */
final class MonitorShowLiveUpdatesEvent
{
    /** Attributes the bundle renders on the root (not replaceable). */
    public const RESERVED_ATTRIBUTES = ['id', 'data-monitor-id', 'data-api-history', 'data-series', 'data-latency-series'];

    private ?string $apiHistoryUrl;

    /** @var array<string, string|true> */
    private array $rootAttributes = [];

    /**
     * Creates the event of one monitor page render.
     *
     * @param string $tenantSlug Tenant of the page
     * @param Monitor $monitor The monitor shown
     * @param string $historyUrl The monitor's history API URL (what the script polls by default)
     */
    public function __construct(
        public readonly string $tenantSlug,
        public readonly Monitor $monitor,
        public readonly string $historyUrl,
    ) {
        $this->apiHistoryUrl = $historyUrl;
    }

    /**
     * The history URL the page renders as `data-api-history` (null: no polling).
     */
    public function getApiHistoryUrl(): ?string
    {
        return $this->apiHistoryUrl;
    }

    /**
     * Sets the history URL the script polls; null (or an empty string) renders no `data-api-history`, so the script
     * keeps the server-rendered state.
     */
    public function setApiHistoryUrl(?string $url): void
    {
        // @igor-ignore - Request-scoped event object; not a shared worker service.
        $this->apiHistoryUrl = $url === null || trim($url) === '' ? null : $url;
    }

    /**
     * Adds (or, with null / false, removes) an attribute of the script root. `true` renders the bare attribute; `class`
     * is appended to the bundle's root class.
     *
     * @throws InvalidArgumentException When the name is not allowed (see the class comment)
     */
    public function setRootAttribute(string $name, string|int|bool|null $value): void
    {
        if (!self::isAllowedAttribute($name)) {
            throw new InvalidArgumentException(sprintf('Root attribute "%s" is not allowed (data-*, aria-*, role, title, lang, dir, class; not the bundle\'s own).', $name));
        }
        if ($value === null || $value === false) {
            // @igor-ignore - Request-scoped event object; not a shared worker service.
            unset($this->rootAttributes[$name]);

            return;
        }
        // @igor-ignore - Request-scoped event object; not a shared worker service.
        $this->rootAttributes[$name] = is_bool($value) ? true : (string) $value;
    }

    /**
     * The attributes the listeners added, by name.
     *
     * @return array<string, string|true>
     */
    public function getRootAttributes(): array
    {
        return $this->rootAttributes;
    }

    /**
     * Whether `$name` may be set on the root: lower-case `data-*` / `aria-*`, `role`, `title`, `lang`, `dir`, `class`,
     * none of {@see self::RESERVED_ATTRIBUTES}.
     */
    public static function isAllowedAttribute(string $name): bool
    {
        if (in_array($name, self::RESERVED_ATTRIBUTES, true)) {
            return false;
        }

        return in_array($name, ['role', 'title', 'lang', 'dir', 'class'], true)
            || preg_match('/^(?:data-[a-z0-9]+(?:[-_.][a-z0-9]+)*|aria-[a-z]+)$/D', $name) === 1;
    }
}
