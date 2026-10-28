<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace fileconverter_docserver\local;

/**
 * Typed, validated view of the plugin settings.
 *
 * @package    fileconverter_docserver
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class config {
    /** @var string Frankenstyle name of the plugin. */
    public const COMPONENT = 'fileconverter_docserver';

    /** @var int Lower bound for the request timeout in seconds. */
    private const MIN_TIMEOUT = 5;

    /** @var int Lower bound for the maximum conversion duration in seconds. */
    private const MIN_DURATION = 60;

    /**
     * Create the configuration object.
     *
     * @param string $docserverurl Base URL of the document server as seen from Moodle, without trailing slash.
     * @param string $moodleurl Base URL of Moodle as seen from the document server, without trailing slash, or empty.
     * @param string $jwtsecret Shared JWT secret of the document server, or empty if JWT is disabled.
     * @param bool $requiresigneddownload Whether file downloads must carry a valid JWT from the document server.
     * @param int $requesttimeout Timeout for a single HTTP request in seconds.
     * @param int $maxduration Maximum time a conversion may take before it is marked as failed, in seconds.
     * @param string $urlsecret Site-local secret used to sign download URLs.
     */
    public function __construct(
        /** @var string Base URL of the document server as seen from Moodle. */
        public readonly string $docserverurl,
        /** @var string Base URL of Moodle as seen from the document server, or empty. */
        public readonly string $moodleurl,
        /** @var string Shared JWT secret, or empty. */
        public readonly string $jwtsecret,
        /** @var bool Whether file downloads must carry a valid JWT. */
        public readonly bool $requiresigneddownload,
        /** @var int Timeout for a single HTTP request in seconds. */
        public readonly int $requesttimeout,
        /** @var int Maximum conversion duration in seconds. */
        public readonly int $maxduration,
        /** @var string Site-local secret used to sign download URLs. */
        public readonly string $urlsecret,
    ) {
    }

    /**
     * Load the configuration from the plugin settings.
     *
     * @return self
     */
    public static function load(): self {
        $raw = get_config(self::COMPONENT);
        return new self(
            rtrim(trim((string) ($raw->docserverurl ?? '')), '/'),
            rtrim(trim((string) ($raw->moodleurl ?? '')), '/'),
            (string) ($raw->jwtsecret ?? ''),
            !empty($raw->requiresigneddownload),
            max(self::MIN_TIMEOUT, (int) ($raw->requesttimeout ?? 30)),
            max(self::MIN_DURATION, (int) ($raw->maxduration ?? 30 * MINSECS)),
            (string) ($raw->urlsecret ?? ''),
        );
    }

    /**
     * Whether the minimum configuration for conversions is present.
     *
     * @return bool
     */
    public function is_complete(): bool {
        return self::is_http_url($this->docserverurl)
            && ($this->moodleurl === '' || self::is_http_url($this->moodleurl))
            && $this->urlsecret !== '';
    }

    /**
     * Whether JWT signing is enabled.
     *
     * @return bool
     */
    public function uses_jwt(): bool {
        return $this->jwtsecret !== '';
    }

    /**
     * Host name of the document server, lower case.
     *
     * @return string
     */
    public function docserver_host(): string {
        return strtolower((string) parse_url($this->docserverurl, PHP_URL_HOST));
    }

    /**
     * Whether a string is an absolute http or https URL with a host.
     *
     * @param string $url The URL to check.
     * @return bool
     */
    private static function is_http_url(string $url): bool {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) && (string) parse_url($url, PHP_URL_HOST) !== '';
    }
}
