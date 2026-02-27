<?php
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
    public const TEMPLATES_DIR = __DIR__ . '/../templates';

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
