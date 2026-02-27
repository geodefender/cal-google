<?php
final class CalendarRenderer implements CalGoogleCalendarRendererInterface
{
    private string $templatesDir;

    /** @var callable */
    private $addToCalendarUrlBuilder;

    /** @var callable */
    private $icsDownloadUrlBuilder;

    public function __construct(callable $addToCalendarUrlBuilder, callable $icsDownloadUrlBuilder, ?string $templatesDir = null)
    {
        $this->addToCalendarUrlBuilder = $addToCalendarUrlBuilder;
        $this->icsDownloadUrlBuilder = $icsDownloadUrlBuilder;
        $this->templatesDir = $templatesDir ?? CalGoogleConfig::TEMPLATES_DIR;
    }

    public function render_year_accordion(array $events, string $monthsMode, string $lang, string $bgColor, string $borderColor, string $textColor, bool $showMonthCounter, array $visibleLinks, string $calendarProvider): string
    {
        $year = (int) wp_date('Y');
        $currentMonth = (int) wp_date('n');
        $monthNames = $this->get_month_names($lang);
        $translations = $this->get_translations($lang);
        $monthsToShow = $monthsMode === 'current' ? range($currentMonth, 12) : range(1, 12);

        $byMonth = array_fill(1, 12, []);
        foreach ($events as $event) {
            if ($event->year() === $year) {
                $byMonth[$event->month()][] = $this->build_event_view_model($event, $lang, $translations, $visibleLinks, $calendarProvider);
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
            'showMonthCounter' => $showMonthCounter,
        ]);
    }

    public function render_event_list(array $events, string $monthsMode, string $lang, bool $groupByMonth, string $bgColor, string $borderColor, string $textColor, bool $showMonthCounter, array $visibleLinks, string $calendarProvider): string
    {
        $year = (int) wp_date('Y');
        $currentMonth = (int) wp_date('n');
        $monthNames = $this->get_month_names($lang);
        $translations = $this->get_translations($lang);
        $monthsToShow = $monthsMode === 'current' ? range($currentMonth, 12) : range(1, 12);

        $eventItems = [];
        $byMonth = [];
        foreach ($events as $event) {
            $item = $this->build_event_view_model($event, $lang, $translations, $visibleLinks, $calendarProvider);
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
            'showMonthCounter' => $showMonthCounter,
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

    /** @param array<int,string> $visibleLinks
     *  @param array<string,mixed> $translations
     *  @return array<string,mixed>
     */
    private function build_event_view_model(Event $event, string $lang, array $translations, array $visibleLinks, string $calendarProvider): array
    {
        $visibleMap = array_fill_keys($visibleLinks, true);

        return [
            'summary' => $event->summary,
            'summary_fallback' => $translations['untitled_event'],
            'when' => $this->format_event_when($event, $lang),
            'location' => $event->location,
            'location_label' => $translations['location'],
            'description' => $event->description,
            'event_url' => isset($visibleMap['event']) ? esc_url($event->url) : '',
            'event_link_label' => $translations['event_link'],
            'add_to_calendar_url' => isset($visibleMap['add']) ? esc_url(call_user_func($this->addToCalendarUrlBuilder, $event, $calendarProvider)) : '',
            'add_to_calendar_label' => $translations['add_to_calendar'],
            'ics_download_url' => isset($visibleMap['download']) ? esc_url(call_user_func($this->icsDownloadUrlBuilder, $event)) : '',
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
                'event_link' => 'Open event link',
                'add_to_calendar' => 'Add to calendar',
                'download_ics' => 'Download .ics',
            ];
        }

        return [
            'untitled_event' => 'Sin título',
            'no_events' => 'Sin eventos para este mes.',
            'location' => 'Ubicación: ',
            'event_link' => 'Abrir enlace del evento',
            'add_to_calendar' => 'Agregar al calendario',
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
