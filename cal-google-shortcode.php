<?php
/**
 * Plugin Name: Cal Google Shortcode
 * Description: Renderiza un calendario de Google Calendar (ICS) en acordeones mensuales mediante shortcode.
 * Version: 1.0.0
 * Author: Codex
 */

if (! defined('ABSPATH')) {
    exit;
}

interface CalGoogleIcsFetcherInterface
{
    /** @return array<int,Event>|WP_Error */
    public function get_events_from_source(string $source, ?DateTimeImmutable $rangeStart = null, ?DateTimeImmutable $rangeEnd = null);
}

interface CalGoogleIcsParserInterface
{
    /** @return array<int,Event> */
    public function parse_ics_events(string $ics, ?DateTimeImmutable $rangeStart = null, ?DateTimeImmutable $rangeEnd = null): array;

    /**
     * @param array<int,string>|string|null $rawValues
     * @return array<int,DateTimeImmutable>
     */
    public function parse_ics_date_list($rawValues): array;

    public function decode_ics_text(string $value): string;

    public function parse_ics_date(?string $value): ?DateTimeImmutable;
}

interface CalGoogleCalendarRendererInterface
{
    /** @param array<int,Event> $events */
    public function render_year_accordion(array $events, string $monthsMode, string $lang, string $bgColor, string $borderColor, string $textColor): string;

    /** @param array<int,Event> $events */
    public function render_event_list(array $events, string $monthsMode, string $lang, bool $groupByMonth, string $bgColor, string $borderColor, string $textColor): string;

    public function render_error_message(string $message): string;
}

final class CalGoogleErrorCodes
{
    public const REQUEST_FAILED = 'cal_google_request_failed';
    public const BAD_STATUS = 'cal_google_bad_status';
    public const EMPTY_RESPONSE = 'cal_google_empty_response';
    public const URL_POLICY_VIOLATION = 'cal_google_url_policy_violation';
}

final class CalGoogleConfig
{
    public const OPTION_ALLOWED_DOMAINS = 'cal_google_allowed_domains';
    public const DEFAULT_MONTHS = 'all';
    public const DEFAULT_LANG = 'es';
    public const DEFAULT_VIEW = 'accordion';
    public const DEFAULT_GROUP_BY_MONTH = 'yes';
    public const DEFAULT_BG_COLOR = '#f7f7f7';
    public const DEFAULT_BORDER_COLOR = '#d9d9d9';
    public const DEFAULT_TEXT_COLOR = '#222222';

    public const HTTP_TIMEOUT = 20;
    public const HTTP_REDIRECTION = 3;
    public const CACHE_TTL_SECONDS = HOUR_IN_SECONDS;

    public const UI_TEXT_DOMAIN = 'cal-google';
    public const TEMPLATES_DIR = __DIR__ . '/templates';

    /** @return array<int,string> */
    public static function allowed_domains(): array
    {
        $configured = get_option(self::OPTION_ALLOWED_DOMAINS, '');
        $rawDomains = is_string($configured) ? explode(',', $configured) : [];

        /**
         * Allows admins/developers to define a host whitelist for remote calendar sources.
         *
         * @param array<int,string> $rawDomains
         */
        $rawDomains = apply_filters('cal_google_allowed_domains', $rawDomains);

        $domains = [];
        foreach ($rawDomains as $rawDomain) {
            $domain = strtolower(trim((string) $rawDomain));
            if ($domain === '') {
                continue;
            }

            if (preg_match('/\A[a-z0-9.-]+\z/', $domain) !== 1) {
                continue;
            }

            $domains[] = ltrim($domain, '.');
        }

        return array_values(array_unique($domains));
    }

    /** @return array<string,string> */
    public static function shortcode_defaults(): array
    {
        return [
            'source' => '',
            'months' => self::DEFAULT_MONTHS,
            'view' => self::DEFAULT_VIEW,
            'group_by_month' => self::DEFAULT_GROUP_BY_MONTH,
            'lang' => self::DEFAULT_LANG,
            'bg_color' => self::DEFAULT_BG_COLOR,
            'border_color' => self::DEFAULT_BORDER_COLOR,
            'text_color' => self::DEFAULT_TEXT_COLOR,
        ];
    }
}

