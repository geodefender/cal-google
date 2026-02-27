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
    /**
     * @return array<int,Event>|WP_Error
     */
    public function get_events_from_source(string $source);
}

interface CalGoogleIcsParserInterface
{
    /**
     * @return array<int,Event>
     */
    public function parse_ics_events(string $ics): array;

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
    /**
     * @param array<int,Event> $events
     */
    public function render_year_accordion(array $events, string $monthsMode, string $lang, string $bgColor, string $borderColor, string $textColor): string;

    /**
     * @param array<int,Event> $events
     */
    public function render_event_list(array $events, string $monthsMode, string $lang, bool $groupByMonth, string $bgColor, string $borderColor, string $textColor): string;
}

final class CalGoogleConfig
{
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
    public function parse_ics_events(string $ics): array
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
                if ($start instanceof DateTimeImmutable) {
                    $end = $this->parse_ics_date($current['DTEND'] ?? null);
                    $events[] = new Event(
                        (string) ($current['SUMMARY'] ?? __('(Sin título)', 'cal-google')),
                        (string) ($current['DESCRIPTION'] ?? ''),
                        (string) ($current['LOCATION'] ?? ''),
                        (string) ($current['URL'] ?? ''),
                        $start,
                        $end,
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
        $decoded = str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $value);
        return trim($decoded);
    }

    public function parse_ics_date(?string $value): ?DateTimeImmutable
    {
        if (! $value) {
            return null;
        }

        $timezone = wp_timezone();
        if (preg_match('/^\d{8}$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('Ymd H:i:s', $value . ' 00:00:00', $timezone);
            return $date ?: null;
        }
        if (preg_match('/^\d{8}T\d{6}Z$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('Ymd\\THis\\Z', $value, new DateTimeZone('UTC'));
            return $date ? $date->setTimezone($timezone) : null;
        }
        if (preg_match('/^\d{8}T\d{6}$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('Ymd\\THis', $value, $timezone);
            return $date ?: null;
        }

        return null;
    }
}

final class IcsFetcher implements CalGoogleIcsFetcherInterface
{
    private CalGoogleIcsParserInterface $parser;

    public function __construct(CalGoogleIcsParserInterface $parser)
    {
        $this->parser = $parser;
    }

    public function get_events_from_source(string $source)
    {
        $cache_key = 'cal_google_' . md5($source);
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
            return new WP_Error('cal_google_request_failed', __('No se pudo descargar el calendario.', 'cal-google'));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return new WP_Error('cal_google_bad_status', __('La URL del calendario devolvió un estado HTTP inválido.', 'cal-google'));
        }

        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            return new WP_Error('cal_google_empty', __('La respuesta del calendario está vacía.', 'cal-google'));
        }

        $events = $this->parser->parse_ics_events($body);
        set_transient($cache_key, $events, CalGoogleConfig::CACHE_TTL_SECONDS);

        return $events;
    }
}

final class CalendarRenderer implements CalGoogleCalendarRendererInterface
{
    /** @var callable */
    private $googleTemplateUrlBuilder;

    /** @var callable */
    private $icsDownloadUrlBuilder;

    public function __construct(callable $googleTemplateUrlBuilder, callable $icsDownloadUrlBuilder)
    {
        $this->googleTemplateUrlBuilder = $googleTemplateUrlBuilder;
        $this->icsDownloadUrlBuilder = $icsDownloadUrlBuilder;
    }

