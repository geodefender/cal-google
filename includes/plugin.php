<?php
final class Cal_Google_Shortcode_Plugin
{
    private const SHORTCODE = 'cal-google';
    private const MAX_OCCURRENCES_PER_EVENT = 500;
    private const ICS_QUERY_VAR = 'cal_google_ics';
    private const EVENT_TRANSIENT_PREFIX = 'cal_google_event_';
    private const STYLE_HANDLE = 'cal-google-shortcode';

    private CalGoogleIcsParserInterface $parser;
    private CalGoogleIcsFetcherInterface $fetcher;
    private CalGoogleCalendarRendererInterface $renderer;

    public function __construct(?CalGoogleIcsFetcherInterface $fetcher = null, ?CalGoogleIcsParserInterface $parser = null, ?CalGoogleCalendarRendererInterface $renderer = null)
    {
        $this->parser = $parser ?? new IcsParser();
        $this->fetcher = $fetcher ?? new IcsFetcher($this->parser);
        $this->renderer = $renderer ?? new CalendarRenderer([$this, 'build_google_calendar_template_url'], [$this, 'build_event_ics_download_url']);

        add_shortcode(self::SHORTCODE, [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'maybe_serve_event_ics']);
    }

    /** @param array<int,string> $vars
     *  @return array<int,string>
     */
    public function register_query_vars(array $vars): array
    {
        $vars[] = self::ICS_QUERY_VAR;
        return $vars;
    }

