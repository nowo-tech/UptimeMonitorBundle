import { test, expect } from '@playwright/test';
import { OUTAGE_MONITOR, openDashboard } from './helpers';

/**
 * UptimeMonitorBundle 1.30 monitor page options in the demo (config/packages/nowo_uptime_monitor.yaml:
 * web_ui.monitor_show.log; App\EventListener\DemoLiveUpdatesListener for ?live=paused | notify).
 */
test.describe('Monitor page options (1.30)', () => {
  async function openOutageMonitor(page: import('@playwright/test').Page): Promise<string> {
    await openDashboard(page);
    await page.getByRole('link', { name: OUTAGE_MONITOR, exact: true }).first().click();
    await expect(page).toHaveURL(/\/monitors\/\d+$/);
    return page.url();
  }

  test('the check log sorts by latency and keeps the time in the title', async ({ page }) => {
    const url = await openOutageMonitor(page);
    const log = page.getByTestId('uptime-monitor-log');
    await expect(log.locator('time').first()).toHaveAttribute('title', /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    await expect(log.locator('td.uptime-cell-num').first()).toBeVisible();

    await log.locator('a[data-log-sort="latency"]').click();
    await expect(page).toHaveURL(/log_sort=latency&log_dir=asc/);
    await expect(log.locator('th[aria-sort="ascending"] a[data-log-sort="latency"]')).toBeVisible();
    const latencies = await log.locator('tbody tr td:nth-child(3)').allTextContents();
    const values = latencies.map((text) => Number.parseInt(text, 10));
    expect(values.length).toBeGreaterThan(1);
    expect(values).toEqual([...values].sort((a, b) => a - b));

    await page.goto(url);
    await expect(log.locator('th[aria-sort="descending"] a[data-log-sort="time"]')).toBeVisible();
  });

  test('live updates paused renders no history URL and the page does not poll', async ({ page }) => {
    const url = await openOutageMonitor(page);
    const root = page.locator('#uptime-monitor-detail-root');
    await expect(root).toHaveAttribute('data-api-history', /\/history$/);

    await page.goto('about:blank');
    const polls: string[] = [];
    page.on('request', (request) => {
      if (request.url().includes('/history')) {
        polls.push(request.url());
      }
    });
    await page.goto(`${url}?live=paused`);
    await expect(root).toHaveAttribute('data-demo-live', 'paused');
    expect(await root.getAttribute('data-api-history')).toBeNull();
    await page.waitForTimeout(1500);
    expect(polls).toEqual([]);
    await expect(page.getByTestId('uptime-monitor-log')).toBeVisible();

    await page.goto(`${url}?live=notify`);
    await expect(root).toHaveAttribute('data-demo-live-watch-url', /\/history$/);
    expect(await root.getAttribute('data-api-history')).toBeNull();
  });
});
