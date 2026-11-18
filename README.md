# RMD WeatherQuery

WordPress plugin to show live weather data from Davis weather stations via the [WeatherLink v2 API](https://weatherlink.github.io/v2-api/). Built by [reichelt media.design](https://reicheltmedia.design).

## Features

- Shortcode `[wq]` for current or historic data of a station.
- Display as list, table or widget, optionally with weather icons and timestamp.
- Conversion of temperature (°C/°F) and pressure units, German field labels.
- Caching of API responses in its own database table, with refresh and clear in the admin.
- Admin page with connection test, station list and shortcode generator.

The admin interface is in German.

## Installation

1. Download `rmd-weatherquery.zip` from the latest [release](https://github.com/reicheltmediadesign/wordpress-rmd-weatherquery/releases). Do not use the “Source code” downloads – they lack the update library and use a different folder name.
2. In WordPress go to Plugins → Add New → Upload Plugin, choose the zip and activate *RMD WeatherQuery*.
3. Enter the WeatherLink API key and API secret in the plugin configuration (WeatherLink account → Account information → API key v2).

Later releases are offered under Dashboard → Updates like any other plugin.

## Usage

```
[wq mode="current" station_id="12345" fields="temp,humidity" display_mode="table" show_icons="true"]
```

| Attribute | Values | Default |
| --- | --- | --- |
| `mode` | `current`, `historic` | `current` |
| `station_id` | WeatherLink station ID | – (required) |
| `fields` | comma-separated field names | `temp` |
| `display_mode` | `list`, `table`, `widget` | `list` |
| `show_icons` | `true`, `false` | `false` |
| `icon_color` | hex colour | `#62C626` |
| `show_timestamp` | `true`, `false` | `false` |
| `temperature_unit` | `celsius`, `fahrenheit` | `celsius` |
| `show_all_fields` | `true`, `false` | `false` |
| `start_timestamp`, `end_timestamp` | Unix timestamps (for `historic`) | – |

The shortcode generator in the admin builds the shortcode for you.

### Block

The **WeatherQuery** block (category Widgets) offers everything the shortcode does in the block sidebar, with a live preview in the editor. The station is picked from a list and the field list shows only the fields the station actually delivers. On top of the shortcode it offers:

- field order and custom labels per field
- heading with selectable level
- fixed number of columns, plain tiles, hidden labels and a tile background colour (widget mode)
- block colours, spacing, font size and wide/full alignment
- caching of the weather data per block (default 5 minutes)

Requires WordPress 6.3 or newer.

## Privacy

The server requests data from `api.weatherlink.com`. The [Weather Icons](https://erikflowers.github.io/weather-icons/) font is bundled with the plugin, so visitors' browsers do not connect to any third-party server. API credentials stay on the server and are never output to the page.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
