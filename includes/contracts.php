<?php
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
    public function render_year_accordion(array $events, string $monthsMode, string $lang, string $bgColor, string $borderColor, string $textColor, bool $showMonthCounter, array $visibleLinks, string $calendarProvider): string;

    /** @param array<int,Event> $events */
    public function render_event_list(array $events, string $monthsMode, string $lang, bool $groupByMonth, string $bgColor, string $borderColor, string $textColor, bool $showMonthCounter, array $visibleLinks, string $calendarProvider): string;

    public function render_error_message(string $message): string;
}