final class Event
{
    /** @var array<int,DateTimeImmutable> */
    public array $exdate;

    /** @var array<int,DateTimeImmutable> */
    public array $rdate;

    public function __construct(
        public string $summary,
        public string $description,
        public string $location,
        public string $url,
        public DateTimeImmutable $start,
        public ?DateTimeImmutable $end = null,
        public string $uid = '',
        public string $rrule = '',
        array $exdate = [],
        array $rdate = []
    ) {
        $this->exdate = $exdate;
        $this->rdate = $rdate;
    }

    public function isAllDay(): bool
    {
        if ($this->start->format('His') !== '000000') {
            return false;
        }

        return $this->end === null || $this->end->format('His') === '000000';
    }

    public function month(): int
    {
        return (int) $this->start->format('n');
    }

    public function year(): int
    {
        return (int) $this->start->format('Y');
    }

    public function withStartAndEnd(DateTimeImmutable $start, ?DateTimeImmutable $end): self
    {
        return new self($this->summary, $this->description, $this->location, $this->url, $start, $end, $this->uid, $this->rrule, $this->exdate, $this->rdate);
    }
}

final class IcsParser implements CalGoogleIcsParserInterface
{
    public function parse_ics_events(string $ics, ?DateTimeImmutable $rangeStart = null, ?DateTimeImmutable $rangeEnd = null): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $ics) ?: [];
        $unfolded = [];
        foreach ($lines as $line) {
            if (($line !== '') && isset($line[0]) && ($line[0] === ' ' || $line[0] === "\t") && ! empty($unfolded)) {
                $unfolded[count($unfolded) - 1] .= substr($line, 1);
            } else {
                $unfolded[] = $line;
            }
        }

        $events = [];
        $inEvent = false;
        $current = [];

        foreach ($unfolded as $line) {
            if (trim($line) === 'BEGIN:VEVENT') {
                $inEvent = true;
                $current = [];
                continue;
            }

            if (trim($line) === 'END:VEVENT') {
                $inEvent = false;
                $start = $this->parse_ics_date($current['DTSTART'] ?? null);
                $inRange = $this->is_within_range($start, $rangeStart, $rangeEnd);
                if ($start instanceof DateTimeImmutable && $inRange) {
                    $events[] = new Event(
                        (string) ($current['SUMMARY'] ?? ''),
                        (string) ($current['DESCRIPTION'] ?? ''),
                        (string) ($current['LOCATION'] ?? ''),
                        (string) ($current['URL'] ?? ''),
                        $start,
                        $this->parse_ics_date($current['DTEND'] ?? null),
                        (string) ($current['UID'] ?? ''),
                        (string) ($current['RRULE'] ?? ''),
                        $this->parse_ics_date_list($current['EXDATE'] ?? []),
                        $this->parse_ics_date_list($current['RDATE'] ?? [])
                    );
                }

                $current = [];
                continue;
            }

            if (! $inEvent) {
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$rawKey, $value] = $parts;
            $key = strtoupper(trim(explode(';', $rawKey, 2)[0]));
            $decodedValue = $this->decode_ics_text(trim($value));

            if (in_array($key, ['EXDATE', 'RDATE'], true)) {
                if (! isset($current[$key]) || ! is_array($current[$key])) {
                    $current[$key] = [];
                }
                $current[$key][] = $decodedValue;
                continue;
            }

            $current[$key] = $decodedValue;
        }

        usort($events, static function (Event $a, Event $b): int {
            return $a->start <=> $b->start;
        });

        return $events;
    }

    private function is_within_range(?DateTimeImmutable $date, ?DateTimeImmutable $rangeStart, ?DateTimeImmutable $rangeEnd): bool
    {
        if (! ($date instanceof DateTimeImmutable)) {
            return false;
        }

        if ($rangeStart instanceof DateTimeImmutable && $date < $rangeStart) {
            return false;
        }

        if ($rangeEnd instanceof DateTimeImmutable && $date > $rangeEnd) {
            return false;
        }

        return true;
    }

    public function parse_ics_date_list($rawValues): array
    {
        if (is_string($rawValues)) {
            $rawValues = [$rawValues];
        }
        if (! is_array($rawValues)) {
            return [];
        }

        $dates = [];
        foreach ($rawValues as $rawValue) {
            $items = array_filter(array_map('trim', explode(',', (string) $rawValue)));
            foreach ($items as $item) {
                $date = $this->parse_ics_date($item);
                if ($date instanceof DateTimeImmutable) {
                    $dates[] = $date;
                }
            }
        }

        return $dates;
    }

    public function decode_ics_text(string $value): string
    {
        return trim(str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $value));
    }

    public function parse_ics_date(?string $value): ?DateTimeImmutable
    {
        if (! $value) {
            return null;
        }

        $timezone = wp_timezone();
        if (preg_match('/^\d{8}$/', $value) === 1) {
            return DateTimeImmutable::createFromFormat('Ymd H:i:s', $value . ' 00:00:00', $timezone) ?: null;
        }
        if (preg_match('/^\d{8}T\d{6}Z$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('Ymd\\THis\\Z', $value, new DateTimeZone('UTC'));
            return $date ? $date->setTimezone($timezone) : null;
        }
        if (preg_match('/^\d{8}T\d{6}$/', $value) === 1) {
            return DateTimeImmutable::createFromFormat('Ymd\\THis', $value, $timezone) ?: null;
        }

        return null;
    }
}

