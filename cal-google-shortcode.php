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

    public function __construct()
    {
        add_shortcode(self::SHORTCODE, [$this, 'render_shortcode']);
    }

    public function render_shortcode($atts): string
    {
        $atts = shortcode_atts([
            'source' => '',
            'months' => 'all',
            'lang' => 'es',
        ], $atts, self::SHORTCODE);

        $source = esc_url_raw(trim((string) $atts['source']));
        if (! $source) {
            return '<p>' . esc_html__('No se indicó una URL de calendario en el atributo source.', 'cal-google') . '</p>';
        }

        $monthsMode = $this->normalize_months_mode((string) $atts['months']);
        $lang = $this->normalize_language((string) $atts['lang']);

        $events = $this->get_events_from_source($source);
        if (is_wp_error($events)) {
            return '<p>' . esc_html($events->get_error_message()) . '</p>';
        }

        return $this->render_year_accordion($events, $monthsMode, $lang);
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
            $current[$key] = $this->decode_ics_text(trim($value));
        }

        usort($events, static function (array $a, array $b): int {
            return $a['start'] <=> $b['start'];
        });

        return $events;
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
    private function render_year_accordion(array $events, string $monthsMode, string $lang): string
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
                #<?php echo esc_html($uid); ?> .cal-google-month > summary { cursor: pointer; padding: 12px 14px; background: #f7f7f7; font-weight: 600; }
                #<?php echo esc_html($uid); ?> .cal-google-month-content { padding: 10px 14px 14px; }
                #<?php echo esc_html($uid); ?> .cal-google-event { padding: 10px 0; border-bottom: 1px solid #ececec; }
                #<?php echo esc_html($uid); ?> .cal-google-event:last-child { border-bottom: 0; }
                #<?php echo esc_html($uid); ?> .cal-google-event-title { font-weight: 600; margin-bottom: 4px; }
                #<?php echo esc_html($uid); ?> .cal-google-event-meta { color: #555; font-size: 0.95em; }
                #<?php echo esc_html($uid); ?> .cal-google-event-description { margin-top: 6px; white-space: pre-line; }
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
                                <?php
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
