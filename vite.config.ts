import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

const __dirname = dirname(fileURLToPath(import.meta.url));
const assetsRoot = resolve(__dirname, 'src/Resources/assets');
const scssDir = resolve(assetsRoot, 'scss');
const srcDir = resolve(assetsRoot, 'src');

const assetsBuildTime = new Date().toISOString();

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
