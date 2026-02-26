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

final class Cal_Google_Shortcode_Plugin
{
    private const SHORTCODE = 'cal-google';
    private const MAX_OCCURRENCES_PER_EVENT = 500;

    public function __construct()
    {
        add_shortcode(self::SHORTCODE, [$this, 'render_shortcode']);
    }

    public function render_shortcode($atts): string
    {
        $atts = shortcode_atts([
            'source' => '',
            'months' => 'all',
            'view' => 'accordion',
            'group_by_month' => 'yes',
            'lang' => 'es',
            'bg_color' => '#f7f7f7',
            'border_color' => '#d9d9d9',
            'text_color' => '#222222',
        ], $atts, self::SHORTCODE);

        $source = esc_url_raw(trim((string) $atts['source']));
        if (! $source) {
            return '<p>' . esc_html__('No se indicó una URL de calendario en el atributo source.', 'cal-google') . '</p>';
        }

        $monthsMode = $this->normalize_months_mode((string) $atts['months']);
        $view = $this->normalize_view_mode((string) $atts['view']);
        $groupByMonth = $this->normalize_boolean_attribute((string) $atts['group_by_month'], true);
        $lang = $this->normalize_language((string) $atts['lang']);
        $bgColor = $this->normalize_color((string) $atts['bg_color'], '#f7f7f7');
        $borderColor = $this->normalize_color((string) $atts['border_color'], '#d9d9d9');
        $textColor = $this->normalize_color((string) $atts['text_color'], '#222222');

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
            return $this->render_event_list($filteredEvents, $monthsMode, $lang, $groupByMonth, $bgColor, $borderColor, $textColor);
        }

