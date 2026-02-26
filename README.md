# Cal Google Shortcode (WordPress plugin)

Plugin simple para renderizar eventos de un calendario ICS de Google Calendar en 12 acordeones (uno por cada mes del año actual).

## Shortcode

```text
[cal-google source="https://calendar.google.com/calendar/ical/.../basic.ics"]
```

## Comportamiento

- Descarga la URL `source` por HTTP.
- Parsea eventos `VEVENT` básicos (`SUMMARY`, `DESCRIPTION`, `LOCATION`, `DTSTART`, `DTEND`).
- Muestra siempre 12 acordeones (enero a diciembre del año actual).
- Filtra y muestra solo eventos del año actual.
- Guarda caché por 1 hora usando transients.