    public function render_year_accordion(array $events, string $monthsMode, string $lang, string $bgColor, string $borderColor, string $textColor): string
    {
        $year = (int) wp_date('Y');
        $currentMonth = (int) wp_date('n');
        $translations = $this->get_translations($lang);
        $monthNames = $this->get_month_names($lang);
        $byMonth = array_fill(1, 12, []);

        foreach ($events as $event) {
            /** @var DateTimeImmutable $start */
            $start = $event->start;
            if ($event->year() !== $year) {
                continue;
            }
            $byMonth[$event->month()][] = $event;
        }

        $monthsToShow = $monthsMode === 'current' ? range($currentMonth, 12) : range(1, 12);
        $uid = wp_unique_id('cal-google-');
        ob_start();
        ?>
        <div class="cal-google" id="<?php echo esc_attr($uid); ?>">
            <style>
                #<?php echo esc_html($uid); ?> .cal-google-month { border: 1px solid #d9d9d9; border-radius: 8px; margin: 0 0 10px; overflow: hidden; }
                #<?php echo esc_html($uid); ?> .cal-google-month { border-color: <?php echo esc_html($borderColor); ?>; }
                #<?php echo esc_html($uid); ?> .cal-google-month > summary { cursor: pointer; padding: 12px 14px; background: <?php echo esc_html($bgColor); ?>; font-weight: 600; color: <?php echo esc_html($textColor); ?>; }
                #<?php echo esc_html($uid); ?> .cal-google-month-content { padding: 10px 14px 14px; }
                #<?php echo esc_html($uid); ?> .cal-google-event { padding: 10px 0; border-bottom: 1px solid #ececec; }
                #<?php echo esc_html($uid); ?> .cal-google-event:last-child { border-bottom: 0; }
                #<?php echo esc_html($uid); ?> .cal-google-event-title { font-weight: 600; margin-bottom: 4px; color: <?php echo esc_html($textColor); ?>; }
                #<?php echo esc_html($uid); ?> .cal-google-event-meta { color: <?php echo esc_html($textColor); ?>; font-size: 0.95em; }
                #<?php echo esc_html($uid); ?> .cal-google-event-description { margin-top: 6px; white-space: pre-line; color: <?php echo esc_html($textColor); ?>; }
                #<?php echo esc_html($uid); ?> .cal-google-empty { color: #777; font-style: italic; }
            </style>
            <?php foreach ($monthsToShow as $month) : ?>
                <details class="cal-google-month" <?php echo $month === $currentMonth ? 'open' : ''; ?>>
                    <summary><?php echo esc_html($monthNames[$month] . ' ' . $year); ?></summary>
                    <div class="cal-google-month-content">
                        <?php if (empty($byMonth[$month])) : ?>
                            <p class="cal-google-empty"><?php echo esc_html($translations['no_events']); ?></p>
                        <?php else : ?>
                            <?php foreach ($byMonth[$month] as $event) : ?>
                                <?php $this->render_event_item($event, $lang, $translations); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function render_event_list(array $events, string $monthsMode, string $lang, bool $groupByMonth, string $bgColor, string $borderColor, string $textColor): string
    {
        $year = (int) wp_date('Y');
        $currentMonth = (int) wp_date('n');
        $translations = $this->get_translations($lang);
        $monthNames = $this->get_month_names($lang);
        $uid = wp_unique_id('cal-google-');
        $monthsToShow = $monthsMode === 'current' ? range($currentMonth, 12) : range(1, 12);
        $byMonth = [];
        foreach ($events as $event) {
            /** @var DateTimeImmutable $start */
            $start = $event->start;
            $month = $event->month();
            if (! isset($byMonth[$month])) {
                $byMonth[$month] = [];
            }
            $byMonth[$month][] = $event;
        }

        ob_start();
        ?>
        <div class="cal-google" id="<?php echo esc_attr($uid); ?>">
            <style>
                #<?php echo esc_html($uid); ?> .cal-google-list { border: 1px solid <?php echo esc_html($borderColor); ?>; border-radius: 8px; background: #fff; overflow: hidden; }
                #<?php echo esc_html($uid); ?> .cal-google-list-month { margin: 0; }
                #<?php echo esc_html($uid); ?> .cal-google-list-month-title { margin: 0; padding: 12px 14px; background: <?php echo esc_html($bgColor); ?>; color: <?php echo esc_html($textColor); ?>; font-weight: 600; }
                #<?php echo esc_html($uid); ?> .cal-google-list-month-events { padding: 0 14px; }
                #<?php echo esc_html($uid); ?> .cal-google-event { padding: 10px 0; border-bottom: 1px solid #ececec; }
                #<?php echo esc_html($uid); ?> .cal-google-event:last-child { border-bottom: 0; }
                #<?php echo esc_html($uid); ?> .cal-google-event-title { font-weight: 600; margin-bottom: 4px; color: <?php echo esc_html($textColor); ?>; }
                #<?php echo esc_html($uid); ?> .cal-google-event-meta { color: <?php echo esc_html($textColor); ?>; font-size: 0.95em; }
                #<?php echo esc_html($uid); ?> .cal-google-event-description { margin-top: 6px; white-space: pre-line; color: <?php echo esc_html($textColor); ?>; }
                #<?php echo esc_html($uid); ?> .cal-google-empty { padding: 12px 14px; color: #777; font-style: italic; }
            </style>
            <section class="cal-google-list">
                <?php if (empty($events)) : ?>
                    <p class="cal-google-empty"><?php echo esc_html($translations['no_events']); ?></p>
                <?php elseif (! $groupByMonth) : ?>
                    <div class="cal-google-list-month-events">
                        <?php foreach ($events as $event) : ?>
                            <?php $this->render_event_item($event, $lang, $translations); ?>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <?php foreach ($monthsToShow as $month) : ?>
                        <div class="cal-google-list-month">
                            <h3 class="cal-google-list-month-title"><?php echo esc_html($monthNames[$month] . ' ' . $year); ?></h3>
                            <div class="cal-google-list-month-events">
                                <?php if (empty($byMonth[$month])) : ?>
                                    <p class="cal-google-empty"><?php echo esc_html($translations['no_events']); ?></p>
                                <?php else : ?>
                                    <?php foreach ($byMonth[$month] as $event) : ?>
                                        <?php $this->render_event_item($event, $lang, $translations); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_event_item(Event $event, string $lang, array $translations): void
    {
        $when = $this->format_event_when($event, $lang);

        $eventUrl = esc_url($event->url);
        $googleTemplateUrl = esc_url(call_user_func($this->googleTemplateUrlBuilder, $event));
        $icsDownloadUrl = esc_url(call_user_func($this->icsDownloadUrlBuilder, $event));
        ?>
        <article class="cal-google-event">
            <div class="cal-google-event-title"><?php echo esc_html($event->summary); ?></div>
            <div class="cal-google-event-meta"><?php echo esc_html($when); ?></div>
            <?php if ($event->location !== '') : ?>
                <div class="cal-google-event-meta"><?php echo esc_html($translations['location']) . esc_html($event->location); ?></div>
            <?php endif; ?>
            <?php if ($event->description !== '') : ?>
                <div class="cal-google-event-description"><?php echo esc_html($event->description); ?></div>
            <?php endif; ?>
            <?php if ($eventUrl !== '') : ?>
                <div class="cal-google-event-description"><a href="<?php echo $eventUrl; ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($translations['event_link']); ?></a></div>
            <?php endif; ?>
            <?php if ($googleTemplateUrl !== '') : ?>
                <div class="cal-google-event-description"><a href="<?php echo $googleTemplateUrl; ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($translations['google_template_link']); ?></a></div>
            <?php endif; ?>
            <?php if ($icsDownloadUrl !== '') : ?>
                <div class="cal-google-event-description"><a href="<?php echo $icsDownloadUrl; ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($translations['download_ics']); ?></a></div>
            <?php endif; ?>
        </article>
        <?php
    }

    private function get_translations(string $lang): array
    {
        if ($lang === 'en') {
            return ['no_events' => 'No events for this month.', 'location' => 'Location: ', 'event_link' => 'Open in Google Calendar', 'google_template_link' => 'Open Google Calendar template', 'download_ics' => 'Download .ics'];
        }

        return ['no_events' => 'Sin eventos para este mes.', 'location' => 'Ubicación: ', 'event_link' => 'Abrir en Google Calendar', 'google_template_link' => 'Abrir plantilla de Google Calendar', 'download_ics' => 'Descargar .ics'];
    }

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
            return $lang === 'en'
                ? wp_date('m/d/Y', $event->start->getTimestamp())
                : wp_date('d/m/Y', $event->start->getTimestamp());
        }

        $when = $this->format_event_datetime($event->start, $lang);
        if ($event->end instanceof DateTimeImmutable) {
            $when .= ' - ' . $this->format_event_datetime($event->end, $lang);
        }

        return $when;
    }

