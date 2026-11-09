import { defineConfig, devices } from '@playwright/test';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

/** Resolve demo PORT from sibling .env / .env.example (demo/symfony8). */
function resolvePort(fallback = 8011): number {
  for (const file of ['.env', '.env.example']) {
    const path = resolve(__dirname, '..', file);
    if (!existsSync(path)) {
      continue;
    }
    const m = readFileSync(path, 'utf8').match(/^PORT=(\d+)/m);
    if (m) {
      return Number(m[1]);
    }
  }
  return fallback;
}

const port = Number(process.env.DEMO_PORT || resolvePort());
const baseURL = process.env.DEMO_BASE_URL || `http://127.0.0.1:${port}`;

export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL,
    trace: 'on-first-retry',
    viewport: { width: 1280, height: 720 },
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
      testMatch: /.*\.spec\.ts/,
      testIgnore: /(screenshots|phone)\.spec\.ts/,
    },
    // 1.24 (MUX-21 / 100 / 101): the phone layout at 390 x 844 in Chromium and WebKit (`make test-e2e-phone`).
    {
      name: 'phone-chromium',
      use: { ...devices['Pixel 7'], viewport: { width: 390, height: 844 } },
      testMatch: /phone\.spec\.ts/,
    },
    {
      name: 'phone-webkit',
      use: { ...devices['iPhone 14'], viewport: { width: 390, height: 844 } },
      testMatch: /phone\.spec\.ts/,
    },
    {
      name: 'screenshots',
      use: { ...devices['Desktop Chrome'] },
      testMatch: /screenshots\.spec\.ts/,
    },
  ],
});
