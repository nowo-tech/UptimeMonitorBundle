import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'happy-dom',
    include: ['src/Resources/assets/src/**/*.test.ts'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'lcov'],
      include: ['src/Resources/assets/src/**/*.ts'],
      exclude: ['**/*.test.ts'],
    },
  },
});