    private function format_event_datetime(DateTimeImmutable $dateTime, string $lang): string
    {
        if ($lang === 'en') {
            return wp_date('m/d/Y h:i A', $dateTime->getTimestamp());
        }

        return wp_date('d/m/Y H:i', $dateTime->getTimestamp());
    }
}

final class Cal_Google_Shortcode_Plugin
{
    private const SHORTCODE = 'cal-google';
    private const MAX_OCCURRENCES_PER_EVENT = 500;
    private const ICS_QUERY_VAR = 'cal_google_ics';
    private const EVENT_TRANSIENT_PREFIX = 'cal_google_event_';

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

    /**
     * @param array<int,string> $vars
     * @return array<int,string>
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
            wp_die(esc_html__('Identificador de evento inválido.', 'cal-google'));
        }

        $event = get_transient(self::EVENT_TRANSIENT_PREFIX . $eventId);
        if (! is_array($event)) {
            status_header(404);
            nocache_headers();
            wp_die(esc_html__('El evento solicitado no está disponible.', 'cal-google'));
        }

        $ics = $this->build_single_event_ics($event);
        if ($ics === '') {
            status_header(404);
            nocache_headers();
            wp_die(esc_html__('No se pudo generar el archivo ICS.', 'cal-google'));
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
            return '<p>' . esc_html__('No se indicó una URL de calendario en el atributo source.', 'cal-google') . '</p>';
        }

        $source = $validatedAtts['source'];
        $monthsMode = $validatedAtts['months'];
        $view = $validatedAtts['view'];
        $groupByMonth = $validatedAtts['group_by_month'];
        $lang = $validatedAtts['lang'];
        $bgColor = $validatedAtts['bg_color'];
        $borderColor = $validatedAtts['border_color'];
        $textColor = $validatedAtts['text_color'];

        $events = $this->get_events_from_source($source);
        if (is_wp_error($events)) {
            return '<p>' . esc_html($events->get_error_message()) . '</p>';
        }

        $year = (int) wp_date('Y');
        $currentMonth = (int) wp_date('n');
        $filteredEvents = $this->filter_events_by_months_mode(
            $this->expand_events_for_year($events, $year),
            $monthsMode,
            $currentMonth
        );

        if ($view === 'list') {
            return $this->renderer->render_event_list($filteredEvents, $monthsMode, $lang, $groupByMonth, $bgColor, $borderColor, $textColor);
        }

        return $this->renderer->render_year_accordion($filteredEvents, $monthsMode, $lang, $bgColor, $borderColor, $textColor);
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

    private function normalize_language(string $lang): string
    {
        $lang = strtolower(trim($lang));
        return in_array($lang, ['es', 'en'], true) ? $lang : CalGoogleConfig::DEFAULT_LANG;
    }

    private function normalize_view_mode(string $view): string
    {
        $view = strtolower(trim($view));
        return in_array($view, ['accordion', 'list'], true) ? $view : CalGoogleConfig::DEFAULT_VIEW;
    }

    private function normalize_boolean_attribute(string $value, bool $default): bool
    {
        $normalized = strtolower(trim($value));
        $truthy = ['1', 'true', 'yes', 'on', 'si'];
        $falsy = ['0', 'false', 'no', 'off'];

        if (in_array($normalized, $truthy, true)) {
            return true;
        }

        if (in_array($normalized, $falsy, true)) {
            return false;
        }

        return $default;
    }

    private function normalize_color(string $color, string $default): string
    {
        $normalized = sanitize_hex_color(trim($color));
        return is_string($normalized) ? $normalized : $default;
    }

    /**
     * @return array<int,Event>|WP_Error
     */
    private function get_events_from_source(string $source)
    {
        return $this->fetcher->get_events_from_source($source);
    }

