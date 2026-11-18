/**
 * Renders [wq_archive view="chart"]: daily min/max/mean temperature as lines, daily rain as bars.
 * Data comes from the canvas' data-rmd-wq-chart attribute (built in PHP).
 */
(function () {
	'use strict';

	function withAlpha(hex, alpha) {
		var value = hex.replace('#', '');
		if (value.length === 3) {
			value = value.replace(/(.)/g, '$1$1');
		}
		var num = parseInt(value, 16);
		return 'rgba(' + ((num >> 16) & 255) + ',' + ((num >> 8) & 255) + ',' + (num & 255) + ',' + alpha + ')';
	}

	function render(canvas) {
		var data;
		try {
			data = JSON.parse(canvas.getAttribute('data-rmd-wq-chart'));
		} catch (err) {
			console.error('[WeatherQuery] Invalid chart data', err);
			return;
		}

		var accent = data.color || '#62C626';
		var rainColor = '#3b82c4';
		var tempMaxColor = '#d9534f';

		new window.Chart(canvas, {
			data: {
				labels: data.labels,
				datasets: [
					{
						type: 'line',
						label: data.strings.tempMax,
						data: data.tempMax,
						borderColor: tempMaxColor,
						backgroundColor: tempMaxColor,
						yAxisID: 'temp',
						tension: 0.3,
						pointRadius: data.labels.length > 60 ? 0 : 2,
						spanGaps: true
					},
					{
						type: 'line',
						label: data.strings.tempAvg,
						data: data.tempAvg,
						borderColor: accent,
						backgroundColor: accent,
						yAxisID: 'temp',
						tension: 0.3,
						pointRadius: data.labels.length > 60 ? 0 : 2,
						spanGaps: true
					},
					{
						type: 'line',
						label: data.strings.tempMin,
						data: data.tempMin,
						borderColor: '#5b8def',
						backgroundColor: '#5b8def',
						yAxisID: 'temp',
						tension: 0.3,
						pointRadius: data.labels.length > 60 ? 0 : 2,
						spanGaps: true
					},
					{
						type: 'bar',
						label: data.strings.rain,
						data: data.rain,
						backgroundColor: withAlpha(rainColor, 0.45),
						borderColor: rainColor,
						borderWidth: 1,
						yAxisID: 'rain',
						order: 10
					}
				]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				plugins: {
					legend: { position: 'bottom' },
					tooltip: {
						callbacks: {
							label: function (ctx) {
								if (ctx.parsed.y === null) {
									return ctx.dataset.label + ': –';
								}
								var unit = ctx.dataset.yAxisID === 'rain' ? ' mm' : ' ' + data.tempUnit;
								return ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString('de-DE', { maximumFractionDigits: 1 }) + unit;
							}
						}
					}
				},
				scales: {
					temp: {
						type: 'linear',
						position: 'left',
						title: { display: true, text: data.strings.temp + ' (' + data.tempUnit + ')' }
					},
					rain: {
						type: 'linear',
						position: 'right',
						beginAtZero: true,
						suggestedMax: 5,
						grid: { drawOnChartArea: false },
						title: { display: true, text: data.strings.rain + ' (mm)' }
					}
				}
			}
		});
	}

	function init() {
		if (!window.Chart) {
			console.error('[WeatherQuery] Chart.js is not loaded');
			return;
		}
		var canvases = document.querySelectorAll('canvas[data-rmd-wq-chart]');
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