        return $this->render_year_accordion($filteredEvents, $monthsMode, $lang, $bgColor, $borderColor, $textColor);
    }

    private function normalize_months_mode(string $monthsMode): string
    {
        $monthsMode = strtolower(trim($monthsMode));
        return in_array($monthsMode, ['all', 'current'], true) ? $monthsMode : 'all';
    }

    private function normalize_language(string $lang): string
    {
        $lang = strtolower(trim($lang));
        return in_array($lang, ['es', 'en'], true) ? $lang : 'es';
    }

    private function normalize_view_mode(string $view): string
    {
        $view = strtolower(trim($view));
        return in_array($view, ['accordion', 'list'], true) ? $view : 'accordion';
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
     * @return array<int,array<string,mixed>>|WP_Error
     */
    private function get_events_from_source(string $source)
    {
        $cache_key = 'cal_google_' . md5($source);
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get($source, [
            'timeout' => 20,
            'redirection' => 3,
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

        $events = $this->parse_ics_events($body);
        set_transient($cache_key, $events, HOUR_IN_SECONDS);

        return $events;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parse_ics_events(string $ics): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $ics) ?: [];

        // Unfold ICS folded lines: if line starts with space/tab it continues previous line.
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
                    $events[] = [
                        'summary' => $current['SUMMARY'] ?? __('(Sin título)', 'cal-google'),
                        'description' => $current['DESCRIPTION'] ?? '',
                        'location' => $current['LOCATION'] ?? '',
                        'url' => $current['URL'] ?? '',
                        'uid' => $current['UID'] ?? '',
                        'rrule' => $current['RRULE'] ?? '',
                        'exdate' => $this->parse_ics_date_list($current['EXDATE'] ?? []),
                        'rdate' => $this->parse_ics_date_list($current['RDATE'] ?? []),
                        'start' => $start,
                        'end' => $end,
                    ];
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

        usort($events, static function (array $a, array $b): int {
            return $a['start'] <=> $b['start'];
        });

        return $events;
    }

    /**
     * @param array<int,string>|string|null $rawValues
     * @return array<int,DateTimeImmutable>
     */
    private function parse_ics_date_list($rawValues): array
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

    private function decode_ics_text(string $value): string
    {
        $decoded = str_replace(
            ['\\n', '\\N', '\\,', '\\;', '\\\\'],
            ["\n", "\n", ',', ';', '\\'],
            $value
        );

        return trim($decoded);
    }

    private function parse_ics_date(?string $value): ?DateTimeImmutable
    {
        if (! $value) {
            return null;
        }

        $timezone = wp_timezone();

        // Date only (all-day)
        if (preg_match('/^\d{8}$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('Ymd H:i:s', $value . ' 00:00:00', $timezone);
            return $date ?: null;
        }

        // UTC format
        if (preg_match('/^\d{8}T\d{6}Z$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('Ymd\\THis\\Z', $value, new DateTimeZone('UTC'));
            return $date ? $date->setTimezone($timezone) : null;
        }

        // Floating/local format
        if (preg_match('/^\d{8}T\d{6}$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('Ymd\\THis', $value, $timezone);
            return $date ?: null;
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $events
     */
    private function render_year_accordion(array $events, string $monthsMode, string $lang, string $bgColor, string $borderColor, string $textColor): string
    {
        $year = (int) wp_date('Y');
        $currentMonth = (int) wp_date('n');
        $translations = $this->get_translations($lang);
        $monthNames = $this->get_month_names($lang);

        $byMonth = [];
        for ($month = 1; $month <= 12; $month++) {
            $byMonth[$month] = [];
        }

        foreach ($events as $event) {
            /** @var DateTimeImmutable $start */
            $start = $event['start'];
            if ((int) $start->format('Y') !== $year) {
                continue;
            }
            $month = (int) $start->format('n');
            $byMonth[$month][] = $event;
        }

        $monthsToShow = $monthsMode === 'current'
            ? range($currentMonth, 12)
            : range(1, 12);

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
                <?php $monthName = $monthNames[$month]; ?>
                <details class="cal-google-month" <?php echo $month === $currentMonth ? 'open' : ''; ?>>
                    <summary><?php echo esc_html($monthName . ' ' . $year); ?></summary>
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

    /**
     * @param array<int,array<string,mixed>> $events
     */
    private function render_event_list(array $events, string $monthsMode, string $lang, bool $groupByMonth, string $bgColor, string $borderColor, string $textColor): string
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
            $start = $event['start'];
            $month = (int) $start->format('n');
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

    /**
     * @param array<string,mixed> $event
     * @param array<string,string> $translations
     */
    private function render_event_item(array $event, string $lang, array $translations): void
    {
        /** @var DateTimeImmutable $start */
        $start = $event['start'];
        /** @var DateTimeImmutable|null $end */
        $end = $event['end'];
        $when = $this->format_event_datetime($start, $lang);
        if ($end instanceof DateTimeImmutable) {
            $when .= ' - ' . $this->format_event_datetime($end, $lang);
        }
        $eventUrl = esc_url((string) ($event['url'] ?? ''));
        ?>
        <article class="cal-google-event">
            <div class="cal-google-event-title"><?php echo esc_html((string) $event['summary']); ?></div>
            <div class="cal-google-event-meta"><?php echo esc_html($when); ?></div>
            <?php if (! empty($event['location'])) : ?>
                <div class="cal-google-event-meta"><?php echo esc_html($translations['location']) . esc_html((string) $event['location']); ?></div>
            <?php endif; ?>
            <?php if (! empty($event['description'])) : ?>
                <div class="cal-google-event-description"><?php echo esc_html((string) $event['description']); ?></div>
            <?php endif; ?>
            <?php if ($eventUrl !== '') : ?>
                <div class="cal-google-event-description"><a href="<?php echo $eventUrl; ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($translations['event_link']); ?></a></div>
            <?php endif; ?>
        </article>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $events
     * @return array<int,array<string,mixed>>
     */
    private function filter_events_by_months_mode(array $events, string $monthsMode, int $currentMonth): array
    {
        if ($monthsMode !== 'current') {
            return $events;
        }

        $filtered = array_filter($events, static function (array $event) use ($currentMonth): bool {
            if (! (($event['start'] ?? null) instanceof DateTimeImmutable)) {
                return false;
            }

            return (int) $event['start']->format('n') >= $currentMonth;
        });

        return array_values($filtered);
    }

    /**
     * @param array<int,array<string,mixed>> $events
     * @return array<int,array<string,mixed>>
     */
    private function expand_events_for_year(array $events, int $year): array
    {
        $expanded = [];
        $timezone = wp_timezone();
        $yearStart = new DateTimeImmutable($year . '-01-01 00:00:00', $timezone);
        $yearEnd = new DateTimeImmutable($year . '-12-31 23:59:59', $timezone);

        foreach ($events as $event) {
            $duration = null;
            if (($event['end'] ?? null) instanceof DateTimeImmutable && ($event['start'] ?? null) instanceof DateTimeImmutable) {
                $duration = $event['end']->getTimestamp() - $event['start']->getTimestamp();
            }

            $isRecurring = ! empty($event['rrule']) && is_string($event['rrule']);
            if (! $isRecurring) {
                if (($event['start'] ?? null) instanceof DateTimeImmutable && (int) $event['start']->format('Y') === $year) {
                    $expanded[] = $event;
                }
                continue;
            }

            $rrule = $this->parse_rrule((string) $event['rrule']);
            $occurrences = $this->build_recurrence_occurrences($event, $rrule, $yearStart, $yearEnd);

            foreach ($occurrences as $occurrenceStart) {
                $copy = $event;
                $copy['start'] = $occurrenceStart;
                $copy['end'] = is_int($duration) ? $occurrenceStart->modify(($duration >= 0 ? '+' : '') . $duration . ' seconds') : null;
                $expanded[] = $copy;
            }
        }

        usort($expanded, static function (array $a, array $b): int {
            /** @var DateTimeImmutable $startA */
            $startA = $a['start'];
            /** @var DateTimeImmutable $startB */
            $startB = $b['start'];
            return $startA <=> $startB;
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
     * @param array<string,mixed> $event
     * @param array<string,string> $rrule
     * @return array<int,DateTimeImmutable>
     */
    private function build_recurrence_occurrences(array $event, array $rrule, DateTimeImmutable $yearStart, DateTimeImmutable $yearEnd): array
    {
        if (! (($event['start'] ?? null) instanceof DateTimeImmutable)) {
            return [];
        }

        $freq = $rrule['FREQ'] ?? '';
        if (! in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
            return [];
        }

        $interval = max(1, (int) ($rrule['INTERVAL'] ?? 1));
        $countLimit = max(1, (int) ($rrule['COUNT'] ?? self::MAX_OCCURRENCES_PER_EVENT));
        $hardLimit = min(self::MAX_OCCURRENCES_PER_EVENT, $countLimit);
        $until = $this->parse_ics_date($rrule['UNTIL'] ?? null);

        $exdateMap = [];
        foreach (($event['exdate'] ?? []) as $exdate) {
            if ($exdate instanceof DateTimeImmutable) {
                $exdateMap[$exdate->format('Ymd\THis')] = true;
            }
        }

        $occurrences = [];
        $occurrenceMap = [];
        $current = $event['start'];
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

        foreach (($event['rdate'] ?? []) as $rdate) {
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
            ];
        }

        return [
            'no_events' => 'Sin eventos para este mes.',
            'location' => 'Ubicación: ',
            'event_link' => 'Abrir en Google Calendar',
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

    private function format_event_datetime(DateTimeImmutable $dateTime, string $lang): string
    {
        if ($lang === 'en') {
            return wp_date('m/d/Y h:i A', $dateTime->getTimestamp());
        }

        return wp_date('d/m/Y H:i', $dateTime->getTimestamp());
    }
}

new Cal_Google_Shortcode_Plugin();