    /**
     * @return array<int,Event>
     */
    private function parse_ics_events(string $ics): array
    {
        return $this->parser->parse_ics_events($ics);
    }

    /**
     * @param array<int,string>|string|null $rawValues
     * @return array<int,DateTimeImmutable>
     */
    private function parse_ics_date_list($rawValues): array
    {
        return $this->parser->parse_ics_date_list($rawValues);
    }

    private function decode_ics_text(string $value): string
    {
        return $this->parser->decode_ics_text($value);
    }

    private function parse_ics_date(?string $value): ?DateTimeImmutable
    {
        return $this->parser->parse_ics_date($value);
    }

    /**
     * @param array<int,Event> $events
     */
    private function render_year_accordion(array $events, string $monthsMode, string $lang, string $bgColor, string $borderColor, string $textColor): string
    {
        return $this->renderer->render_year_accordion($events, $monthsMode, $lang, $bgColor, $borderColor, $textColor);
    }

    /**
     * @param array<int,Event> $events
     */
    private function render_event_list(array $events, string $monthsMode, string $lang, bool $groupByMonth, string $bgColor, string $borderColor, string $textColor): string
    {
        return $this->renderer->render_event_list($events, $monthsMode, $lang, $groupByMonth, $bgColor, $borderColor, $textColor);
    }

