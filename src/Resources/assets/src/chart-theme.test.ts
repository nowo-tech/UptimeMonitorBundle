import { describe, expect, it } from 'vitest';
import { UPTIME_CHART_COLORS } from './chart-theme';

describe('chart-theme', () => {
  it('exports chart color tokens', () => {
    expect(UPTIME_CHART_COLORS.uptime).toBeTruthy();
    expect(UPTIME_CHART_COLORS.latency).toBeTruthy();
    expect(UPTIME_CHART_COLORS.borderUptime).toBeTruthy();
  });
});
