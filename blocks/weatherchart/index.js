/**
 * WeatherQuery chart block (editor). Written without a build step against the wp.* globals;
 * the frontend markup is rendered by PHP (RMD_WeatherQuery::render_chart_block).
 */
(function (wp) {
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var apiFetch = wp.apiFetch;
	var ServerSideRender = wp.serverSideRender;
	var be = wp.blockEditor;
	var c = wp.components;

	var TEXT_DOMAIN = 'rmd-weatherquery';

	function useRemote(path, initial) {
		var state = useState({ loading: true, data: initial, error: '' });
		var setState = state[1];
		useEffect(function () {
			apiFetch({ path: path })
				.then(function (data) {
					setState({ loading: false, data: data, error: '' });
				})
				.catch(function (err) {
					setState({ loading: false, data: initial, error: err && err.message ? err.message : __('Daten konnten nicht geladen werden.', TEXT_DOMAIN) });
				});
		}, [path]);
		return state[0];
	}

	function SeriesList(props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var selected = attributes.series || [];
		var visible = attributes.visibleSeries || [];

		var toggle = function (value, checked) {
			// Keep the definition order so the toggles on the page follow the list here.
			var next = props.available
				.map(function (s) { return s.value; })
				.filter(function (v) { return v === value ? checked : selected.indexOf(v) !== -1; });
			setAttributes({
				series: next,
				visibleSeries: checked
					? visible
					: visible.filter(function (v) { return v !== value; })
			});
		};

		var toggleVisible = function (value, checked) {
			var next = visible.filter(function (v) { return v !== value; });
			if (checked) {
				next.push(value);
			}
			setAttributes({ visibleSeries: next });
		};

		return el('div', null, props.available.map(function (s) {
			var isSelected = selected.indexOf(s.value) !== -1;
			return el('div', { key: s.value, style: { borderBottom: '1px solid #e0e0e0', padding: '8px 0' } },
				el(c.CheckboxControl, {
					__nextHasNoMarginBottom: true,
					label: s.label,
					checked: isSelected,
					onChange: function (checked) { toggle(s.value, checked); }
				}),
				isSelected ? el('div', { style: { marginTop: '6px', marginLeft: '28px' } },
					el(c.ToggleControl, {
						__nextHasNoMarginBottom: true,
						label: __('Beim Laden sichtbar', TEXT_DOMAIN),
						checked: visible.indexOf(s.value) !== -1,
						onChange: function (checked) { toggleVisible(s.value, checked); }
					})
				) : null
			);
		}));
	}

	function Edit(props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var stations = useRemote('/rmd-weatherquery/v1/stations', []);
		var seriesInfo = useRemote('/rmd-weatherquery/v1/chart-series', null);
		var blockProps = be.useBlockProps();
		var isDaily = attributes.chartMode === 'daily';
		var modeInfo = seriesInfo.data ? seriesInfo.data[attributes.chartMode] : null;

		var stationOptions = [{ value: 0, label: __('– Station wählen –', TEXT_DOMAIN) }].concat(
			stations.data.map(function (s) {
				return { value: s.id, label: s.name + ' (' + s.id + ')' };
			})
		);

		var stationPanel = el(c.PanelBody, { title: __('Station', TEXT_DOMAIN), initialOpen: true },
			stations.loading ? el(c.Spinner) : null,
			stations.error ? el(c.Notice, { status: 'error', isDismissible: false }, stations.error) : null,
			!stations.loading && !stations.error ? el(c.SelectControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __('Wetterstation', TEXT_DOMAIN),
				value: attributes.stationId,
				options: stationOptions,
				onChange: function (value) { setAttributes({ stationId: parseInt(value, 10) || 0 }); }
			}) : null
		);

		var dataPanel = el(c.PanelBody, { title: __('Daten', TEXT_DOMAIN), initialOpen: true },
			el(c.SelectControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __('Ansicht', TEXT_DOMAIN),
				value: attributes.chartMode,
				options: [
					{ value: 'readings', label: __('Einzelmessungen eines Tages', TEXT_DOMAIN) },
					{ value: 'daily', label: __('Tageswerte eines Zeitraums', TEXT_DOMAIN) }
				],
				onChange: function (value) {
					var next = { chartMode: value };
					// Series keys differ between the modes, so start from that mode's defaults.
					if (seriesInfo.data && seriesInfo.data[value]) {
						next.series = seriesInfo.data[value].fields;
						next.visibleSeries = seriesInfo.data[value].visible;
					}
					setAttributes(next);
				}
			}),
			isDaily ? el(c.SelectControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __('Zeitraum', TEXT_DOMAIN),
				value: attributes.days,
				options: [
					{ value: 7, label: __('7 Tage', TEXT_DOMAIN) },
					{ value: 30, label: __('30 Tage', TEXT_DOMAIN) },
					{ value: 90, label: __('90 Tage', TEXT_DOMAIN) },
					{ value: 365, label: __('1 Jahr', TEXT_DOMAIN) }
				],
				onChange: function (value) { setAttributes({ days: parseInt(value, 10) || 30 }); }
			}) : el(c.TextControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				type: 'date',
				label: __('Tag', TEXT_DOMAIN),
				help: __('Leer = heute', TEXT_DOMAIN),
				value: attributes.date,
				onChange: function (value) { setAttributes({ date: value || '' }); }
			}),
			el(c.ToggleControl, {
				__nextHasNoMarginBottom: true,
				label: isDaily
					? __('Besucher können den Zeitraum wählen', TEXT_DOMAIN)
					: __('Besucher können den Tag wählen', TEXT_DOMAIN),
				checked: attributes.selectable,
				onChange: function (value) { setAttributes({ selectable: value }); }
			}),
			el(c.SelectControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __('Temperatureinheit', TEXT_DOMAIN),
				value: attributes.temperatureUnit,
				options: [
					{ value: 'celsius', label: '°C' },
					{ value: 'fahrenheit', label: '°F' }
				],
				onChange: function (value) { setAttributes({ temperatureUnit: value }); }
			})
		);

		var seriesPanel = el(c.PanelBody, { title: __('Messgrößen', TEXT_DOMAIN), initialOpen: true },
			seriesInfo.loading ? el(c.Spinner) : null,
			seriesInfo.error ? el(c.Notice, { status: 'error', isDismissible: false }, seriesInfo.error) : null,
			modeInfo ? el(Fragment, null,
				el('p', { className: 'components-base-control__help' }, __('Messgrößen ohne Werte im gewählten Zeitraum werden auf der Seite ausgelassen.', TEXT_DOMAIN)),
				el(SeriesList, { attributes: attributes, setAttributes: setAttributes, available: modeInfo.series })
			) : null,
			el(c.ToggleControl, {
				__nextHasNoMarginBottom: true,
				label: __('Besucher können Messgrößen ein- und ausblenden', TEXT_DOMAIN),
				help: __('Aus: Chart.js-Legende unter dem Diagramm (ebenfalls anklickbar).', TEXT_DOMAIN),
				checked: attributes.toggles,
				onChange: function (value) { setAttributes({ toggles: value }); }
			})
		);

		var layoutPanel = el(c.PanelBody, { title: __('Darstellung', TEXT_DOMAIN), initialOpen: false },
			el(c.TextControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __('Überschrift', TEXT_DOMAIN),
				value: attributes.title,
				onChange: function (value) { setAttributes({ title: value }); }
			}),
			attributes.title ? el(c.SelectControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __('Überschriften-Ebene', TEXT_DOMAIN),
				value: attributes.titleLevel,
				options: [2, 3, 4, 5, 6].map(function (n) { return { value: n, label: 'H' + n }; }),
				onChange: function (value) { setAttributes({ titleLevel: parseInt(value, 10) }); }
			}) : null,
			el(c.RangeControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __('Höhe des Diagramms (px)', TEXT_DOMAIN),
				value: attributes.height,
				min: 200,
				max: 800,
				step: 20,
				onChange: function (value) { setAttributes({ height: value || 360 }); }
			})
		);

		return el(Fragment, null,
			el(be.InspectorControls, null, stationPanel, dataPanel, seriesPanel, layoutPanel),
			el('div', blockProps,
				el(ServerSideRender, {
					block: 'rmd-weatherquery/chart',
					attributes: attributes,
					skipBlockSupportAttributes: true
				})
			)
		);
	}

	wp.blocks.registerBlockType('rmd-weatherquery/chart', {
		edit: Edit,
		save: function () {
			return null;
		}
	});
})(window.wp);
