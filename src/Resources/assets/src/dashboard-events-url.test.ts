import { afterEach, describe, expect, it } from 'vitest';
import {
  appendMonitorFilterToUrl,
  EVENTS_FILTER_PARAM,
  readMonitorFilterFromUrl,
  writeMonitorFilterToUrl,
} from './dashboard-events-url';

describe('dashboard events filter URL', () => {
  afterEach(() => {
    window.history.replaceState(null, '', '/');
  });

  it('reads a positive monitor id from the query string', () => {
    window.history.replaceState(null, '', `/uptime/main?${EVENTS_FILTER_PARAM}=12`);
    expect(readMonitorFilterFromUrl()).toBe(12);
  });

  it('ignores a missing, empty, invalid or non-positive id', () => {
    window.history.replaceState(null, '', '/uptime/main');
    expect(readMonitorFilterFromUrl()).toBeNull();
    window.history.replaceState(null, '', '/uptime/main?monitor=');
    expect(readMonitorFilterFromUrl()).toBeNull();
    window.history.replaceState(null, '', '/uptime/main?monitor=abc');
    expect(readMonitorFilterFromUrl()).toBeNull();
    window.history.replaceState(null, '', '/uptime/main?monitor=0');
    expect(readMonitorFilterFromUrl()).toBeNull();
  });

  it('writes and removes the filter, keeping other query parameters and the hash', () => {
    window.history.replaceState(null, '', '/uptime/main?tab=x#events');
    writeMonitorFilterToUrl(7);
    expect(window.location.search).toBe('?tab=x&monitor=7');
    expect(window.location.hash).toBe('#events');

    writeMonitorFilterToUrl(null);
    expect(window.location.search).toBe('?tab=x');
  });

  it('appends the filter to a fragment URL', () => {
    expect(appendMonitorFilterToUrl('/uptime/main/fragment/layout', 3)).toBe('/uptime/main/fragment/layout?monitor=3');
    expect(appendMonitorFilterToUrl('/uptime/main/fragment/layout', null)).toBe('/uptime/main/fragment/layout');
    expect(appendMonitorFilterToUrl('', 3)).toBe('');
  });
});
