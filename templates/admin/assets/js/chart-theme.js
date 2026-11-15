/**
 * Shared Admin chart theme for Chart.js.
 * Series colors come from CSS variables (--chart-1 .. --chart-4: gfh-500, gfh-800, gfh-300, gfh-200)
 * so every chart and KPI sparkline matches the active Admin or organization theme.
 */
export function readChartTheme(root = document.documentElement) {
  const styles = getComputedStyle(root);
  const token = (name, fallback) => styles.getPropertyValue(name).trim() || fallback;

  const series = [
    token('--chart-1', '#3182bd'),
    token('--chart-2', '#08519c'),
    token('--chart-3', '#6baed6'),
    token('--chart-4', '#9ecae1'),
  ];

  return {
    series,
    fillPrimary: token('--chart-1-fill', 'rgba(49, 130, 189, 0.16)'),
    grid: token('--chart-grid', '#d5e3f0'),
    ink: token('--color-ink', '#1a2a3a'),
    muted: token('--color-muted', '#4a5b6c'),
    surface: token('--color-surface', '#ffffff'),
    fontBody: token('--font-body', 'Inter, system-ui, sans-serif'),
  };
}

/** Applies the GFH Admin palette and typography defaults to a Chart.js constructor. */
export function applyChartDefaults(ChartCtor = globalThis.Chart) {
  if (!ChartCtor || !ChartCtor.defaults) {
    return null;
  }
  const theme = readChartTheme();
  ChartCtor.defaults.color = theme.muted;
  ChartCtor.defaults.borderColor = theme.grid;
  ChartCtor.defaults.font.family = theme.fontBody;
  return theme;
}

/**
 * Mounts any declarative <canvas data-chart="..."> elements on the page using Chart.js
 * and the shared four-color series palette (gfh-500, gfh-800, gfh-300, gfh-200).
 */
export function mountDeclarativeCharts(root = document, ChartCtor = globalThis.Chart) {
  if (!ChartCtor) {
    return [];
  }
  const theme = applyChartDefaults(ChartCtor);
  const instances = [];

  for (const canvas of root.querySelectorAll('canvas[data-chart]')) {
    const type = canvas.getAttribute('data-chart') || 'bar';
    const labels = (canvas.getAttribute('data-chart-labels') || '')
      .split('|')
      .map((s) => s.trim())
      .filter(Boolean);
    const rawSeries = (canvas.getAttribute('data-chart-series') || '')
      .split(';')
      .map((s) => s.trim())
      .filter(Boolean);

    const datasets = rawSeries.map((entry, index) => {
      const [labelPart, valuesPart] = entry.includes(':') ? entry.split(':', 2) : [`Series ${index + 1}`, entry];
      const data = valuesPart.split(',').map((v) => Number(v.trim()) || 0);
      const color = theme.series[index % theme.series.length];
      return {
        label: labelPart.trim(),
        data,
        borderColor: color,
        backgroundColor: type === 'line' ? theme.fillPrimary : color,
        borderWidth: 2,
        tension: 0.3,
        fill: type === 'line' && index === 0,
      };
    });

    const isSparkline = canvas.hasAttribute('data-chart-sparkline');
    instances.push(
      new ChartCtor(canvas, {
        type,
        data: { labels, datasets },
        options: isSparkline
          ? {
              responsive: true,
              maintainAspectRatio: false,
              plugins: { legend: { display: false }, tooltip: { enabled: false } },
              scales: { x: { display: false }, y: { display: false } },
              elements: { point: { radius: 0 } },
            }
          : {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: { position: 'bottom', labels: { color: theme.ink, boxWidth: 12 } },
              },
              scales: {
                x: { grid: { display: false }, ticks: { color: theme.muted } },
                y: { grid: { color: theme.grid }, ticks: { color: theme.muted } },
              },
            },
      }),
    );
  }

  return instances;
}