    /**
         * @param array<string,string> $translations
     */
    private function render_event_item(Event $event, string $lang, array $translations): void
    {
        $when = $this->format_event_when($event, $lang);
        $eventUrl = esc_url($event->url);
        $googleTemplateUrl = esc_url($this->build_google_calendar_template_url($event));
        $icsDownloadUrl = esc_url($this->build_event_ics_download_url($event));
        ?>
        <article class="cal-google-event">
            <div class="cal-google-event-title"><?php echo esc_html($event->summary); ?></div>
            <div class="cal-google-event-meta"><?php echo esc_html($when); ?></div>
            <?php if ($event->location !== '') : ?>
                <div class="cal-google-event-meta"><?php echo esc_html($translations['location']) . esc_html($event->location); ?></div>
            <?php endif; ?>
            <?php if ($event->description !== '') : ?>
                <div class="cal-google-event-description"><?php echo esc_html($event->description); ?></div>
            <?php endif; ?>
            <?php if ($eventUrl !== '') : ?>
                <div class="cal-google-event-description"><a href="<?php echo $eventUrl; ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($translations['event_link']); ?></a></div>
            <?php endif; ?>
            <?php if ($googleTemplateUrl !== '') : ?>
                <div class="cal-google-event-description"><a href="<?php echo $googleTemplateUrl; ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($translations['google_template_link']); ?></a></div>
            <?php endif; ?>
            <?php if ($icsDownloadUrl !== '') : ?>
                <div class="cal-google-event-description"><a href="<?php echo $icsDownloadUrl; ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($translations['download_ics']); ?></a></div>
            <?php endif; ?>
        </article>
        <?php
    }

    private function build_google_calendar_template_url(Event $event): string
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

    private function build_event_ics_download_url(Event $event): string
    {
        $eventId = $this->build_event_identifier($event);
        if ($eventId === '') {
            return '';
        }

        $payload = $this->build_event_download_payload($event);
        if ($payload === []) {
            return '';
        }

        set_transient(self::EVENT_TRANSIENT_PREFIX . $eventId, $payload, CalGoogleConfig::CACHE_TTL_SECONDS);

        return add_query_arg(self::ICS_QUERY_VAR, $eventId, home_url('/'));
    }

    private function build_event_identifier(Event $event): string
    {
        $start = $event->start;
        $uid = $event->uid;

        return sha1($uid . '|' . $start->format('c') . '|' . $event->summary);
    }

    /** @return array<string,mixed> */
    private function build_event_download_payload(Event $event): array
    {
        $start = $event->start;
        $end = $event->end;

        return [
            'uid' => $event->uid,
            'summary' => $event->summary,
            'description' => $event->description,
            'location' => $event->location,
            'start_ts' => $start->getTimestamp(),
            'end_ts' => $end instanceof DateTimeImmutable ? $end->getTimestamp() : null,
        ];
    }

    /**
         */
    private function build_single_event_ics(array $event): string
    {
        if (! is_numeric($event['start_ts'] ?? null)) {
            return '';
        }

        $timezone = wp_timezone();
        $start = (new DateTimeImmutable('@' . (int) $event['start_ts']))->setTimezone($timezone);
        $endTs = is_numeric($event['end_ts'] ?? null) ? (int) $event['end_ts'] : null;
        $end = $endTs !== null ? (new DateTimeImmutable('@' . $endTs))->setTimezone($timezone) : $start->modify('+1 hour');
        if (! ($end instanceof DateTimeImmutable) || $end <= $start) {
            $end = $start->modify('+1 hour');
        }

        $uid = sanitize_text_field((string) ($event['uid'] ?? ''));
        if ($uid === '') {
            $uid = sha1((string) $event['summary'] . '|' . $start->format('c')) . '@cal-google';
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
        $sanitized = wp_strip_all_tags($value);

        return str_replace(
            ["\\", ";", ",", "\r\n", "\r", "\n"],
            ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"],
            $sanitized
        );
    }

    /** @param array<int,Event> $events
     *  @return array<int,Event>
     */
    private function filter_events_by_months_mode(array $events, string $monthsMode, int $currentMonth): array
    {
        if ($monthsMode !== 'current') {
            return $events;
        }

        $filtered = array_filter($events, static function (Event $event) use ($currentMonth): bool {
            return $event->month() >= $currentMonth;
        });

        return array_values($filtered);
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
            $duration = null;
            if ($event->end instanceof DateTimeImmutable) {
                $duration = $event->end->getTimestamp() - $event->start->getTimestamp();
            }

            $isRecurring = $event->rrule !== '';
            if (! $isRecurring) {
                if ($event->year() === $year) {
                    $expanded[] = $event;
                }
                continue;
            }

            $rrule = $this->parse_rrule($event->rrule);
            $occurrences = $this->build_recurrence_occurrences($event, $rrule, $yearStart, $yearEnd);

            foreach ($occurrences as $occurrenceStart) {
                $end = is_int($duration) ? $occurrenceStart->modify(($duration >= 0 ? '+' : '') . $duration . ' seconds') : null;
                $expanded[] = $event->withStartAndEnd($occurrenceStart, $end);
            }
        }

        usort($expanded, static function (Event $a, Event $b): int {
            return $a->start <=> $b->start;
        });

        return $expanded;
    }

