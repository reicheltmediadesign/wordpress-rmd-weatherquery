/**
 * Renders [wq_chart] and the "WeatherQuery Diagramm" block. Data comes from the canvas'
 * data-rmd-wq-weather-chart attribute (built in PHP, values already in display units).
 * Checkboxes with data-rmd-wq-series switch the matching dataset on and off.
 */
(function () {
	'use strict';

	var COMPASS = ['N', 'NNO', 'NO', 'ONO', 'O', 'OSO', 'SO', 'SSO', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];

	function withAlpha(hex, alpha) {
		var value = hex.replace('#', '');
		if (value.length === 3) {
			value = value.replace(/(.)/g, '$1$1');
		}
		var num = parseInt(value, 16);
		return 'rgba(' + ((num >> 16) & 255) + ',' + ((num >> 8) & 255) + ',' + (num & 255) + ',' + alpha + ')';
	}

	function formatNumber(value, decimals) {
		return value.toLocaleString('de-DE', { maximumFractionDigits: decimals === undefined ? 1 : decimals });
	}

	function clock(minutes) {
		var h = Math.floor(minutes / 60);
		var m = Math.round(minutes % 60);
		return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
	}

	function compass(degrees) {
		return COMPASS[Math.round(degrees / 22.5) % 16];
	}

	// Only the first visible y axis draws grid lines, otherwise several grids overlap.
	function updateGrid(chart) {
		var first = true;
		Object.keys(chart.options.scales).forEach(function (id) {
			if (id === 'x') {
				return;
			}
			var visible = chart.data.datasets.some(function (ds, i) {
				return ds.yAxisID === id && chart.isDatasetVisible(i);
			});
			chart.options.scales[id].grid.drawOnChartArea = visible && first;
			if (visible) {
				first = false;
			}
		});
	}

	function render(canvas) {
		var data;
		try {
			data = JSON.parse(canvas.getAttribute('data-rmd-wq-weather-chart'));
		} catch (err) {
			console.error('[WeatherQuery] Invalid chart data', err);
			return;
		}

		var byTime = data.mode === 'readings';
		var points = byTime ? data.x.length : data.labels.length;

		var datasets = data.series.map(function (s) {
			var bar = s.type === 'bar';
			var direction = s.axis === 'dir';
			return {
				type: s.type,
				label: s.label,
				unit: data.axes[s.axis].unit,
				yAxisID: s.axis,
				hidden: !s.visible,
				data: byTime ? s.data.map(function (y, i) { return { x: data.x[i], y: y }; }) : s.data,
				borderColor: s.color,
				backgroundColor: bar ? withAlpha(s.color, 0.45) : s.color,
				borderWidth: bar ? 1 : 2,
				showLine: !direction,
				pointRadius: direction ? 2 : (points > 60 ? 0 : 2),
				tension: 0.3,
				spanGaps: true,
				order: bar ? 10 : 0
			};
		});

		var scales = {
			x: byTime
				? { type: 'linear', min: 0, max: 1440, ticks: { stepSize: 60, callback: function (v) { return clock(v); } } }
				: { type: 'category' }
		};
		Object.keys(data.axes).forEach(function (id) {
			var axis = data.axes[id];
			scales[id] = {
				type: 'linear',
				display: 'auto',
				position: axis.position,
				min: axis.min,
				max: axis.max,
				suggestedMax: axis.suggestedMax,
				beginAtZero: !!axis.beginAtZero,
				grid: { drawOnChartArea: false },
				title: { display: true, text: axis.title + (axis.unit ? ' (' + axis.unit + ')' : '') }
			};
			if (id === 'dir') {
				scales[id].ticks = { stepSize: 90, callback: function (v) { return compass(v); } };
			}
		});

		var chart = new window.Chart(canvas, {
			data: { labels: byTime ? undefined : data.labels, datasets: datasets },
			options: {
				responsive: true,
				maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				scales: scales,
				plugins: {
					legend: {
						display: !!data.legend,
						position: 'bottom',
						onClick: function (e, item, legend) {
							var c = legend.chart;
							c.setDatasetVisibility(item.datasetIndex, !c.isDatasetVisible(item.datasetIndex));
							updateGrid(c);
							c.update();
						}
					},
					tooltip: {
						callbacks: {
							title: function (items) {
								if (!items.length) {
									return '';
								}
								return byTime ? data.strings.clock.replace('%s', clock(items[0].parsed.x)) : items[0].label;
							},
							label: function (ctx) {
								var y = ctx.parsed.y;
								var ds = ctx.dataset;
								if (y === null || isNaN(y)) {
									return ds.label + ': –';
								}
								if (ds.yAxisID === 'dir') {
									return ds.label + ': ' + compass(y) + ' (' + formatNumber(y, 0) + '°)';
								}
								return ds.label + ': ' + formatNumber(y) + (ds.unit ? ' ' + ds.unit : '');
							}
						}
					}
				}
			}
		});
		updateGrid(chart);
		chart.update('none');

		var root = canvas.closest('.rmd-wq-chart');
		var boxes = root ? root.querySelectorAll('input[data-rmd-wq-series]') : [];
		Array.prototype.forEach.call(boxes, function (box) {
			box.addEventListener('change', function () {
				chart.setDatasetVisibility(parseInt(box.getAttribute('data-rmd-wq-series'), 10), box.checked);
				updateGrid(chart);
				chart.update();
			});
		});
	}

	function init() {
		if (!window.Chart) {
			console.error('[WeatherQuery] Chart.js is not loaded');
			return;
		}
		var canvases = document.querySelectorAll('canvas[data-rmd-wq-weather-chart]');
		for (var i = 0; i < canvases.length; i++) {
			render(canvases[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
