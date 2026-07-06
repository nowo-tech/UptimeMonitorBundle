/** Query parameter for recent-checks monitor filter (shareable dashboard URL). */
export const EVENTS_FILTER_PARAM = 'monitor';

export function readMonitorFilterFromUrl(): number | null {
  const raw = new URLSearchParams(window.location.search).get(EVENTS_FILTER_PARAM);
  if (raw === null || raw === '') {
    return null;
  }

  const id = Number.parseInt(raw, 10);

  return Number.isNaN(id) || id <= 0 ? null : id;
}

export function writeMonitorFilterToUrl(monitorId: number | null): void {
  const url = new URL(window.location.href);
  if (monitorId === null) {
    url.searchParams.delete(EVENTS_FILTER_PARAM);
  } else {
    url.searchParams.set(EVENTS_FILTER_PARAM, String(monitorId));
  }

  window.history.replaceState(null, '', `${url.pathname}${url.search}${url.hash}`);
}

export function appendMonitorFilterToUrl(baseUrl: string, monitorId: number | null): string {
  if (baseUrl === '' || monitorId === null) {
    return baseUrl;
  }

  const url = new URL(baseUrl, window.location.origin);
  url.searchParams.set(EVENTS_FILTER_PARAM, String(monitorId));

  return `${url.pathname}${url.search}`;
}
