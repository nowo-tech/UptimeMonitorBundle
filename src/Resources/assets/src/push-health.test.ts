import { beforeEach, describe, expect, it, vi } from 'vitest';

const chartMock = vi.hoisted(() => vi.fn());
vi.mock('chart.js/auto', () => ({ Chart: chartMock }));

import { dataset, pushDatasets, renderPushCharts, type PushLabels, type PushSeries } from './push-health';

const labels: PushLabels = { cpu: 'CPU', memory: 'Memory', swap: 'Swap', load: 'Load per core' };

const series: PushSeries = {
  hours: 24,
  labels: ['10:00', '10:01'],
  cpu: [10, 20],
  memory: [50, null],
  swap: [null, null],
  load: [0.5, 0.6],
  disks: { '/': [70, 71], '/srv': [null, null] },
};

function mount(seriesJson: string, labelsJson: string): HTMLElement {
  document.body.innerHTML = `
    <div id="root">
      <div id="uptime-push-charts" data-push-series='${seriesJson}' data-push-labels='${labelsJson}'>
        <div class="uptime-chart-box"><canvas id="uptime-push-chart-usage"></canvas></div>
        <div class="uptime-chart-box"><canvas id="uptime-push-chart-load"></canvas></div>
        <div class="uptime-chart-box"><canvas id="uptime-push-chart-disks"></canvas></div>
      </div>
    </div>`;

  return document.getElementById('root') as HTMLElement;
}

describe('push health charts', () => {
  beforeEach(() => chartMock.mockClear());

  it('builds one dataset per series that has a value, keeping gaps', () => {
    const sets = pushDatasets(series, labels);

    expect(sets.usage.map((set) => set.label)).toEqual(['CPU', 'Memory']);
    expect(sets.usage[1]?.data).toEqual([50, null]);
    expect(sets.load.map((set) => set.label)).toEqual(['Load per core']);
    expect(sets.disks.map((set) => set.label)).toEqual(['/']);
    expect(dataset('x', [1], 7).borderColor).toBe(dataset('y', [1], 1).borderColor);
    expect(dataset('x', [1], 0).spanGaps).toBe(true);
  });

  it('draws the three charts and hides a chart with no data', () => {
    const root = mount(JSON.stringify({ ...series, load: [null, null] }), JSON.stringify(labels));
    renderPushCharts(root);

    expect(chartMock).toHaveBeenCalledTimes(2);
    expect(document.getElementById('uptime-push-chart-load')?.closest('.uptime-chart-box')?.hasAttribute('hidden')).toBe(true);
    const usage = chartMock.mock.calls[0]?.[1] as { options: { scales: { y: { max?: number } } } };
    expect(usage.options.scales.y.max).toBe(100);
  });

  it('draws the load chart without a fixed maximum', () => {
    renderPushCharts(mount(JSON.stringify(series), JSON.stringify(labels)));

    const load = chartMock.mock.calls[1]?.[1] as { options: { scales: { y: { beginAtZero?: boolean; max?: number } } } };
    expect(load.options.scales.y).toEqual({ beginAtZero: true });
  });

  it('does nothing without the chart box or with invalid JSON', () => {
    document.body.innerHTML = '<div id="root"></div>';
    renderPushCharts(document.getElementById('root') as HTMLElement);
    renderPushCharts(mount('{', JSON.stringify(labels)));

    expect(chartMock).not.toHaveBeenCalled();
  });
});
