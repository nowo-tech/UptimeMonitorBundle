import { Chart } from 'chart.js/auto';

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

const PALETTE = ['rgb(37, 99, 235)', 'rgb(22, 163, 74)', 'rgb(217, 119, 6)', 'rgb(147, 51, 234)', 'rgb(220, 38, 38)', 'rgb(8, 145, 178)'];

/** Line dataset of one series (gaps where a push carried no value). Exported for tests. */
export function dataset(label: string, data: (number | null)[], index: number): {
  label: string;
  data: (number | null)[];
  borderColor: string;
  backgroundColor: string;
  spanGaps: boolean;
  tension: number;
  pointRadius: number;
} {
  const color = PALETTE[index % PALETTE.length] ?? 'rgb(37, 99, 235)';

  return { label, data, borderColor: color, backgroundColor: color, spanGaps: true, tension: 0.2, pointRadius: 0 };
}

/** The datasets of each chart, leaving out the series with no value at all. Exported for tests. */
export function pushDatasets(series: PushSeries, labels: PushLabels): {
  usage: ReturnType<typeof dataset>[];
  load: ReturnType<typeof dataset>[];
  disks: ReturnType<typeof dataset>[];
} {
  const hasValue = (data: (number | null)[]): boolean => data.some((value) => value !== null);
  const usage = (
    [['cpu', series.cpu], ['memory', series.memory], ['swap', series.swap]] as const
  )
    .filter(([, data]) => hasValue(data))
    .map(([key, data], index) => dataset(labels[key], [...data], index));
  const load = hasValue(series.load) ? [dataset(labels.load, series.load, 3)] : [];
  const disks = Object.entries(series.disks)
    .filter(([, data]) => hasValue(data))
    .map(([mount, data], index) => dataset(mount, data, index));

  return { usage, load, disks };
}

function draw(id: string, labels: string[], datasets: ReturnType<typeof dataset>[], max: number | undefined): void {
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
      scales: { x: { ticks: { maxTicksLimit: 12 } }, y: max === undefined ? { beginAtZero: true } : { min: 0, max } },
    },
  });
}

/** Draws the usage (CPU, memory, swap), load and disk charts of a push monitor page. */
export function renderPushCharts(root: ParentNode): void {
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
  const sets = pushDatasets(series, labels);
  draw('uptime-push-chart-usage', series.labels, sets.usage, 100);
  draw('uptime-push-chart-load', series.labels, sets.load, undefined);
  draw('uptime-push-chart-disks', series.labels, sets.disks, 100);
}
