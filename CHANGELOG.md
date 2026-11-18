# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
