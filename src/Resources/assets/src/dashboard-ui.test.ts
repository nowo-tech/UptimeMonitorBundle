import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  findSyncBadge,
  initLiveIndicator,
  initProjectCollapse,
  pulseLiveIndicator,
  setMercureConnectionState,
  setPollingFallbackBadge,
} from './dashboard-ui';
import { clearBundleLoggerForTest, setBundleLogger, type BundleLogger } from './uptime-logger';

function spyLogger(): BundleLogger {
  return { scriptLoaded: vi.fn(), setDebug: vi.fn(), debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() };
}

function mountGroups(): HTMLElement {
  const root = document.createElement('div');
  root.innerHTML = `
    <div data-project-group data-project-id="prod">
      <button type="button" data-project-toggle><span data-chevron-toggle></span><a href="/x">link</a></button>
      <ul data-project-body></ul>
    </div>
    <div data-project-group data-project-id="">
      <button type="button" data-project-toggle></button>
      <ul data-project-body></ul>
    </div>`;
  document.body.replaceChildren(root);
  return root;
}

describe('project collapse', () => {
  beforeEach(() => sessionStorage.clear());

  it('toggles a group, persists it and restores it on the next init', () => {
    const root = mountGroups();
    initProjectCollapse(root);
    const section = root.querySelector('[data-project-id="prod"]') as HTMLElement;
    const toggle = section.querySelector('[data-project-toggle]') as HTMLElement;

    toggle.click();
    expect(section.classList.contains('is-collapsed')).toBe(true);
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(JSON.parse(sessionStorage.getItem('uptime-project-collapsed') ?? '[]')).toEqual(['prod']);

    (section.querySelector('[data-chevron-toggle]') as HTMLElement).click();
    expect(section.classList.contains('is-collapsed')).toBe(false);
    expect(sessionStorage.getItem('uptime-project-collapsed')).toBe('[]');

    toggle.click();
    const again = mountGroups();
    initProjectCollapse(again);
    expect(again.querySelector('[data-project-id="prod"]')?.classList.contains('is-collapsed')).toBe(true);
  });

  it('ignores clicks on links inside the toggle', () => {
    const root = mountGroups();
    initProjectCollapse(root);
    (root.querySelector('a') as HTMLElement).click();
    expect(root.querySelector('[data-project-id="prod"]')?.classList.contains('is-collapsed')).toBe(false);
  });

  it('survives a corrupt stored state', () => {
    sessionStorage.setItem('uptime-project-collapsed', '{not json');
    const root = mountGroups();
    expect(() => initProjectCollapse(root)).not.toThrow();
    expect(root.querySelector('.is-collapsed')).toBeNull();
  });
});

describe('live indicator and sync badge', () => {
  afterEach(() => clearBundleLoggerForTest());

  it('pulses the live indicator', () => {
    const root = document.createElement('div');
    root.innerHTML = '<span data-live-indicator class="is-pulsing"></span>';
    const badge = initLiveIndicator(root);
    pulseLiveIndicator(badge);
    expect(badge?.classList.contains('is-pulsing')).toBe(true);
    expect(() => pulseLiveIndicator(null)).not.toThrow();
  });

  it('finds the badge in the sibling sync bar, else anywhere in the document', () => {
    document.body.innerHTML = '<div class="uptime-sync-bar"><span data-sync-badge id="a"></span></div><div id="root"></div>';
    const root = document.getElementById('root') as HTMLElement;
    expect(findSyncBadge(root)?.id).toBe('a');

    document.body.innerHTML = '<span data-sync-badge id="b"></span><p></p><div id="root"></div>';
    expect(findSyncBadge(document.getElementById('root') as HTMLElement)?.id).toBe('b');
  });

  it('labels every Mercure state and logs it', () => {
    const log = spyLogger();
    setBundleLogger(log);
    document.body.innerHTML = '<div class="uptime-sync-bar"><span data-sync-badge></span></div><div id="root"></div>';
    const root = document.getElementById('root') as HTMLElement;
    const badge = document.querySelector('[data-sync-badge]') as HTMLElement;

    setMercureConnectionState(root, 'connecting', { hub: 'http://hub' });
    expect(badge.textContent).toBe('Mercure · connecting…');
    expect(log.info).toHaveBeenCalledWith('Mercure connecting…', { hub: 'http://hub', topic: '' });

    setMercureConnectionState(root, 'connected');
    expect(badge.classList.contains('is-mercure-connected')).toBe(true);
    expect(log.info).toHaveBeenCalledWith('Mercure connected.');

    setMercureConnectionState(root, 'error', { topic: '/t' });
    expect(badge.classList.contains('is-mercure-error')).toBe(true);
    expect(log.warn).toHaveBeenCalledWith('Mercure connection error.', { hub: '', topic: '/t' });

    setMercureConnectionState(root, 'disconnected');
    expect(badge.dataset.mercureState).toBe('disconnected');
    expect(badge.title).toBe('Mercure SSE closed');
  });

  it('switches the badge to the polling fallback', () => {
    const log = spyLogger();
    setBundleLogger(log);
    document.body.innerHTML = '<div class="uptime-sync-bar"><span data-sync-badge class="is-mercure-connected"></span></div><div id="root"></div>';
    const root = document.getElementById('root') as HTMLElement;
    setPollingFallbackBadge(root, 30000);

    const badge = document.querySelector('[data-sync-badge]') as HTMLElement;
    expect(badge.textContent).toBe('Polling · 30s');
    expect(badge.dataset.mercureState).toBe('polling-fallback');
    expect(badge.classList.contains('is-mercure-connected')).toBe(false);
    expect(log.warn).toHaveBeenCalledWith('Summary polling fallback active.', { interval_ms: 30000 });
  });

  it('does nothing without a badge', () => {
    document.body.innerHTML = '<div id="root"></div>';
    const root = document.getElementById('root') as HTMLElement;
    expect(() => setMercureConnectionState(root, 'connected')).not.toThrow();
    expect(() => setPollingFallbackBadge(root, 1000)).not.toThrow();
  });
});
