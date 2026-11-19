# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- Shortcode `[wq_chart]` and block “WeatherQuery Diagramm”: chart from the archive with series visitors can switch on and off. `mode="readings"` plots every reading of one day over the time of day, `mode="daily"` one value per day (min/max/mean temperature, rain, humidity, pressure, wind, gusts, solar radiation, UV index). Each unit gets its own y axis, shown only while one of its series is visible.

## [1.4.0] – 2026-11-18

### Added

- Own weather archive: WP-Cron fetches the current conditions of all stations every 5 minutes and stores each new reading in the table `rmd_weatherquery_archive`; live requests from page views are archived as well.
- `[wq_archive]` views from the archive: daily values, monthly overview, single readings of a day and a temperature/rain chart (Chart.js, bundled). Visitors can pick the period or the day.
- Block mode “Archiv” with the same views.
- Archive status box with “fetch now” button on the configuration page.

### Changed

- `[wq_archive]` reads from the plugin's own archive instead of the WeatherLink historic API (which requires a Pro subscription). `sensor_id` still works as an alias for `station_id`.
- The setting “Standard Sensor-ID” is now “Archiv-Station”.
- The block's mode “Historische Daten” (WeatherLink Pro API) is replaced by “Archiv”.

## [1.3.1] – 2026-11-18

### Fixed

- Restored the shortcodes `[wq_header]` and `[wq_archive]`, the “Header-Wetter Konfiguration” box and the “Standard Sensor-ID” setting. They existed in the version deployed before 1.1.0 but had never been committed, so the 1.3.0 update removed them.
- Saving the API configuration no longer drops the header station ID.

## [1.3.0] – 2026-11-18

### Added

- Gutenberg block “WeatherQuery” with live preview: station picker, field selection with order and custom labels, heading, column count, tile styling, block colours/spacing and per-block caching of weather data.
- Station list shows the weather station model (ISS), the gateway and further sensors.
- Wind direction icons point in the direction the wind is blowing.

### Changed

- Weather Icons are bundled with the plugin instead of being loaded from cdnjs.cloudflare.com.
- The first admin submenu entry is now called “Stationen”.
- Requires WordPress 6.3 or newer.

### Fixed

- The cache table is created on update as well, not only on activation; without it nothing was cached.
- Device model was always “Unbekanntes Modell”, because the gateway product number was matched against sensor product numbers.

## [1.2.0] – 2026-10-29

### Added

- Automatic updates from GitHub releases (Dashboard → Updates), via plugin-update-checker and the `update.json` attached to each release.

### Changed

- Plugin name is now “RMD WeatherQuery”.

## [1.1.0] – 2026-10-29

### Changed

- First release on GitHub; plugin header and version constant aligned.