final class IcsFetcher implements CalGoogleIcsFetcherInterface
{
    public function __construct(private CalGoogleIcsParserInterface $parser)
    {
    }

    public function get_events_from_source(string $source, ?DateTimeImmutable $rangeStart = null, ?DateTimeImmutable $rangeEnd = null)
    {
        $policyError = $this->validate_source_url_policy($source);
        if ($policyError instanceof WP_Error) {
            return $policyError;
        }

        $cache_key = 'cal_google_' . md5($source . '|' . ($rangeStart?->format('c') ?? 'null') . '|' . ($rangeEnd?->format('c') ?? 'null'));
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get($source, [
            'timeout' => CalGoogleConfig::HTTP_TIMEOUT,
            'redirection' => CalGoogleConfig::HTTP_REDIRECTION,
            'user-agent' => 'WordPress Cal Google Shortcode',
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(CalGoogleErrorCodes::REQUEST_FAILED, CalGoogleErrorCodes::REQUEST_FAILED, ['status' => 0]);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return new WP_Error(CalGoogleErrorCodes::BAD_STATUS, CalGoogleErrorCodes::BAD_STATUS, ['status' => $status]);
        }

        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            return new WP_Error(CalGoogleErrorCodes::EMPTY_RESPONSE, CalGoogleErrorCodes::EMPTY_RESPONSE, ['status' => $status]);
        }

        $events = $this->parser->parse_ics_events($body, $rangeStart, $rangeEnd);

        if (defined('WP_DEBUG') && WP_DEBUG === true && ($rangeStart instanceof DateTimeImmutable || $rangeEnd instanceof DateTimeImmutable)) {
            $allEventsCount = count($this->parser->parse_ics_events($body));
            error_log(sprintf(
                '[cal-google] parse_ics_events range filter: before=%d after=%d start=%s end=%s',
                $allEventsCount,
                count($events),
                $rangeStart?->format('c') ?? 'null',
                $rangeEnd?->format('c') ?? 'null'
            ));
        }

        set_transient($cache_key, $events, CalGoogleConfig::CACHE_TTL_SECONDS);

        return $events;
    }

