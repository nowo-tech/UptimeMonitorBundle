import { test, expect, type Locator, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { OUTAGE_MONITOR, openDashboard } from './helpers';

/**
 * REQ-DEMO-013 — README screenshots in full demo context: app masthead + bundle UI + Symfony web debug
 * toolbar (light theme), with the seeded monitors and 24 h of backfilled history (`make demo-screenshots` reseeds first).
 * Captured as PNG; `make demo-screenshots` converts them to WebP and deletes the PNGs.
 */
test.describe.configure({ mode: 'serial' });

const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

const WIDTH = 1440;
const HEIGHT = 900;

/**
 * Captures the viewport (masthead → bundle UI → fixed web debug toolbar) once the toolbar is rendered.
 * When `until` is given, the viewport grows so that element is fully visible above the toolbar.
 */
async function captureDemoFrame(page: Page, file: string, until?: Locator): Promise<void> {
  const toolbar = page.locator('.sf-toolbar .sf-toolbarreset');
  await expect(toolbar).toBeVisible();
  if (until) {
    const bottom = await until.evaluate((el) => el.getBoundingClientRect().bottom + window.scrollY);
    const toolbarHeight = (await toolbar.boundingBox())?.height ?? 40;
    await page.setViewportSize({ width: WIDTH, height: Math.max(HEIGHT, Math.ceil(bottom + 32 + toolbarHeight)) });
    await expect(toolbar).toBeVisible();
  }
  // Let Chart.js / heartbeat bars finish their entry animation.
  await page.evaluate(() => new Promise((done) => requestAnimationFrame(() => setTimeout(done, 1200))));
  await page.screenshot({ path: resolve(outDir, file) });
}

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('Uptime Monitor screenshots (full demo frame)', () => {
  test.use({ viewport: { width: WIDTH, height: HEIGHT }, colorScheme: 'light' });

  test('dashboard — monitors with heartbeat history and recent checks', async ({ page }) => {
    await openDashboard(page);
    await expect(page.getByRole('heading', { name: 'Recent checks' })).toBeVisible();
    await captureDemoFrame(page, 'dashboard.png');
  });

  test('monitor detail — heartbeat, uptime ratios and response-time chart', async ({ page }) => {
    await openDashboard(page);
    await page.getByRole('link', { name: OUTAGE_MONITOR, exact: true }).first().click();
    await expect(page.getByRole('heading', { name: OUTAGE_MONITOR, exact: true })).toBeVisible();
    const responseChart = page.locator('canvas').first();
    await expect(responseChart).toBeVisible();
    await captureDemoFrame(page, 'monitor-detail.png', responseChart);
  });
});
