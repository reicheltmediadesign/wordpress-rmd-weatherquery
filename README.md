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

## Privacy

The server requests data from `api.weatherlink.com`. The weather icon font is loaded from `cdnjs.cloudflare.com`, so visitors' browsers connect to that CDN. API credentials stay on the server and are never output to the page.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
