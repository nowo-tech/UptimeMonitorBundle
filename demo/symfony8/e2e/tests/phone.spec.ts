import { test, expect, type Page } from '@playwright/test';
import { OUTAGE_MONITOR, TENANT } from './helpers';

/**
 * 1.24 phone layout (Beacon mobile audit MUX-21, MUX-100, MUX-101) at 390 x 844, projects phone-chromium and
 * phone-webkit. `make test-e2e-phone` serves the demo with the table dashboard (UPTIME_DASHBOARD_VIEW=table); with the
 * tree dashboard the table checks are skipped and only the monitor page is checked.
 */

/** Horizontal overflow of the page scroller, in CSS pixels (0 = none). */
async function pageOverflow(page: Page): Promise<number> {
  return page.evaluate(() => {
    const scroller = document.scrollingElement ?? document.documentElement;
    return Math.max(0, scroller.scrollWidth - window.innerWidth);
  });
}

async function openTableDashboard(page: Page): Promise<boolean> {
  const response = await page.goto(`/uptime/${TENANT}`);
  expect(response?.status()).toBe(200);
  return (await page.locator('[data-uptime-dashboard-table]').count()) > 0;
}

test.describe('phone layout (390 x 844)', () => {
  test('MUX-21: the monitors list stacks status, name and meta with no horizontal overflow', async ({ page }, testInfo) => {
    test.skip(!(await openTableDashboard(page)), 'the demo serves the tree dashboard (run make test-e2e-phone)');

    const rows = page.getByTestId('uptime-monitor-row');
    await expect(rows.first()).toBeVisible();
    await expect(page.getByTestId('uptime-monitors-table').locator('thead')).toHaveCSS('position', 'absolute');

    const count = await rows.count();
    expect(count).toBeGreaterThan(0);
    for (let i = 0; i < count; i++) {
      const row = rows.nth(i);
      const status = row.locator('[data-uptime-phone="status"]');
      const title = row.locator('[data-uptime-phone="title"]');
      await expect(status).toBeVisible();
      const statusBox = await status.boundingBox();
      const titleBox = await title.boundingBox();
      expect(statusBox).not.toBeNull();
      expect(titleBox).not.toBeNull();
      // The status badge comes first (above the name) and is never clipped by the right edge.
      expect(statusBox!.y + statusBox!.height).toBeLessThanOrEqual(titleBox!.y + 1);
      expect(statusBox!.x + statusBox!.width).toBeLessThanOrEqual(390);
      // The badge text is whole (not ellipsised): its scroll width fits its box.
      const clipped = await status.locator('span').first().evaluate((el) => el.scrollWidth > el.clientWidth + 1);
      expect(clipped).toBe(false);
      // The meta line sits under the name.
      const meta = row.locator('[data-uptime-phone="meta"]').first();
      const metaBox = await meta.boundingBox();
      expect(metaBox!.y).toBeGreaterThanOrEqual(titleBox!.y + titleBox!.height - 1);
    }

    // MUX-101 (project uptime): the recent checks are stacked too.
    const event = page.getByTestId('uptime-event-row').first();
    if ((await event.count()) > 0) {
      const box = await event.boundingBox();
      expect(box!.x + box!.width).toBeLessThanOrEqual(390);
    }

    expect(await pageOverflow(page)).toBe(0);
    await page.screenshot({ path: testInfo.outputPath('monitors.png'), fullPage: true });
  });

  test('MUX-100 / MUX-101: the monitor page has two figure columns and a check log that fits', async ({ page }, testInfo) => {
    await page.goto(`/uptime/${TENANT}`);
    await page.getByRole('link', { name: OUTAGE_MONITOR, exact: true }).first().click();
    await expect(page).toHaveURL(new RegExp(`/uptime/${TENANT}/monitors/\\d+$`));

    const tiles = page.locator('.uptime-metrics-cards > .uptime-metric-card');
    await expect(tiles).toHaveCount(3);
    const [a, b, c] = await Promise.all([0, 1, 2].map((i) => tiles.nth(i).boundingBox()));
    expect(Math.abs(a!.y - b!.y)).toBeLessThan(1); // first row: two tiles
    expect(c!.y).toBeGreaterThan(a!.y + a!.height - 1); // the third one below, across both columns
    for (const box of [a, b, c]) {
      expect(box!.x + box!.width).toBeLessThanOrEqual(390);
    }
    // No label spills out of its tile.
    const spill = await page.locator('.uptime-metric-label').evaluateAll((labels) =>
      labels.some((label) => label.scrollWidth > label.clientWidth + 1),
    );
    expect(spill).toBe(false);

    const log = page.getByTestId('uptime-monitor-log');
    const table = log.locator('table[data-events-table]');
    const logBox = await log.boundingBox();
    const tableBox = await table.boundingBox();
    expect(tableBox!.width).toBeLessThanOrEqual(logBox!.width);
    await expect(table.locator('thead')).toHaveCSS('position', 'absolute');
    const scroller = log.locator('.uptime-table-scroll');
    expect(await scroller.evaluate((el) => el.scrollWidth <= el.clientWidth + 1)).toBe(true);

    expect(await pageOverflow(page)).toBe(0);
    await page.screenshot({ path: testInfo.outputPath('monitor.png'), fullPage: true });
  });
});

test.describe('desktop keeps the tables (1280 x 720)', () => {
  test.use({ viewport: { width: 1280, height: 720 }, isMobile: false, hasTouch: false });

  test('the monitors table and the check log keep their header row', async ({ page }) => {
    if (await openTableDashboard(page)) {
      await expect(page.getByTestId('uptime-monitors-table').locator('thead')).not.toHaveCSS('position', 'absolute');
      await expect(page.getByTestId('uptime-monitor-row').first()).toHaveCSS('display', 'table-row');
    }
    await page.getByRole('link', { name: OUTAGE_MONITOR, exact: true }).first().click();
    const table = page.getByTestId('uptime-monitor-log').locator('table[data-events-table]');
    await expect(table).toHaveCSS('display', 'table');
    const tiles = page.locator('.uptime-metrics-cards > .uptime-metric-card');
    const [a, c] = await Promise.all([tiles.nth(0).boundingBox(), tiles.nth(2).boundingBox()]);
    expect(Math.abs(a!.y - c!.y)).toBeLessThan(1); // three tiles in one row
  });
});