    public function maybe_serve_event_ics(): void
    {
        $eventId = get_query_var(self::ICS_QUERY_VAR, '');
        if (! is_string($eventId) || $eventId === '') {
            return;
        }

        if (preg_match('/\A[a-f0-9]{40}\z/', $eventId) !== 1) {
            status_header(400);
            nocache_headers();
            wp_die(esc_html__('Identificador de evento inválido.', CalGoogleConfig::UI_TEXT_DOMAIN));
        }

        $event = get_transient(self::EVENT_TRANSIENT_PREFIX . $eventId);
        if (! is_array($event)) {
            status_header(404);
            nocache_headers();
            wp_die(esc_html__('El evento solicitado no está disponible.', CalGoogleConfig::UI_TEXT_DOMAIN));
        }

        $ics = $this->build_single_event_ics($event);
        if ($ics === '') {
            status_header(404);
            nocache_headers();
            wp_die(esc_html__('No se pudo generar el archivo ICS.', CalGoogleConfig::UI_TEXT_DOMAIN));
        }

        nocache_headers();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="event-' . $eventId . '.ics"');
        echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public function render_shortcode($atts): string
    {
        $validatedAtts = $this->validate_shortcode_attributes(is_array($atts) ? $atts : []);
        if ($validatedAtts['source'] === '') {
            return $this->renderer->render_error_message(__('No se indicó una URL de calendario en el atributo source.', CalGoogleConfig::UI_TEXT_DOMAIN));
        }

        $targetRange = $this->build_target_range_for_months_mode($validatedAtts['months']);
        $events = $this->fetcher->get_events_from_source($validatedAtts['source'], $targetRange['start'], $targetRange['end']);
        if (is_wp_error($events)) {
            return $this->renderer->render_error_message($this->human_readable_fetch_error($events, $validatedAtts['lang']));
        }

        $year = (int) wp_date('Y');
        $currentMonth = (int) wp_date('n');
        $filteredEvents = $this->filter_events_by_months_mode($this->expand_events_for_year($events, $year), $validatedAtts['months'], $currentMonth);

        if ($validatedAtts['view'] === 'list') {
            return $this->renderer->render_event_list($filteredEvents, $validatedAtts['months'], $validatedAtts['lang'], $validatedAtts['group_by_month'], $validatedAtts['bg_color'], $validatedAtts['border_color'], $validatedAtts['text_color']);
        }

        return $this->renderer->render_year_accordion($filteredEvents, $validatedAtts['months'], $validatedAtts['lang'], $validatedAtts['bg_color'], $validatedAtts['border_color'], $validatedAtts['text_color']);
    }

    public function enqueue_assets(): void
    {
        $cssPath = dirname(__DIR__) . '/assets/cal-google.css';
        $pluginMainFile = dirname(__DIR__) . '/cal-google-shortcode.php';
        $version = file_exists($cssPath) ? filemtime($cssPath) : false;

        wp_enqueue_style(
            self::STYLE_HANDLE,
            plugins_url('assets/cal-google.css', $pluginMainFile),
            [],
            $version !== false ? (string) $version : '1.0.0'
        );
    }

    /**
     * @param array<string,mixed> $atts
     * @return array{source:string,months:string,view:string,group_by_month:bool,lang:string,bg_color:string,border_color:string,text_color:string}
     */
    private function validate_shortcode_attributes(array $atts): array
    {
        $atts = $this->recover_malformed_shortcode_attributes($atts);
        $atts = shortcode_atts(CalGoogleConfig::shortcode_defaults(), $atts, self::SHORTCODE);

        return [
            'source' => esc_url_raw(trim((string) $atts['source'])),
            'months' => $this->normalize_months_mode((string) $atts['months']),
            'view' => $this->normalize_view_mode((string) $atts['view']),
            'group_by_month' => $this->normalize_boolean_attribute((string) $atts['group_by_month'], true),
            'lang' => $this->normalize_language((string) $atts['lang']),
            'bg_color' => $this->normalize_color((string) $atts['bg_color'], CalGoogleConfig::DEFAULT_BG_COLOR),
            'border_color' => $this->normalize_color((string) $atts['border_color'], CalGoogleConfig::DEFAULT_BORDER_COLOR),
            'text_color' => $this->normalize_color((string) $atts['text_color'], CalGoogleConfig::DEFAULT_TEXT_COLOR),
        ];
    }

    /**
     * @param array<string,mixed> $atts
     * @return array<string,mixed>
     */
    private function recover_malformed_shortcode_attributes(array $atts): array
    {
        $blob = $this->build_shortcode_attribute_blob($atts);

        if (($atts['source'] ?? '') === '' && preg_match('/https:\/\/[^\s"\]]+\.ics(?:\?[^\s"\]]*)?/i', $blob, $match) === 1) {
            $atts['source'] = $match[0];
        }

        foreach (['months', 'view', 'lang', 'bg_color', 'border_color', 'text_color', 'group_by_month'] as $attributeName) {
            if (($atts[$attributeName] ?? '') !== '') {
                continue;
            }

            if (preg_match('/\b' . preg_quote($attributeName, '/') . '\s*=\s*"([^"]+)"/i', $blob, $match) === 1) {
                $atts[$attributeName] = $match[1];
            }
        }

        if (isset($atts['source']) && is_string($atts['source'])) {
            if (preg_match('/https:\/\/[^\s"\]]+\.ics(?:\?[^\s"\]]*)?/i', $atts['source'], $match) === 1) {
                $atts['source'] = $match[0];
            }
        }

        return $atts;
    }

    /** @param array<string,mixed> $atts */
    private function build_shortcode_attribute_blob(array $atts): string
    {
        $parts = [];
        foreach ($atts as $key => $value) {
            $parts[] = (string) $key;
            if (is_scalar($value)) {
                $parts[] = (string) $value;
            }
        }

        return implode(' ', $parts);
    }

    private function normalize_months_mode(string $monthsMode): string
    {
        $monthsMode = strtolower(trim($monthsMode));
        return in_array($monthsMode, ['all', 'current'], true) ? $monthsMode : CalGoogleConfig::DEFAULT_MONTHS;
    }

    /** @return array{start:?DateTimeImmutable,end:?DateTimeImmutable} */
    private function build_target_range_for_months_mode(string $monthsMode): array
    {
        if ($monthsMode === 'all') {
            return ['start' => null, 'end' => null];
        }

        $timezone = wp_timezone();
        $year = (int) wp_date('Y');
        $currentMonth = (int) wp_date('n');

        return [
            'start' => new DateTimeImmutable($year . '-' . str_pad((string) $currentMonth, 2, '0', STR_PAD_LEFT) . '-01 00:00:00', $timezone),
            'end' => new DateTimeImmutable($year . '-12-31 23:59:59', $timezone),
        ];
    }

    private function normalize_view_mode(string $view): string
    {
        $view = strtolower(trim($view));
        return in_array($view, ['accordion', 'list'], true) ? $view : CalGoogleConfig::DEFAULT_VIEW;
    }

    private function normalize_boolean_attribute(string $value, bool $default): bool
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['1', 'yes', 'true', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'no', 'false', 'off'], true)) {
            return false;
        }

        return $default;
    }

    private function normalize_language(string $lang): string
    {
        $lang = strtolower(trim($lang));
        return in_array($lang, ['es', 'en'], true) ? $lang : CalGoogleConfig::DEFAULT_LANG;
    }

    private function normalize_color(string $color, string $default): string
    {
        $color = trim($color);
        if (preg_match('/^#[a-fA-F0-9]{5}$/', $color) === 1) {
            $color .= '0';
        }

        if (preg_match('/^#[a-fA-F0-9]{3}$/', $color) === 1) {
            $color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
        }

        if (preg_match('/^#[a-fA-F0-9]{6}$/', $color) === 1) {
            return strtolower($color);
        }

        return $default;
    }

    private function human_readable_fetch_error(WP_Error $error, string $lang): string
    {
        $code = (string) $error->get_error_code();
        if ($lang === 'en') {
            return match ($code) {
                CalGoogleErrorCodes::REQUEST_FAILED => 'Unable to download calendar feed.',
                CalGoogleErrorCodes::BAD_STATUS => 'Calendar URL returned an invalid HTTP status.',
                CalGoogleErrorCodes::EMPTY_RESPONSE => 'Calendar response is empty.',
                CalGoogleErrorCodes::URL_POLICY_VIOLATION => 'Calendar URL does not comply with the allowed URL policy.',
                default => 'Calendar feed could not be processed.',
            };
        }

        return match ($code) {
            CalGoogleErrorCodes::REQUEST_FAILED => 'No se pudo descargar el calendario.',
            CalGoogleErrorCodes::BAD_STATUS => 'La URL del calendario devolvió un estado HTTP inválido.',
            CalGoogleErrorCodes::EMPTY_RESPONSE => 'La respuesta del calendario está vacía.',
            CalGoogleErrorCodes::URL_POLICY_VIOLATION => 'La URL del calendario no cumple con la política de seguridad permitida.',
            default => 'No se pudo procesar el calendario.',
        };
    }

    /** @param array<int,Event> $events
     *  @return array<int,Event>
     */
    private function filter_events_by_months_mode(array $events, string $monthsMode, int $currentMonth): array
    {
        if ($monthsMode !== 'current') {
            return $events;
        }

        return array_values(array_filter($events, static fn (Event $event): bool => $event->month() >= $currentMonth));
    }

    /** @param array<int,Event> $events
     *  @return array<int,Event>
     */
    private function expand_events_for_year(array $events, int $year): array
    {
        $expanded = [];
        $timezone = wp_timezone();
        $yearStart = new DateTimeImmutable($year . '-01-01 00:00:00', $timezone);
        $yearEnd = new DateTimeImmutable($year . '-12-31 23:59:59', $timezone);

        foreach ($events as $event) {
            $duration = $event->end instanceof DateTimeImmutable ? ($event->end->getTimestamp() - $event->start->getTimestamp()) : null;

            if ($event->rrule === '') {
                if ($event->year() === $year) {
                    $expanded[] = $event;
                }
                continue;
            }

            $rrule = $this->parse_rrule($event->rrule);
            foreach ($this->build_recurrence_occurrences($event, $rrule, $yearStart, $yearEnd) as $occurrenceStart) {
                $end = is_int($duration) ? $occurrenceStart->modify(($duration >= 0 ? '+' : '') . $duration . ' seconds') : null;
                $expanded[] = $event->withStartAndEnd($occurrenceStart, $end);
            }
        }

        usort($expanded, static fn (Event $a, Event $b): int => $a->start <=> $b->start);
        return $expanded;
    }

    /** @return array<string,string> */
    private function parse_rrule(string $rrule): array
    {
        $parsed = [];
        foreach (array_filter(array_map('trim', explode(';', $rrule))) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $parsed[strtoupper($parts[0])] = strtoupper($parts[1]);
            }
        }

        return $parsed;
    }

    /**
     * @param array<string,string> $rrule
     * @return array<int,DateTimeImmutable>
     */
    private function build_recurrence_occurrences(Event $event, array $rrule, DateTimeImmutable $yearStart, DateTimeImmutable $yearEnd): array
    {
        $freq = $rrule['FREQ'] ?? '';
        if (! in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
            return [];
        }

        $interval = max(1, (int) ($rrule['INTERVAL'] ?? 1));
        $countLimit = max(1, (int) ($rrule['COUNT'] ?? self::MAX_OCCURRENCES_PER_EVENT));
        $hardLimit = min(self::MAX_OCCURRENCES_PER_EVENT, $countLimit);
        $until = $this->parser->parse_ics_date($rrule['UNTIL'] ?? null);

        $exdateMap = [];
        foreach ($event->exdate as $exdate) {
            $exdateMap[$exdate->format('Ymd\THis')] = true;
        }

        $occurrences = [];
        $occurrenceMap = [];
        $current = $event->start;
        $generated = 0;

        while ($generated < $hardLimit) {
            if ($until instanceof DateTimeImmutable && $current > $until) {
                break;
            }

            $generated++;
            if ($current > $yearEnd && ! ($until instanceof DateTimeImmutable)) {
                break;
            }

            $key = $current->format('Ymd\THis');
            if (! isset($exdateMap[$key]) && $current >= $yearStart && $current <= $yearEnd) {
                $occurrences[] = $current;
                $occurrenceMap[$key] = true;
            }

            $next = $this->next_recurrence_date($current, $freq, $interval);
            if (! ($next instanceof DateTimeImmutable) || $next <= $current) {
                break;
            }

            $current = $next;
        }

        foreach ($event->rdate as $rdate) {
            $key = $rdate->format('Ymd\THis');
            if ($rdate >= $yearStart && $rdate <= $yearEnd && ! isset($exdateMap[$key]) && ! isset($occurrenceMap[$key])) {
                $occurrences[] = $rdate;
            }
        }

        usort($occurrences, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
        return $occurrences;
    }

    private function next_recurrence_date(DateTimeImmutable $date, string $freq, int $interval): ?DateTimeImmutable
    {
        return match ($freq) {
            'DAILY' => $date->modify('+' . $interval . ' day'),
            'WEEKLY' => $date->modify('+' . $interval . ' week'),
            'MONTHLY' => $date->modify('+' . $interval . ' month'),
            'YEARLY' => $date->modify('+' . $interval . ' year'),
            default => null,
        };
    }

    public function build_google_calendar_template_url(Event $event): string
    {
        $start = $event->start;
        $end = $event->end;
        if (! ($end instanceof DateTimeImmutable) || $end <= $start) {
            $end = $start->modify('+1 hour');
        }

        $params = [
            'action' => 'TEMPLATE',
            'text' => $event->summary,
            'dates' => $start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z') . '/' . $end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            'location' => $event->location,
            'details' => $event->description,
        ];

        return 'https://calendar.google.com/calendar/render?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function build_event_ics_download_url(Event $event): string
    {
        $eventId = sha1($event->uid . '|' . $event->start->format('c') . '|' . $event->summary);
        $payload = [
            'uid' => $event->uid,
            'summary' => $event->summary,
            'description' => $event->description,
            'location' => $event->location,
            'start_ts' => $event->start->getTimestamp(),
            'end_ts' => $event->end instanceof DateTimeImmutable ? $event->end->getTimestamp() : null,
        ];

        set_transient(self::EVENT_TRANSIENT_PREFIX . $eventId, $payload, CalGoogleConfig::CACHE_TTL_SECONDS);
        return add_query_arg(self::ICS_QUERY_VAR, $eventId, home_url('/'));
    }

    /** @param array<string,mixed> $event */
    private function build_single_event_ics(array $event): string
    {
        if (! is_numeric($event['start_ts'] ?? null)) {
            return '';
        }

        $timezone = wp_timezone();
        $start = (new DateTimeImmutable('@' . (int) $event['start_ts']))->setTimezone($timezone);
        $endTs = is_numeric($event['end_ts'] ?? null) ? (int) $event['end_ts'] : null;
        $end = $endTs !== null ? (new DateTimeImmutable('@' . $endTs))->setTimezone($timezone) : $start->modify('+1 hour');
        if ($end <= $start) {
            $end = $start->modify('+1 hour');
        }

        $uid = sanitize_text_field((string) ($event['uid'] ?? ''));
        if ($uid === '') {
            $uid = sha1((string) ($event['summary'] ?? '') . '|' . $start->format('c')) . '@cal-google';
        }

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Cal Google Shortcode//ES',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:' . $this->escape_ics_text($uid),
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            'DTEND:' . $end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            'SUMMARY:' . $this->escape_ics_text((string) ($event['summary'] ?? '')),
            'DESCRIPTION:' . $this->escape_ics_text((string) ($event['description'] ?? '')),
            'LOCATION:' . $this->escape_ics_text((string) ($event['location'] ?? '')),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", $lines) . "\r\n";
    }

    private function escape_ics_text(string $value): string
    {
        return str_replace(["\\", ";", ",", "\r\n", "\r", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"], wp_strip_all_tags($value));
    }
}
