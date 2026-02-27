<?php
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
