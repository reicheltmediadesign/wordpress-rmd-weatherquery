# RMD WeatherQuery

WordPress plugin to show live weather data from Davis weather stations via the [WeatherLink v2 API](https://weatherlink.github.io/v2-api/). Built by [reichelt media.design](https://reicheltmedia.design).

## Features

- Shortcode `[wq]` for current or historic data of a station.
- Shortcode `[wq_header]` for a compact one-line summary (temperature | rain | wind).
- Own weather archive: readings are stored in the database and shown with `[wq_archive]` as daily values, monthly overview, single readings or chart – no WeatherLink Pro subscription needed.
- Shortcode `[wq_chart]` and block for archive charts with series visitors can switch on and off.
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

The shortcode generator in the admin builds the shortcode for you. `mode="historic"` uses the WeatherLink historic API, which requires a Pro subscription; use `[wq_archive]` instead.

### Header line

```
[wq_header]
```

Shows temperature, today's rain and wind speed in one line. Without `station_id` it uses the station set under WeatherQuery → Stationen → Header-Wetter Konfiguration. Attributes: `station_id`, `icon_color`, `temperature_unit`, `separator` (default `|`).

### Weather archive

Every 5 minutes WP-Cron fetches the current conditions of all stations on the account and stores each new reading (WeatherLink Basic delivers one every 15 minutes) in the table `{prefix}rmd_weatherquery_archive`. Live requests from page views are archived as well. Status, last error and a “fetch now” button are on the WeatherQuery → Konfiguration page.

WP-Cron only runs when someone visits the site. For gapless recording, have an external service call `https://example.com/wp-cron.php?doing_wp_cron` every 5 minutes.

```
[wq_archive view="daily" days="30"]
[wq_archive view="chart" days="30"]
[wq_archive view="readings"]
[wq_archive view="monthly"]
```

| Attribute | Values | Default |
| --- | --- | --- |
| `view` | `daily` (one row per day), `monthly`, `readings` (all readings of one day), `chart` (temperature and rain per day) | `daily` |
| `station_id` | WeatherLink station ID | archive station setting, then header station |
| `days` | number of days up to today (`daily`, `chart`) | `30` |
| `start_date`, `end_date` | `YYYY-MM-DD`, instead of `days` | – |
| `date` | `YYYY-MM-DD` for `readings` | today |
| `fields` | fields for `readings`: `temp`, `humidity`, `dew_point`, `pressure`, `wind_speed`, `wind_speed_avg`, `wind_gust`, `wind_dir`, `rainfall` (day total so far), `rain_15_min`, `rain_rate`, `solar_rad`, `uv_index` | `temp,humidity,pressure,wind_speed,wind_gust,wind_dir,rain_15_min` |
| `selectable` | `true` lets visitors pick the period (`daily`, `chart`) or the day (`readings`) | `true` |
| `show_icons`, `icon_color`, `temperature_unit` | as for `[wq]` | |

Daily rain is the day's highest value of the station's own daily rain counter, so missing readings do not distort it.

### Archive chart

```
[wq_chart]
[wq_chart date="2026-11-19" fields="temp,humidity,wind_gust,rain_15_min" visible="temp"]
[wq_chart mode="daily" days="30" fields="temp_max,temp_min,rainfall,pressure" visible="temp_max,temp_min,rainfall"]
```

Above the chart there is one button per series to switch it on and off. Series without any value in the shown period are left out. Each unit (°C, %, hPa, km/h, mm, …) gets its own y axis, which is only shown while one of its series is visible.

| Attribute | Values | Default |
| --- | --- | --- |
| `mode` | `readings` (every reading of one day, x axis = time of day), `daily` (one value per day) | `readings` |
| `fields` | series that can be switched on, in this order. `readings`: `temp`, `dew_point`, `humidity`, `pressure`, `wind_speed`, `wind_speed_avg`, `wind_gust`, `wind_dir`, `rainfall` (day total so far), `rain_15_min`, `rain_rate`, `solar_rad`, `uv_index`. `daily`: `temp_max`, `temp_avg`, `temp_min`, `dew_point`, `humidity`, `pressure`, `wind_speed_avg`, `wind_gust`, `rainfall`, `rain_rate`, `solar_rad`, `uv_index` | `readings`: `temp,dew_point,humidity,pressure,wind_speed,wind_gust,rain_15_min,solar_rad`; `daily`: `temp_max,temp_avg,temp_min,rainfall,humidity,pressure,wind_gust` |
| `visible` | series switched on when the page loads | `readings`: `temp,humidity`; `daily`: `temp_max,temp_avg,temp_min,rainfall` |
| `toggles` | `false` replaces the buttons with the Chart.js legend (also clickable) | `true` |
| `date` | `YYYY-MM-DD` for `readings` | today |
| `days`, `start_date`, `end_date` | period for `daily`, as for `[wq_archive]` | `30` |
| `selectable` | `true` lets visitors pick the day (`readings`) or the period (`daily`) | `true` |
| `height` | chart height in px (200–800) | `360` |
| `station_id`, `temperature_unit` | as for `[wq_archive]` | |

### Block

The **WeatherQuery** block (category Widgets) offers everything the shortcode does in the block sidebar, with a live preview in the editor. The station is picked from a list and the field list shows only the fields the station actually delivers. On top of the shortcode it offers:

- field order and custom labels per field
- heading with selectable level
- fixed number of columns, plain tiles, hidden labels and a tile background colour (widget mode)
- block colours, spacing, font size and wide/full alignment
- caching of the weather data per block (default 5 minutes)
- mode “Archiv” with the same views as `[wq_archive]`

The **WeatherQuery Diagramm** block offers `[wq_chart]` with a checkbox list of series and, per series, whether it is visible when the page loads. The editor preview shows the buttons; the chart itself is drawn on the page.

Requires WordPress 6.3 or newer.

## Privacy

The server requests data from `api.weatherlink.com`. The [Weather Icons](https://erikflowers.github.io/weather-icons/) font and [Chart.js](https://www.chartjs.org/) are bundled with the plugin, so visitors' browsers do not connect to any third-party server. API credentials stay on the server and are never output to the page.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
