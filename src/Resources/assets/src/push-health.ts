import { Chart } from 'chart.js/auto';
import { axisColors, readChartPalette, UPTIME_CHART_SERIES, type ChartPalette } from './chart-theme';

/** Series of the server health view of a push monitor (PushMetricsSeries::series()). */
export type PushSeries = {
  hours: number;
  labels: string[];
  cpu: (number | null)[];
  memory: (number | null)[];
  swap: (number | null)[];
  load: (number | null)[];
  disks: Record<string, (number | null)[]>;
};

export type PushLabels = { cpu: string; memory: string; swap: string; load: string };

/** Line dataset of one series (gaps where a push carried no value), coloured from the palette series. Exported for tests. */
export function dataset(label: string, data: (number | null)[], index: number, colors: string[] = UPTIME_CHART_SERIES): {
  label: string;
  data: (number | null)[];
  borderColor: string;
  backgroundColor: string;
  spanGaps: boolean;
  tension: number;
  pointRadius: number;
} {
  const palette = colors.length > 0 ? colors : UPTIME_CHART_SERIES;
  const color = palette[index % palette.length] ?? '#2563eb';

  return { label, data, borderColor: color, backgroundColor: color, spanGaps: true, tension: 0.2, pointRadius: 0 };
}

/** The datasets of each chart, leaving out the series with no value at all. Exported for tests. */
export function pushDatasets(series: PushSeries, labels: PushLabels, colors: string[] = UPTIME_CHART_SERIES): {
  usage: ReturnType<typeof dataset>[];
  load: ReturnType<typeof dataset>[];
  disks: ReturnType<typeof dataset>[];
} {
  const hasValue = (data: (number | null)[]): boolean => data.some((value) => value !== null);
  const usage = (
    [['cpu', series.cpu], ['memory', series.memory], ['swap', series.swap]] as const
  )
    .filter(([, data]) => hasValue(data))
    .map(([key, data], index) => dataset(labels[key], [...data], index, colors));
  const load = hasValue(series.load) ? [dataset(labels.load, series.load, 3, colors)] : [];
  const disks = Object.entries(series.disks)
    .filter(([, data]) => hasValue(data))
    .map(([mount, data], index) => dataset(mount, data, index, colors));

  return { usage, load, disks };
}

function draw(id: string, labels: string[], datasets: ReturnType<typeof dataset>[], max: number | undefined, palette: ChartPalette): void {
  const canvas = document.getElementById(id);
  if (!(canvas instanceof HTMLCanvasElement) || datasets.length === 0) {
    canvas?.closest('.uptime-chart-box')?.setAttribute('hidden', '');
    return;
  }
  new Chart(canvas, {
    type: 'line',
    data: { labels, datasets },
    options: {
      animation: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { labels: { color: palette.text } } },
      scales: {
        x: { ...axisColors(palette), ticks: { maxTicksLimit: 12, color: palette.text } },
        y: { ...axisColors(palette), ...(max === undefined ? { beginAtZero: true } : { min: 0, max }) },
      },
    },
  });
}

/**
 * Draws the usage (CPU, memory, swap), load and disk charts of a push monitor page, in the page palette
 * (--uptime-chart-* custom properties of the chart box when none is given).
 */
export function renderPushCharts(root: ParentNode, palette?: ChartPalette): void {
  const box = root.querySelector<HTMLElement>('#uptime-push-charts');
  if (box === null) {
    return;
  }
  let series: PushSeries;
  let labels: PushLabels;
  try {
    series = JSON.parse(box.dataset.pushSeries ?? '') as PushSeries;
    labels = JSON.parse(box.dataset.pushLabels ?? '') as PushLabels;
  } catch {
    return;
  }
  const colors = palette ?? readChartPalette(box);
  const sets = pushDatasets(series, labels, colors.series);
  draw('uptime-push-chart-usage', series.labels, sets.usage, 100, colors);
  draw('uptime-push-chart-load', series.labels, sets.load, undefined, colors);
  draw('uptime-push-chart-disks', series.labels, sets.disks, 100, colors);
}
