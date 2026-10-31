import { describe, expect, it } from 'vitest';
import { axisColors, readChartPalette, translucent, UPTIME_CHART_COLORS, UPTIME_CHART_SERIES } from './chart-theme';

describe('chart-theme', () => {
  it('exports chart color tokens', () => {
    expect(UPTIME_CHART_COLORS.uptime).toBeTruthy();
    expect(UPTIME_CHART_COLORS.latency).toBeTruthy();
    expect(UPTIME_CHART_COLORS.borderUptime).toBeTruthy();
    expect(UPTIME_CHART_SERIES).toHaveLength(6);
  });

  it('makes any colour translucent', () => {
    expect(translucent('#16a34a', 0.25)).toBe('rgba(22, 163, 74, 0.25)');
    expect(translucent('#fff', 0.5)).toBe('rgba(255, 255, 255, 0.5)');
    expect(translucent('rgb(1, 2, 3)', 0.25)).toBe('rgba(1, 2, 3, 0.25)');
    expect(translucent('rgba(1 2 3 / 50%)', 0.25)).toBe('rgba(1, 2, 3, 0.25)');
    expect(translucent('oklch(0.6 0.2 140)', 0.25)).toBe('color-mix(in srgb, oklch(0.6 0.2 140) 25%, transparent)');
  });

  it('reads the palette from the custom properties, with the defaults when one is missing', () => {
    const element = document.createElement('div');
    element.style.setProperty('--uptime-chart-uptime', '#00ff00');
    element.style.setProperty('--uptime-chart-series-2', 'rebeccapurple');
    element.style.setProperty('--uptime-chart-grid', '   ');
    document.body.append(element);

    const palette = readChartPalette(element);
    expect(palette.uptime).toBe('#00ff00');
    expect(palette.uptimeFill).toBe('rgba(0, 255, 0, 0.25)');
    expect(palette.latency).toBe(UPTIME_CHART_COLORS.borderLatency);
    expect(palette.latencyFill).toBe('rgba(29, 78, 216, 0.25)');
    expect(palette.grid).toBe('rgba(100, 116, 139, 0.2)');
    expect(palette.text).toBe('#64748b');
    expect(palette.series).toEqual(['#2563eb', 'rebeccapurple', '#d97706', '#9333ea', '#dc2626', '#0891b2']);
    expect(axisColors(palette)).toEqual({ grid: { color: palette.grid }, ticks: { color: palette.text } });
    element.remove();
  });
});
