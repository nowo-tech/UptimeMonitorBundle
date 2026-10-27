<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use Nowo\UiKitBundle\View\Action;
use Nowo\UptimeMonitorBundle\Entity\Monitor;

/**
 * Host extension point of the admin pages (since 1.12): dispatched before the dashboard (`dashboard`) and a monitor
 * page (`monitor_show`) render, so a host adds header actions and its own sections (a template and its variables,
 * rendered before or after the bundle content) without overriding a bundle template.
 */
final class UptimePageEvent
{
    /** @var list<Action> */
    private array $actions = [];

    /** @var list<array{template: string, context: array<string, mixed>, position: string}> */
    private array $sections = [];

    /**
     * Creates the event of one page render.
     *
     * @param string $page Page id: `dashboard` or `monitor_show`
     * @param string $tenantSlug Tenant of the page
     * @param Monitor|null $monitor The monitor of a monitor page
     */
    public function __construct(
        public readonly string $page,
        public readonly string $tenantSlug,
        public readonly ?Monitor $monitor = null,
    ) {
    }

    /**
     * Adds a page header action after the bundle's.
     */
    public function addAction(Action $action): void
    {
        // @igor-ignore - Request-scoped event object; not a shared worker service.
        $this->actions[] = $action;
    }

    /**
     * Adds a section: `$template` rendered with `$context` (plus the page variables) before or after the bundle
     * content of the page.
     *
     * @param array<string, mixed> $context Template variables
     * @param string $position `before` or `after`
     */
    public function addSection(string $template, array $context = [], string $position = 'after'): void
    {
        // @igor-ignore - Request-scoped event object; not a shared worker service.
        $this->sections[] = ['template' => $template, 'context' => $context, 'position' => $position === 'before' ? 'before' : 'after'];
    }

    /**
     * The header actions the listeners added.
     *
     * @return list<Action>
     */
    public function getActions(): array
    {
        return $this->actions;
    }

    /**
     * The sections the listeners added at `$position` (`before` or `after`).
     *
     * @return list<array{template: string, context: array<string, mixed>}>
     */
    public function getSections(string $position): array
    {
        $sections = [];
        foreach ($this->sections as $section) {
            if ($section['position'] === $position) {
                $sections[] = ['template' => $section['template'], 'context' => $section['context']];
            }
        }

        return $sections;
    }
}
