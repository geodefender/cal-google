# Cal Google Shortcode (WordPress plugin)

Plugin simple para renderizar eventos de un calendario ICS de Google Calendar en el año actual, ya sea en acordeones mensuales o en lista cronológica.

## Shortcode

```text
[cal-google source="https://calendar.google.com/calendar/ical/.../basic.ics" months="all" view="accordion" lang="es"]
```

### Atributos

- `source` (obligatorio): URL del calendario ICS.
- `months` (opcional):
  - `all` (default): muestra enero a diciembre del año actual, siempre en ese orden.
  - `current`: oculta los meses ya pasados y muestra desde el mes actual hasta diciembre.
- `view` (opcional):
  - `accordion` (default): renderiza el calendario en acordeones por mes.
  - `list`: renderiza los eventos en lista cronológica.
- `group_by_month` (opcional, solo relevante en `view="list"`):
  - `yes` (default): agrupa la lista por mes, manteniendo el orden cronológico dentro de cada bloque.
  - `no`: muestra una única lista cronológica continua, sin encabezados de mes.
- `lang` (opcional):
  - `es` (default): textos y meses en español.
  - `en`: textos y meses en inglés.

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
