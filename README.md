# Cal Google Shortcode (WordPress plugin)

Plugin simple para renderizar eventos de un calendario ICS de Google Calendar en acordeones mensuales del año actual.

## Shortcode

```text
[cal-google source="https://calendar.google.com/calendar/ical/.../basic.ics" months="all" lang="es"]
```

### Atributos

- `source` (obligatorio): URL del calendario ICS.
- `months` (opcional):
  - `all` (default): muestra enero a diciembre del año actual, siempre en ese orden.
  - `current`: oculta los meses ya pasados y muestra desde el mes actual hasta diciembre.
- `lang` (opcional):
  - `es` (default): textos y meses en español.
  - `en`: textos y meses en inglés.

## Comportamiento

- Descarga la URL `source` por HTTP.
- Parsea eventos `VEVENT` básicos (`SUMMARY`, `DESCRIPTION`, `LOCATION`, `URL`, `DTSTART`, `DTEND`).
- Filtra y muestra solo eventos del año actual.
- Guarda caché por 1 hora usando transients.
- Si el evento tiene propiedad `URL`, se muestra un link para abrirlo en Google Calendar.
