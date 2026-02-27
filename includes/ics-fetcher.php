<?php
final class IcsFetcher implements CalGoogleIcsFetcherInterface
{
    private const EVENTS_CACHE_SCHEMA_VERSION = 1;

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
        $cachedEvents = $this->events_from_cache_payload($cached);
        if (is_array($cachedEvents)) {
            return $cachedEvents;
        }

        if ($cached !== false) {
            delete_transient($cache_key);
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

        $events = $this->sanitize_events($this->parser->parse_ics_events($body, $rangeStart, $rangeEnd));

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

        set_transient($cache_key, $this->build_events_cache_payload($events), CalGoogleConfig::CACHE_TTL_SECONDS);

        return $events;
    }

    /** @param mixed $cached */
    private function events_from_cache_payload($cached): ?array
    {
        if (! is_array($cached)) {
            return null;
        }

        if (($cached['schema_version'] ?? null) !== self::EVENTS_CACHE_SCHEMA_VERSION) {
            return null;
        }

        if (! is_numeric($cached['validated_at'] ?? null) || ! is_array($cached['events'] ?? null)) {
            return null;
        }

        $events = [];
        foreach ($cached['events'] as $eventRow) {
            $event = $this->build_event_from_cache_row($eventRow);
            if (! ($event instanceof Event)) {
                return null;
            }

            $events[] = $event;
        }

        return $events;
    }

    /** @param array<int,Event> $events
     *  @return array{schema_version:int,validated_at:int,events:array<int,array<string,mixed>>}
     */
    private function build_events_cache_payload(array $events): array
    {
        $rows = [];
        foreach ($events as $event) {
            $rows[] = [
                'summary' => $event->summary,
                'description' => $event->description,
                'location' => $event->location,
                'url' => $event->url,
                'uid' => $event->uid,
                'rrule' => $event->rrule,
                'start_ts' => $event->start->getTimestamp(),
                'end_ts' => $event->end instanceof DateTimeImmutable ? $event->end->getTimestamp() : null,
                'exdate_ts' => array_map(static fn (DateTimeImmutable $date): int => $date->getTimestamp(), $event->exdate),
                'rdate_ts' => array_map(static fn (DateTimeImmutable $date): int => $date->getTimestamp(), $event->rdate),
            ];
        }

        return [
            'schema_version' => self::EVENTS_CACHE_SCHEMA_VERSION,
            'validated_at' => time(),
            'events' => $rows,
        ];
    }

    /** @return array<int,Event> */
    private function sanitize_events(array $events): array
    {
        $sanitizedEvents = [];
        foreach ($events as $event) {
            if (! ($event instanceof Event)) {
                continue;
            }

            $sanitizedEvents[] = new Event(
                sanitize_text_field($event->summary),
                sanitize_textarea_field($event->description),
                sanitize_text_field($event->location),
                esc_url_raw($event->url),
                $event->start,
                $event->end,
                sanitize_text_field($event->uid),
                sanitize_text_field($event->rrule),
                $this->sanitize_event_dates($event->exdate),
                $this->sanitize_event_dates($event->rdate)
            );
        }

        return $sanitizedEvents;
    }

    /** @param mixed $eventRow */
    private function build_event_from_cache_row($eventRow): ?Event
    {
        if (! is_array($eventRow) || ! is_numeric($eventRow['start_ts'] ?? null)) {
            return null;
        }

        $timezone = wp_timezone();
        $start = (new DateTimeImmutable('@' . (int) $eventRow['start_ts']))->setTimezone($timezone);
        $end = null;
        if (($eventRow['end_ts'] ?? null) !== null) {
            if (! is_numeric($eventRow['end_ts'])) {
                return null;
            }
            $end = (new DateTimeImmutable('@' . (int) $eventRow['end_ts']))->setTimezone($timezone);
        }

        $exdate = $this->build_dates_from_timestamps($eventRow['exdate_ts'] ?? []);
        if ($exdate === null) {
            return null;
        }

        $rdate = $this->build_dates_from_timestamps($eventRow['rdate_ts'] ?? []);
        if ($rdate === null) {
            return null;
        }

        return $this->sanitize_events([
            new Event(
                (string) ($eventRow['summary'] ?? ''),
                (string) ($eventRow['description'] ?? ''),
                (string) ($eventRow['location'] ?? ''),
                (string) ($eventRow['url'] ?? ''),
                $start,
                $end,
                (string) ($eventRow['uid'] ?? ''),
                (string) ($eventRow['rrule'] ?? ''),
                $exdate,
                $rdate
            ),
        ])[0] ?? null;
    }

    /** @param mixed $timestamps
     * @return array<int,DateTimeImmutable>|null
     */
    private function build_dates_from_timestamps($timestamps): ?array
    {
        if (! is_array($timestamps)) {
            return null;
        }

        $timezone = wp_timezone();
        $dates = [];
        foreach ($timestamps as $timestamp) {
            if (! is_numeric($timestamp)) {
                return null;
            }

            $dates[] = (new DateTimeImmutable('@' . (int) $timestamp))->setTimezone($timezone);
        }

        return $dates;
    }

    /**
     * @param array<int,mixed> $dates
     * @return array<int,DateTimeImmutable>
     */
    private function sanitize_event_dates(array $dates): array
    {
        return array_values(array_filter($dates, static fn ($date): bool => $date instanceof DateTimeImmutable));
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
