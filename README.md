# Cal Google Shortcode (WordPress plugin)

Plugin simple para renderizar eventos de un calendario ICS de Google Calendar en el año actual, ya sea en acordeones mensuales o en lista cronológica.

## Shortcode

```text
[cal-google source="https://calendar.google.com/calendar/ical/.../basic.ics" months="all" view="accordion" lang="es"]
```

## Parámetros y defaults

### Atributos del shortcode

- `source` (obligatorio): URL del calendario ICS.
- `months` (opcional, default: `all`):
  - `all`: muestra enero a diciembre del año actual, siempre en ese orden.
  - `current`: oculta los meses ya pasados y muestra desde el mes actual hasta diciembre.
- `view` (opcional, default: `accordion`):
  - `accordion`: renderiza el calendario en acordeones por mes.
  - `list`: renderiza los eventos en lista cronológica.
- `group_by_month` (opcional, default: `yes`, solo relevante en `view="list"`):
  - `yes`: agrupa la lista por mes, manteniendo el orden cronológico dentro de cada bloque.
  - `no`: muestra una única lista cronológica continua, sin encabezados de mes.
- `lang` (opcional, default: `es`):
  - `es`: textos y meses en español.
  - `en`: textos y meses en inglés.
- `bg_color` (opcional, default: `#f7f7f7`): color de fondo para cabeceras de mes.
- `border_color` (opcional, default: `#d9d9d9`): color de borde del contenedor.
- `text_color` (opcional, default: `#222222`): color del texto principal.

### Defaults internos de red y caché

- Timeout HTTP para descargar `source`: `20` segundos.
- Redirecciones HTTP máximas: `3`.
- TTL de caché (transients, tanto feed ICS como payload de descarga de evento): `1` hora.

### Ejemplos

```text
[cal-google source="https://calendar.google.com/calendar/ical/.../basic.ics"]
```

```text
[cal-google source="https://calendar.google.com/calendar/ical/.../basic.ics" view="list"]
```

```text
[cal-google source="https://calendar.google.com/calendar/ical/.../basic.ics" view="list" group_by_month="no" months="current" lang="en"]
```

## Comportamiento

- Descarga la URL `source` por HTTP.
- Parsea eventos `VEVENT` básicos (`SUMMARY`, `DESCRIPTION`, `LOCATION`, `URL`, `DTSTART`, `DTEND`).
- Filtra y muestra solo eventos del año actual.
- Guarda caché por 1 hora usando transients.
- Si el evento tiene propiedad `URL`, se muestra un link para abrirlo en Google Calendar.
