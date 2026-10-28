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

use core_files\conversion;
use fileconverter_docserver\converter;

/**
 * Decides whether a download request of the document server may receive a source file.
 *
 * A file is released only if all of the following hold:
 * - the conversion exists, belongs to this converter and is in progress;
 * - the URL signature matches conversion, file id, content hash and expiry time;
 * - the URL has not expired;
 * - when a JWT secret is set and signed downloads are required, the request carries a
 *   valid JWT of the document server whose URL contains the same signature.
 *
 * @package    fileconverter_docserver
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_access {
    /** @var string File area used in download URLs. */
    public const FILEAREA = 'source';

    /**
     * Build the download URL for the source file of a conversion.
     *
     * @param config $config Plugin configuration.
     * @param int $conversionid Id of the conversion record.
     * @param \stored_file $file Source file.
     * @param string $extension Lower case file extension of the source file.
     * @param int $expires Unix time after which the URL is no longer valid.
     * @return string Absolute URL as reachable by the document server.
     */
    public static function build_url(
        config $config,
        int $conversionid,
        \stored_file $file,
        string $extension,
        int $expires
    ): string {
        global $CFG;

        $signature = download_token::sign($config->urlsecret, $conversionid, $file, $expires);
        // The original file name is not passed on, so no personal data leaks into the document server logs.
        $url = \moodle_url::make_pluginfile_url(
            \context_system::instance()->id,
            config::COMPONENT,
            self::FILEAREA,
            $conversionid,
            '/' . $expires . '/' . $signature . '/',
            'document.' . $extension
        )->out(false);

        if ($config->moodleurl !== '' && str_starts_with($url, $CFG->wwwroot)) {
            $url = $config->moodleurl . substr($url, strlen($CFG->wwwroot));
        }
        return $url;
    }

    /**
     * Resolve a download request to the source file, or null if access is denied.
     *
     * @param config $config Plugin configuration.
     * @param int $conversionid Conversion id from the URL.
     * @param int $expires Expiry time from the URL.
     * @param string $signature Signature from the URL.
     * @param string $authorization Raw Authorization header of the request, or empty.
     * @param int $now Current Unix time.
     * @return \stored_file|null
     */
    public static function resolve(
        config $config,
        int $conversionid,
        int $expires,
        string $signature,
        string $authorization,
        int $now
    ): ?\stored_file {
        global $DB;

        if ($conversionid <= 0 || !preg_match('/^[0-9a-f]{64}$/', $signature)) {
            return null;
        }

        $record = $DB->get_record(conversion::TABLE, [
            'id' => $conversionid,
            'converter' => converter::CLASSNAME,
            'status' => conversion::STATUS_IN_PROGRESS,
        ], 'id, sourcefileid');
        if (!$record) {
            return null;
        }

        $file = get_file_storage()->get_file_by_id((int) $record->sourcefileid);
        if (!$file) {
            return null;
        }

        if (!download_token::verify($config->urlsecret, $conversionid, $file, $expires, $signature, $now)) {
            return null;
        }

        if (
            $config->uses_jwt() && $config->requiresigneddownload
                && !download_token::verify_docserver_jwt($config->jwtsecret, $authorization, $signature)
        ) {
            return null;
        }

        return $file;
    }

    /**
     * Read the Authorization header of the current request.
     *
     * Moodle has no API for request headers. The header is read from the server environment
     * because the document server sends its JWT there; nothing else of the request is used.
     *
     * @return string
     */
    public static function get_authorization_header(): string {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
                return $_SERVER[$key];
            }
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    return (string) $value;
                }
            }
        }
        return '';
    }
}
