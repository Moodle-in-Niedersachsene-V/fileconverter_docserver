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

/**
 * Library functions of fileconverter_docserver.
 *
 * @package    fileconverter_docserver
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use fileconverter_docserver\local\config;
use fileconverter_docserver\local\source_access;

/**
 * Serve a source file to the document server.
 *
 * This endpoint is deliberately reachable without a Moodle login: the document server
 * cannot authenticate as a user. Access is instead bound to a signed, expiring URL for a
 * conversion in progress and, if configured, to the JWT of the document server.
 * See \fileconverter_docserver\local\source_access::resolve() for the full rule set.
 *
 * @param stdClass $course Course object (site course in system context).
 * @param stdClass|null $cm Course module, always null here.
 * @param context $context The context, must be the system context.
 * @param string $filearea The file area, must be 'source'.
 * @param array $args conversion id, expiry time, signature and file name.
 * @param bool $forcedownload Ignored, the file is always sent as attachment.
 * @param array $options Additional send options.
 * @return bool False if access is denied; otherwise the file is sent and the script ends.
 */
function fileconverter_docserver_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    // Semgrep moodle-pluginfile-ohne-pruefung is a known false positive here: the document server cannot log in,
    // so require_login() is replaced by the signed URL and JWT checks in source_access::resolve().
    if ($context->contextlevel != CONTEXT_SYSTEM || $filearea !== source_access::FILEAREA || count($args) !== 4) {
        return false;
    }

    $conversionid = clean_param($args[0], PARAM_INT);
    $expires = clean_param($args[1], PARAM_INT);
    $signature = clean_param($args[2], PARAM_ALPHANUM);

    $file = source_access::resolve(
        config::load(),
        (int) $conversionid,
        (int) $expires,
        (string) $signature,
        source_access::get_authorization_header(),
        time()
    );
    if (!$file) {
        return false;
    }

    // No caching anywhere: the URL grants access to a student submission.
    $options['cacheability'] = 'private';
    send_stored_file($file, 0, 0, true, $options);
}
