import { createHash } from 'node:crypto';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

/**
 * Deterministic build id: a hash of the TypeScript/CSS sources and pnpm-lock.yaml. It only changes when the code or
 * the dependencies change, so rebuilding unchanged sources (e.g. in `make release-check`) leaves the committed bundle
 * byte-identical (the former wall-clock stamp rewrote it on every build).
 */
function sourceBuildId(dir: string): string {
  const root = new URL(`${dir}/`, import.meta.url);
  const hash = createHash('sha256');
  const files = (readdirSync(root, { recursive: true }) as string[])
    .filter((file) => !file.includes('.test.') && statSync(new URL(file, root)).isFile())
    .sort();
  for (const file of files) {
    hash.update(file).update(readFileSync(new URL(file, root)));
  }
  hash.update(readFileSync(new URL('pnpm-lock.yaml', import.meta.url)));

  return hash.digest('hex').slice(0, 12);
}

const __dirname = dirname(fileURLToPath(import.meta.url));
const assetsRoot = resolve(__dirname, 'src/Resources/assets');
const scssDir = resolve(assetsRoot, 'scss');
const srcDir = resolve(assetsRoot, 'src');

const assetsBuildTime = sourceBuildId('src/Resources/assets');

export default defineConfig({
  define: {
    __UPTIME_ASSETS_BUILD_TIME__: JSON.stringify(assetsBuildTime),
  },
  css: {
    preprocessorOptions: {
      scss: {
        api: 'modern-compiler',
      },
    },
  },
  build: {
    outDir: 'src/Resources/public',
    emptyOutDir: false,
    rollupOptions: {
      input: {
        'uptime-dashboard': resolve(srcDir, 'uptime-dashboard.ts'),
        'uptime-monitor-detail': resolve(srcDir, 'uptime-monitor-detail.ts'),
        'uptime-theme': resolve(scssDir, 'uptime-theme.scss'),
        'uptime-ui-bootstrap': resolve(scssDir, 'uptime-ui-bootstrap.scss'),
        'uptime-ui-tailwind': resolve(scssDir, 'uptime-ui-tailwind.scss'),
      },
      output: {
        entryFileNames: '[name].js',
        assetFileNames: '[name][extname]',
      },
    },
    minify: true,
    sourcemap: false,
  },
});
