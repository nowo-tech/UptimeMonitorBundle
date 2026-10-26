import { expect, type Page } from '@playwright/test';

/** Demo tenant seeded by `make reset-demo` (nowo:uptime:seed-demo + app:uptime:demo-history). */
export const TENANT = 'main';

/** Monitors created by the demo seed + history backfill (local probe targets only, no external network). */
export const SEEDED_MONITORS = ['demo_uptime_ok', 'demo_uptime_flaky', 'Storefront', 'Checkout API', 'Search API'];

/** Monitor whose backfilled history contains a resolved outage (see demo App\Command\DemoHistoryCommand). */
export const OUTAGE_MONITOR = 'Checkout API';

/**
 * Opens the tenant dashboard and waits until the TypeScript dashboard lists every seeded monitor.
 * Selectors use roles and visible text only, so bundle template markup changes do not break them.
 */
export async function openDashboard(page: Page): Promise<void> {
  const response = await page.goto(`/uptime/${TENANT}`);
  expect(response?.status()).toBe(200);
  await expect(page.getByRole('heading', { name: 'Main', exact: true })).toBeVisible();
  for (const name of SEEDED_MONITORS) {
    await expect(page.getByRole('link', { name, exact: true }).first()).toBeVisible();
  }
}