    /**
     * @return array<string,string>
     */
    private function parse_rrule(string $rrule): array
    {
        $parsed = [];
        $pairs = array_filter(array_map('trim', explode(';', $rrule)));
        foreach ($pairs as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $parsed[strtoupper($parts[0])] = strtoupper($parts[1]);
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
            if ($exdate instanceof DateTimeImmutable) {
                $exdateMap[$exdate->format('Ymd\THis')] = true;
            }
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
            if (! ($rdate instanceof DateTimeImmutable)) {
                continue;
            }

            $key = $rdate->format('Ymd\THis');
            if ($rdate < $yearStart || $rdate > $yearEnd || isset($exdateMap[$key]) || isset($occurrenceMap[$key])) {
                continue;
            }

            $occurrences[] = $rdate;
            $occurrenceMap[$key] = true;
        }

        usort($occurrences, static function (DateTimeImmutable $a, DateTimeImmutable $b): int {
            return $a <=> $b;
        });

        return $occurrences;
    }

    private function next_recurrence_date(DateTimeImmutable $date, string $freq, int $interval): ?DateTimeImmutable
    {
        if ($freq === 'DAILY') {
            return $date->modify('+' . $interval . ' day');
        }

        if ($freq === 'WEEKLY') {
            return $date->modify('+' . $interval . ' week');
        }

        if ($freq === 'MONTHLY') {
            return $date->modify('+' . $interval . ' month');
        }

        if ($freq === 'YEARLY') {
            return $date->modify('+' . $interval . ' year');
        }

        return null;
    }

    /**
     * @return array<string,string>
     */
    private function get_translations(string $lang): array
    {
        if ($lang === 'en') {
            return [
                'no_events' => 'No events for this month.',
                'location' => 'Location: ',
                'event_link' => 'Open in Google Calendar',
                'google_template_link' => 'Open Google Calendar template',
                'download_ics' => 'Download .ics',
            ];
        }

        return [
            'no_events' => 'Sin eventos para este mes.',
            'location' => 'Ubicación: ',
            'event_link' => 'Abrir en Google Calendar',
            'google_template_link' => 'Abrir plantilla de Google Calendar',
            'download_ics' => 'Descargar .ics',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function get_month_names(string $lang): array
    {
        if ($lang === 'en') {
            return [
                1 => 'January',
                2 => 'February',
                3 => 'March',
                4 => 'April',
                5 => 'May',
                6 => 'June',
                7 => 'July',
                8 => 'August',
                9 => 'September',
                10 => 'October',
                11 => 'November',
                12 => 'December',
            ];
        }

        return [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
        ];
    }

    private function format_event_when(Event $event, string $lang): string
    {
        if ($event->isAllDay()) {
            return $lang === 'en'
                ? wp_date('m/d/Y', $event->start->getTimestamp())
                : wp_date('d/m/Y', $event->start->getTimestamp());
        }

        $when = $this->format_event_datetime($event->start, $lang);
        if ($event->end instanceof DateTimeImmutable) {
            $when .= ' - ' . $this->format_event_datetime($event->end, $lang);
        }

        return $when;
    }

    private function format_event_datetime(DateTimeImmutable $dateTime, string $lang): string
    {
        if ($lang === 'en') {
            return wp_date('m/d/Y h:i A', $dateTime->getTimestamp());
        }

        return wp_date('d/m/Y H:i', $dateTime->getTimestamp());
    }
}

new Cal_Google_Shortcode_Plugin();