    private function validate_source_url_policy(string $source): ?WP_Error
    {
        $url = wp_parse_url($source);
        if (! is_array($url)) {
            return $this->url_policy_error('Invalid URL format.');
        }

        $scheme = strtolower((string) ($url['scheme'] ?? ''));
        if ($scheme !== 'https') {
            return $this->url_policy_error('Only HTTPS URLs are allowed.');
        }

        $host = strtolower((string) ($url['host'] ?? ''));
        if ($host === '') {
            return $this->url_policy_error('URL host is required.');
        }

        if ($this->is_internal_hostname($host)) {
            return $this->url_policy_error('Internal hosts are not allowed.');
        }

        if ($this->is_ip_address($host) && ! $this->is_public_ip($host)) {
            return $this->url_policy_error('Private or local IP addresses are not allowed.');
        }

        $allowedDomains = CalGoogleConfig::allowed_domains();
        if (! empty($allowedDomains) && ! $this->host_matches_whitelist($host, $allowedDomains)) {
            return $this->url_policy_error('Host is not in the allowed domains whitelist.');
        }

        foreach ($this->resolve_host_ips($host) as $resolvedIp) {
            if (! $this->is_public_ip($resolvedIp)) {
                return $this->url_policy_error('Host resolves to a private or local IP address.');
            }
        }

        return null;
    }

    private function url_policy_error(string $reason): WP_Error
    {
        return new WP_Error(CalGoogleErrorCodes::URL_POLICY_VIOLATION, $reason, ['status' => 400]);
    }

    private function is_internal_hostname(string $host): bool
    {
        if (in_array($host, ['localhost', 'loopback'], true)) {
            return true;
        }

        return preg_match('/(\\.localhost|\\.local|\\.internal|\\.home|\\.lan)\z/', $host) === 1;
    }

