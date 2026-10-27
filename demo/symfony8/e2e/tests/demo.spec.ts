import { test, expect } from '@playwright/test';
import { OUTAGE_MONITOR, SEEDED_MONITORS, TENANT, openDashboard } from './helpers';

/**
 * REQ-DEMO-013 happy path against demo/symfony8 (data reset by `make test-e2e` → reset-demo):
 * dashboard boots with the seeded monitors, a monitor can be created and opened, the public status
 * page lists the services and the settings screens render.
 */
test.describe('Uptime Monitor demo', () => {
  test('dashboard lists the seeded monitors with live status and history', async ({ page }) => {
    await openDashboard(page);
    // Page header actions (the demo masthead has links with the same names).
    const content = page.locator('#nowo-ui-content');
    await expect(content.getByRole('link', { name: 'Settings' })).toBeVisible();
    await expect(content.getByRole('link', { name: 'Status page' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Recent checks' })).toBeVisible();
    await expect(page.getByRole('searchbox', { name: 'Search monitors' }).or(page.getByLabel('Search monitors'))).toBeVisible();
  });

  test('creates an HTTP monitor and opens its detail page', async ({ page }) => {
    const name = `E2E probe ${Date.now()}`;
    await page.goto(`/uptime/${TENANT}/monitors/new`);
    await expect(page.getByRole('heading', { name: 'New monitor' })).toBeVisible();
    await page.getByLabel('Monitor type').selectOption('http');
    await page.getByLabel('Friendly name').fill(name);
    await page.getByLabel('URL (HTTP/HTTPS)').fill('http://php/demo/uptime/ok');
    await page.getByRole('button', { name: 'Save' }).click();

    await expect(page).toHaveURL(new RegExp(`/uptime/${TENANT}/?$`));
    await expect(page.getByText(`Monitor "${name}" created.`)).toBeVisible();
    await page.getByRole('link', { name, exact: true }).first().click();
    await expect(page).toHaveURL(new RegExp(`/uptime/${TENANT}/monitors/\\d+$`));
    await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
  });

  test('monitor detail shows the backfilled history of a seeded monitor', async ({ page }) => {
    await openDashboard(page);
    await page.getByRole('link', { name: OUTAGE_MONITOR, exact: true }).first().click();
    await expect(page).toHaveURL(new RegExp(`/uptime/${TENANT}/monitors/\\d+$`));
    await expect(page.getByRole('heading', { name: OUTAGE_MONITOR, exact: true })).toBeVisible();
    await expect(page.locator('canvas').first()).toBeVisible();
  });

  test('public status page lists every service', async ({ page }) => {
    const response = await page.goto(`/status/${TENANT}`);
    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Main', level: 1 })).toBeVisible();
    for (const name of SEEDED_MONITORS) {
      await expect(page.getByText(name, { exact: true })).toBeVisible();
    }
  });

  test('settings screens render and navigate', async ({ page }) => {
    await page.goto(`/uptime/${TENANT}/settings`);
    await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'General' })).toBeVisible();
    for (const section of ['Appearance', 'Monitor history', 'About']) {
      await page.locator('#nowo-ui-content').getByRole('link', { name: section, exact: true }).click();
      await expect(page.getByRole('heading', { name: section })).toBeVisible();
    }
  });
});
