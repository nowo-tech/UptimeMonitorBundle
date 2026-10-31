/**
 * Default Chart.js colours of the uptime charts, used when the page sets no --uptime-chart-* custom property (the
 * bundle stylesheet sets them on `.uptime-app` from the host --nowo-ui-* tokens).
 */
export const UPTIME_CHART_COLORS = {
  uptime: 'rgba(34, 197, 94, 0.7)',
  latency: 'rgba(37, 99, 235, 0.7)',
  borderUptime: 'rgb(22, 163, 74)',
  borderLatency: 'rgb(29, 78, 216)',
};

/** Default series colours of the multi-series charts (push health). */
export const UPTIME_CHART_SERIES = ['#2563eb', '#16a34a', '#d97706', '#9333ea', '#dc2626', '#0891b2'];

export type ChartPalette = {
  /** Uptime line. */
  uptime: string;
  /** Uptime area under the line. */
  uptimeFill: string;
  /** Latency / response time line and bars. */
  latency: string;
  /** Latency area under the line and bar fill. */
  latencyFill: string;
  /** Grid lines. */
  grid: string;
  /** Tick labels. */
  text: string;
  /** Series colours of the multi-series charts (push health). */
  series: string[];
};

/**
 * A colour with transparency (area fills): hex and rgb() colours become rgba(); any other CSS colour a host token may
 * hold (hsl(), oklch(), a name) goes through color-mix(). Exported for tests.
 */
export function translucent(color: string, alpha: number): string {
  const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(color);
  if (hex !== null) {
    const digits = hex[1].length === 3 ? hex[1].replace(/./g, '$&$&') : hex[1];
    const [r, g, b] = [0, 2, 4].map((offset) => parseInt(digits.slice(offset, offset + 2), 16));

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }
  const rgb = /^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/i.exec(color);
  if (rgb !== null) {
    return `rgba(${rgb[1]}, ${rgb[2]}, ${rgb[3]}, ${alpha})`;
  }

  return `color-mix(in srgb, ${color} ${Math.round(alpha * 100)}%, transparent)`;
}

/**
 * The chart palette of a page: the --uptime-chart-* custom properties that apply to `element` (the page root), each one
 * falling back to the default colour when the property is missing or empty.
 */
export function readChartPalette(element: Element): ChartPalette {
  const style = getComputedStyle(element);
  const read = (name: string, fallback: string): string => {
    const value = style.getPropertyValue(name).trim();

    return value === '' ? fallback : value;
  };
  const uptime = read('--uptime-chart-uptime', UPTIME_CHART_COLORS.borderUptime);
  const latency = read('--uptime-chart-latency', UPTIME_CHART_COLORS.borderLatency);

  return {
    uptime,
    uptimeFill: translucent(uptime, 0.25),
    latency,
    latencyFill: translucent(latency, 0.25),
    grid: read('--uptime-chart-grid', 'rgba(100, 116, 139, 0.2)'),
    text: read('--uptime-chart-text', '#64748b'),
    series: UPTIME_CHART_SERIES.map((fallback, index) => read(`--uptime-chart-series-${index + 1}`, fallback)),
  };
}

/** Axis colours of a Chart.js scale: grid lines and tick labels from the palette. */
export function axisColors(palette: ChartPalette): { grid: { color: string }; ticks: { color: string } } {
  return { grid: { color: palette.grid }, ticks: { color: palette.text } };
}