    private function is_ip_address(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    private function is_public_ip(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** @param array<int,string> $allowedDomains */
    private function host_matches_whitelist(string $host, array $allowedDomains): bool
    {
        foreach ($allowedDomains as $allowedDomain) {
            if ($host === $allowedDomain || str_ends_with($host, '.' . $allowedDomain)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int,string> */
    private function resolve_host_ips(string $host): array
    {
        $ips = [];
        $ipv4 = gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }

        $aaaa = dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}

final class CalendarRenderer implements CalGoogleCalendarRendererInterface
{
    private string $templatesDir;

    /** @var callable */
    private $googleTemplateUrlBuilder;

    /** @var callable */
    private $icsDownloadUrlBuilder;

    public function __construct(callable $googleTemplateUrlBuilder, callable $icsDownloadUrlBuilder, ?string $templatesDir = null)
    {
        $this->googleTemplateUrlBuilder = $googleTemplateUrlBuilder;
        $this->icsDownloadUrlBuilder = $icsDownloadUrlBuilder;
        $this->templatesDir = $templatesDir ?? CalGoogleConfig::TEMPLATES_DIR;
    }

    public function render_year_accordion(array $events, string $monthsMode, string $lang, string $bgColor, string $borderColor, string $textColor): string
    {
        $year = (int) wp_date('Y');
        $currentMonth = (int) wp_date('n');
        $monthNames = $this->get_month_names($lang);
        $translations = $this->get_translations($lang);
        $monthsToShow = $monthsMode === 'current' ? range($currentMonth, 12) : range(1, 12);

        $byMonth = array_fill(1, 12, []);
        foreach ($events as $event) {
            if ($event->year() === $year) {
                $byMonth[$event->month()][] = $this->build_event_view_model($event, $lang, $translations);
            }
        }

        return $this->render_template('year-accordion.php', [
            'uid' => wp_unique_id('cal-google-'),
            'year' => $year,
            'currentMonth' => $currentMonth,
            'monthsToShow' => $monthsToShow,
            'monthNames' => $monthNames,
            'byMonth' => $byMonth,
            'translations' => $translations,
            'bgColor' => $bgColor,
            'borderColor' => $borderColor,
            'textColor' => $textColor,
        ]);
    }

    public function render_event_list(array $events, string $monthsMode, string $lang, bool $groupByMonth, string $bgColor, string $borderColor, string $textColor): string
    {
        $year = (int) wp_date('Y');
        $currentMonth = (int) wp_date('n');
        $monthNames = $this->get_month_names($lang);
        $translations = $this->get_translations($lang);
        $monthsToShow = $monthsMode === 'current' ? range($currentMonth, 12) : range(1, 12);

        $eventItems = [];
        $byMonth = [];
        foreach ($events as $event) {
            $item = $this->build_event_view_model($event, $lang, $translations);
            $eventItems[] = $item;
            $byMonth[$event->month()][] = $item;
        }

        return $this->render_template('event-list.php', [
            'uid' => wp_unique_id('cal-google-'),
            'year' => $year,
            'monthsToShow' => $monthsToShow,
            'monthNames' => $monthNames,
            'eventItems' => $eventItems,
            'byMonth' => $byMonth,
            'translations' => $translations,
            'groupByMonth' => $groupByMonth,
            'bgColor' => $bgColor,
            'borderColor' => $borderColor,
            'textColor' => $textColor,
            'renderer' => $this,
        ]);
    }

    public function render_error_message(string $message): string
    {
        return '<p>' . esc_html($message) . '</p>';
    }

    /** @param array<string,mixed> $args */
    public function render_partial(string $template, array $args): string
    {
        return $this->render_template($template, $args);
    }

    /** @return array<string,mixed> */
    private function build_event_view_model(Event $event, string $lang, array $translations): array
    {
        return [
            'summary' => $event->summary,
            'summary_fallback' => $translations['untitled_event'],
            'when' => $this->format_event_when($event, $lang),
            'location' => $event->location,
            'location_label' => $translations['location'],
            'description' => $event->description,
            'event_url' => esc_url($event->url),
            'event_link_label' => $translations['event_link'],
            'google_template_url' => esc_url(call_user_func($this->googleTemplateUrlBuilder, $event)),
            'google_template_link_label' => $translations['google_template_link'],
            'ics_download_url' => esc_url(call_user_func($this->icsDownloadUrlBuilder, $event)),
            'download_ics_label' => $translations['download_ics'],
        ];
    }

    /** @param array<string,mixed> $args */
    private function render_template(string $templateName, array $args): string
    {
        $templatePath = trailingslashit($this->templatesDir) . ltrim($templateName, '/');
        if (! file_exists($templatePath)) {
            return '';
        }

        ob_start();
        extract($args, EXTR_SKIP);
        include $templatePath;
        return (string) ob_get_clean();
    }

    /** @return array<string,string> */
    private function get_translations(string $lang): array
    {
        if ($lang === 'en') {
            return [
                'untitled_event' => 'Untitled event',
                'no_events' => 'No events for this month.',
                'location' => 'Location: ',
                'event_link' => 'Open in Google Calendar',
                'google_template_link' => 'Open Google Calendar template',
                'download_ics' => 'Download .ics',
            ];
        }

        return [
            'untitled_event' => 'Sin título',
            'no_events' => 'Sin eventos para este mes.',
            'location' => 'Ubicación: ',
            'event_link' => 'Abrir en Google Calendar',
            'google_template_link' => 'Abrir plantilla de Google Calendar',
            'download_ics' => 'Descargar .ics',
        ];
    }

    /** @return array<int,string> */
    private function get_month_names(string $lang): array
    {
        if ($lang === 'en') {
            return [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
        }

        return [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
    }

    private function format_event_when(Event $event, string $lang): string
    {
        if ($event->isAllDay()) {
            return $lang === 'en' ? wp_date('m/d/Y', $event->start->getTimestamp()) : wp_date('d/m/Y', $event->start->getTimestamp());
        }

        $when = $this->format_event_datetime($event->start, $lang);
        if ($event->end instanceof DateTimeImmutable) {
            $when .= ' - ' . $this->format_event_datetime($event->end, $lang);
        }

        return $when;
    }

    private function format_event_datetime(DateTimeImmutable $dateTime, string $lang): string
    {
        return $lang === 'en' ? wp_date('m/d/Y h:i A', $dateTime->getTimestamp()) : wp_date('d/m/Y H:i', $dateTime->getTimestamp());
    }
}

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
        $this->enqueue_assets();

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

    private function enqueue_assets(): void
    {
        wp_enqueue_style(
            self::STYLE_HANDLE,
            plugins_url('assets/cal-google.css', __FILE__),
            [],
            (string) filemtime(__DIR__ . '/assets/cal-google.css')
        );
    }

    /**
     * @param array<string,mixed> $atts
     * @return array{source:string,months:string,view:string,group_by_month:bool,lang:string,bg_color:string,border_color:string,text_color:string}
     */
    private function validate_shortcode_attributes(array $atts): array
    {
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

new Cal_Google_Shortcode_Plugin();
