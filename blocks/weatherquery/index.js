/**
 * WeatherQuery block (editor). Written without a build step against the wp.* globals;
 * the frontend markup is rendered by PHP (RMD_WeatherQuery::render_block).
 */
(function (wp) {
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var apiFetch = wp.apiFetch;
	var addQueryArgs = wp.url.addQueryArgs;
	var ServerSideRender = wp.serverSideRender;
	var be = wp.blockEditor;
	var c = wp.components;

	var TEXT_DOMAIN = 'rmd-weatherquery';

	// Used when the station's live field list cannot be loaded.
	var FALLBACK_FIELDS = [
		{ value: 'temp', label: 'Temperatur' },
		{ value: 'humidity', label: 'Luftfeuchtigkeit' },
		{ value: 'wind_speed', label: 'Windgeschwindigkeit' },
		{ value: 'wind_dir', label: 'Windrichtung' },
		{ value: 'pressure', label: 'Luftdruck' },
		{ value: 'rainfall', label: 'Niederschlag' }
	];

	function useStations() {
		var state = useState({ loading: true, stations: [], error: '' });
		var setState = state[1];
		useEffect(function () {
			apiFetch({ path: '/rmd-weatherquery/v1/stations' })
				.then(function (stations) {
					setState({ loading: false, stations: stations, error: '' });
				})
				.catch(function (err) {
					setState({ loading: false, stations: [], error: err && err.message ? err.message : __('Stationen konnten nicht geladen werden.', TEXT_DOMAIN) });
				});
		}, []);
		return state[0];
	}

	function useFields(stationId) {
		var state = useState({ loading: false, fields: FALLBACK_FIELDS, error: '' });
		var setState = state[1];
		useEffect(function () {
			if (!stationId) {
				setState({ loading: false, fields: FALLBACK_FIELDS, error: '' });
				return;
			}
			setState({ loading: true, fields: FALLBACK_FIELDS, error: '' });
			apiFetch({ path: addQueryArgs('/rmd-weatherquery/v1/fields', { station_id: stationId }) })
				.then(function (fields) {
					setState({ loading: false, fields: fields.length ? fields : FALLBACK_FIELDS, error: '' });
				})
				.catch(function (err) {
					setState({ loading: false, fields: FALLBACK_FIELDS, error: err && err.message ? err.message : '' });
				});
		}, [stationId]);
		return state[0];
	}

	function FieldList(props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var available = props.fields;
		var selected = attributes.fields || [];
		var labels = attributes.labels || {};

		var labelOf = function (value) {
			for (var i = 0; i < available.length; i++) {
				if (available[i].value === value) {
					return available[i].label;
				}
			}
			return value;
		};

		var move = function (index, delta) {
			var next = selected.slice();
			var target = index + delta;
			if (target < 0 || target >= next.length) {
				return;
			}
			var tmp = next[index];
			next[index] = next[target];
			next[target] = tmp;
			setAttributes({ fields: next });
		};

		var toggle = function (value, checked) {
			var next = selected.filter(function (f) { return f !== value; });
			if (checked) {
				next.push(value);
			}
			setAttributes({ fields: next });
		};

		var setLabel = function (value, label) {
			var next = Object.assign({}, labels);
			if (label) {
				next[value] = label;
			} else {
				delete next[value];
			}
			setAttributes({ labels: next });
		};

		var selectedRows = selected.map(function (value, index) {
			return el('div', { key: value, className: 'rmd-wq-field-row', style: { borderBottom: '1px solid #e0e0e0', padding: '8px 0' } },
				el('div', { style: { display: 'flex', alignItems: 'center', gap: '4px' } },
					el('div', { style: { flex: 1 } },
						el(c.CheckboxControl, {
							__nextHasNoMarginBottom: true,
							label: labelOf(value),
							checked: true,
							onChange: function (checked) { toggle(value, checked); }
						})
					),
					el(c.Button, { icon: 'arrow-up-alt2', label: __('Nach oben', TEXT_DOMAIN), size: 'small', disabled: index === 0, onClick: function () { move(index, -1); } }),
					el(c.Button, { icon: 'arrow-down-alt2', label: __('Nach unten', TEXT_DOMAIN), size: 'small', disabled: index === selected.length - 1, onClick: function () { move(index, 1); } })
				),
				el(c.TextControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					placeholder: labelOf(value),
					'aria-label': __('Eigene Beschriftung', TEXT_DOMAIN),
					value: labels[value] || '',
					onChange: function (label) { setLabel(value, label); }
				})
			);
		});

		var otherRows = available
			.filter(function (f) { return selected.indexOf(f.value) === -1; })
			.map(function (f) {
				return el(c.CheckboxControl, {
					key: f.value,
					__nextHasNoMarginBottom: true,
					label: f.label,
					checked: false,
					onChange: function (checked) { toggle(f.value, checked); }
				});
			});

		return el(Fragment, null,
			selectedRows.length
				? el('div', { style: { marginBottom: '16px' } }, selectedRows)
				: el('p', null, __('Noch keine Felder ausgewählt.', TEXT_DOMAIN)),
			otherRows.length ? el('p', { style: { fontWeight: 600, marginTop: '8px' } }, __('Weitere Felder', TEXT_DOMAIN)) : null,
			el('div', { style: { display: 'grid', gap: '8px' } }, otherRows)
		);
	}

	function Edit(props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var stations = useStations();
		var fields = useFields(attributes.stationId);
		var blockProps = be.useBlockProps();
		var isArchive = attributes.mode === 'archive';
		var isWidget = !isArchive && attributes.displayMode === 'widget';
		// Archive views other than single readings have fixed columns.
		var showFieldChoice = !isArchive || attributes.archiveView === 'readings';

		var stationOptions = [{ value: 0, label: __('– Station wählen –', TEXT_DOMAIN) }].concat(
			stations.stations.map(function (s) {
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
				label: __('Modus', TEXT_DOMAIN),
				value: attributes.mode,
				options: [
					{ value: 'current', label: __('Aktuelle Bedingungen', TEXT_DOMAIN) },
					{ value: 'archive', label: __('Archiv (eigene Aufzeichnung)', TEXT_DOMAIN) }
				],
				onChange: function (value) { setAttributes({ mode: value }); }
			}),
			isArchive ? el(Fragment, null,
				el(c.SelectControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __('Ansicht', TEXT_DOMAIN),
					value: attributes.archiveView,
					options: [
						{ value: 'daily', label: __('Tageswerte (Tabelle)', TEXT_DOMAIN) },
						{ value: 'chart', label: __('Diagramm Temperatur/Niederschlag', TEXT_DOMAIN) },
						{ value: 'readings', label: __('Einzelmessungen eines Tages', TEXT_DOMAIN) },
						{ value: 'monthly', label: __('Monatsübersicht', TEXT_DOMAIN) }
					],
					onChange: function (value) { setAttributes({ archiveView: value }); }
				}),
				attributes.archiveView === 'daily' || attributes.archiveView === 'chart' ? el(c.SelectControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __('Zeitraum', TEXT_DOMAIN),
					value: attributes.archiveDays,
					options: [
						{ value: 7, label: __('7 Tage', TEXT_DOMAIN) },
						{ value: 30, label: __('30 Tage', TEXT_DOMAIN) },
						{ value: 90, label: __('90 Tage', TEXT_DOMAIN) },
						{ value: 365, label: __('1 Jahr', TEXT_DOMAIN) }
					],
					onChange: function (value) { setAttributes({ archiveDays: parseInt(value, 10) || 30 }); }
				}) : null,
				attributes.archiveView === 'readings' ? el(c.TextControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					type: 'date',
					label: __('Tag', TEXT_DOMAIN),
					help: __('Leer = heute', TEXT_DOMAIN),
					value: attributes.archiveDate,
					onChange: function (value) { setAttributes({ archiveDate: value || '' }); }
				}) : null,
				attributes.archiveView !== 'monthly' ? el(c.ToggleControl, {
					__nextHasNoMarginBottom: true,
					label: attributes.archiveView === 'readings'
						? __('Besucher können den Tag wählen', TEXT_DOMAIN)
						: __('Besucher können den Zeitraum wählen', TEXT_DOMAIN),
					checked: attributes.archiveSelectable,
					onChange: function (value) { setAttributes({ archiveSelectable: value }); }
				}) : null
			) : null,
			showFieldChoice ? el(c.ToggleControl, {
				__nextHasNoMarginBottom: true,
				label: __('Alle verfügbaren Felder anzeigen', TEXT_DOMAIN),
				checked: attributes.showAllFields,
				onChange: function (value) { setAttributes({ showAllFields: value }); }
			}) : null,
			showFieldChoice && !attributes.showAllFields ? el(Fragment, null,
				fields.loading ? el(c.Spinner) : null,
				fields.error ? el(c.Notice, { status: 'warning', isDismissible: false }, fields.error) : null,
				el('p', { className: 'components-base-control__help' }, __('Reihenfolge per Pfeil ändern, Beschriftung optional überschreiben.', TEXT_DOMAIN)),
				el(FieldList, { attributes: attributes, setAttributes: setAttributes, fields: fields.fields })
			) : null,
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
			!isArchive ? el(c.SelectControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __('Darstellungsart', TEXT_DOMAIN),
				value: attributes.displayMode,
				options: [
					{ value: 'widget', label: __('Widget (Kacheln)', TEXT_DOMAIN) },
					{ value: 'list', label: __('Liste', TEXT_DOMAIN) },
					{ value: 'table', label: __('Tabelle', TEXT_DOMAIN) }
				],
				onChange: function (value) { setAttributes({ displayMode: value }); }
			}) : null,
			isWidget ? el(c.RangeControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __('Spalten (0 = automatisch)', TEXT_DOMAIN),
				value: attributes.columns,
				min: 0,
				max: 6,
				onChange: function (value) { setAttributes({ columns: value || 0 }); }
			}) : null,
			isWidget ? el(c.ToggleControl, {
				__nextHasNoMarginBottom: true,
				label: __('Schlichte Kacheln (ohne Rahmen und Schatten)', TEXT_DOMAIN),
				checked: attributes.plainCards,
				onChange: function (value) { setAttributes({ plainCards: value }); }
			}) : null,
			isWidget ? el(c.ToggleControl, {
				__nextHasNoMarginBottom: true,
				label: __('Beschriftungen ausblenden', TEXT_DOMAIN),
				checked: attributes.hideLabels,
				onChange: function (value) { setAttributes({ hideLabels: value }); }
			}) : null,
			el(c.ToggleControl, {
				__nextHasNoMarginBottom: true,
				label: __('Wetter-Icons anzeigen', TEXT_DOMAIN),
				checked: attributes.showIcons,
				onChange: function (value) { setAttributes({ showIcons: value }); }
			}),
			!isArchive ? el(c.ToggleControl, {
				__nextHasNoMarginBottom: true,
				label: __('Zeitstempel anzeigen', TEXT_DOMAIN),
				checked: attributes.showTimestamp,
				onChange: function (value) { setAttributes({ showTimestamp: value }); }
			}) : null
		);

		var colorSettings = [];
		if (attributes.showIcons) {
			colorSettings.push({
				label: __('Icon-Farbe', TEXT_DOMAIN),
				value: attributes.iconColor,
				onChange: function (value) { setAttributes({ iconColor: value || '#62C626' }); }
			});
		}
		if (isWidget) {
			colorSettings.push({
				label: __('Kachel-Hintergrund', TEXT_DOMAIN),
				value: attributes.cardBackground,
				onChange: function (value) { setAttributes({ cardBackground: value || '' }); }
			});
		}
		var colorPanel = colorSettings.length ? el(be.PanelColorSettings, {
			title: __('WeatherQuery-Farben', TEXT_DOMAIN),
			initialOpen: false,
			colorSettings: colorSettings
		}) : null;

		var advancedPanel = el(c.PanelBody, { title: __('Zwischenspeicher', TEXT_DOMAIN), initialOpen: false },
			el(c.RangeControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __('Wetterdaten zwischenspeichern (Minuten)', TEXT_DOMAIN),
				help: __('0 = bei jedem Seitenaufruf neu abfragen. Die Station sendet je nach Abo alle 1–15 Minuten neue Werte.', TEXT_DOMAIN),
				value: attributes.cacheMinutes,
				min: 0,
				max: 60,
				onChange: function (value) { setAttributes({ cacheMinutes: value || 0 }); }
			})
		);

		return el(Fragment, null,
			el(be.InspectorControls, null, stationPanel, dataPanel, layoutPanel, colorPanel, isArchive ? null : advancedPanel),
			el('div', blockProps,
				el(ServerSideRender, {
					block: 'rmd-weatherquery/weather',
					attributes: attributes,
					skipBlockSupportAttributes: true
				})
			)
		);
	}

	wp.blocks.registerBlockType('rmd-weatherquery/weather', {
		edit: Edit,
		save: function () {
			return null;
		}
	});
})(window.wp);
