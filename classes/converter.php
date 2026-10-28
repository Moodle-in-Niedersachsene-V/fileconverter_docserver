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

namespace fileconverter_docserver;

use core_files\conversion;
use fileconverter_docserver\local\config;
use fileconverter_docserver\local\docserver_client;
use fileconverter_docserver\local\docserver_exception;
use fileconverter_docserver\local\source_access;

/**
 * Document converter that uses the Conversion API of an ONLYOFFICE compatible document server.
 *
 * Conversions run asynchronously: the document server downloads the source file from Moodle,
 * Moodle polls for the result and stores the PDF once it is ready.
 *
 * @package    fileconverter_docserver
 * @copyright  2019 Jan Dageförde, University of Münster
 *             2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class converter implements \core_files\converter_interface {
    /** @var string Class name as stored by core in the converter column of file_conversion. */
    public const CLASSNAME = '\\fileconverter_docserver\\converter';

    /** @var int Code page UTF-8 for text based input formats. */
    private const CODEPAGE_UTF8 = 65001;

    /** @var int Extra lifetime of a download URL beyond the maximum conversion duration, in seconds. */
    private const URL_GRACE = 300;

    /** @var string[] Supported source formats (file extensions). */
    private const IMPORTS = [
        'csv', 'doc', 'docx', 'htm', 'html', 'odp', 'ods', 'odt', 'ppt', 'pptx', 'rtf', 'txt', 'xls', 'xlsx',
    ];

    /** @var string[] Spreadsheet formats that are scaled to page width. */
    private const SPREADSHEETS = ['csv', 'ods', 'xls', 'xlsx'];

    /** @var string[] Supported target formats. */
    private const EXPORTS = ['pdf'];

    /** @var config Plugin configuration. */
    private config $config;

    /** @var docserver_client Client for the document server. */
    private docserver_client $client;

    /**
     * Create the converter.
     *
     * @param config|null $config Plugin configuration, loaded from the settings when null.
     * @param docserver_client|null $client Document server client, created from the configuration when null.
     */
    public function __construct(?config $config = null, ?docserver_client $client = null) {
        $this->config = $config ?? config::load();
        $this->client = $client ?? new docserver_client($this->config);
    }

    /**
     * Whether the plugin is configured.
     *
     * Only the settings are checked, so this stays cheap; the connection test page checks the server.
     *
     * @return bool
     */
    public static function are_requirements_met() {
        return config::load()->is_complete();
    }

    /**
     * Whether a conversion between the given formats is supported.
     *
     * @param string $from Source file extension.
     * @param string $to Target file extension.
     * @return bool
     */
    public static function supports($from, $to) {
        return in_array(strtolower((string) $from), self::IMPORTS, true)
            && in_array(strtolower((string) $to), self::EXPORTS, true);
    }

    /**
     * Human readable list of supported source formats.
     *
     * @return string
     */
    public function get_supported_conversions() {
        return implode(', ', self::IMPORTS);
    }

    /**
     * Send the conversion request to the document server.
     *
     * Core has already set the status to in progress and saved the record, which the
     * download endpoint relies on. On failure the status is set to failed so that core
     * can try the next enabled converter.
     *
     * @param conversion $conversion The conversion to start.
     * @return $this
     */
    public function start_document_conversion(conversion $conversion) {
        $file = $conversion->get_sourcefile();
        if (!$file) {
            return $this->fail($conversion, new docserver_exception('error:sourcemissing'));
        }

        $extension = strtolower((string) pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        $target = strtolower((string) $conversion->get('targetformat'));
        if (!self::supports($extension, $target)) {
            $reason = new docserver_exception('error:unsupportedformat', null, $extension . ' -> ' . $target);
            return $this->fail($conversion, $reason);
        }

        $data = $conversion->get('data');
        $data->docserverstarted = time();
        $data->docserverextension = $extension;
        $conversion->set('data', $data);
        $conversion->set('status', conversion::STATUS_IN_PROGRESS);
        $conversion->set('statusmessage', null);

        try {
            $result = $this->client->request_conversion($this->build_request($conversion, $file));
            $this->trigger_event('conversion_requested', $conversion, $file);
            if ($result->endConvert) {
                $this->store_result($conversion, $result->fileUrl);
            }
        } catch (docserver_exception $e) {
            $this->fail($conversion, $e, $file);
        }
        return $this;
    }

    /**
     * Ask the document server for the state of a running conversion and store the result when ready.
     *
     * @param conversion $conversion The conversion to poll.
     * @return $this
     */
    public function poll_conversion_status(conversion $conversion) {
        if ((int) $conversion->get('status') !== conversion::STATUS_IN_PROGRESS) {
            return $this;
        }

        $file = $conversion->get_sourcefile();
        if (!$file) {
            return $this->fail($conversion, new docserver_exception('error:sourcemissing'));
        }

        $started = (int) ($conversion->get('data')->docserverstarted ?? 0);
        if ($started <= 0) {
            // A conversion without start time was not started by this converter; start it now.
            return $this->start_document_conversion($conversion);
        }
        if (time() - $started > $this->config->maxduration) {
            return $this->fail($conversion, new docserver_exception('error:timeout'), $file);
        }

        try {
            $result = $this->client->request_conversion($this->build_request($conversion, $file));
            if ($result->endConvert) {
                $this->store_result($conversion, $result->fileUrl);
            }
        } catch (docserver_exception $e) {
            $this->fail($conversion, $e, $file);
        }
        return $this;
    }

    /**
     * Build the Conversion API parameters for a conversion.
     *
     * The key is stable for one conversion attempt, so repeated requests report progress
     * instead of starting over; it includes a site hash so several Moodle sites can share
     * one document server.
     *
     * @param conversion $conversion The conversion.
     * @param \stored_file $file Its source file.
     * @return array
     */
    private function build_request(conversion $conversion, \stored_file $file): array {
        $data = $conversion->get('data');
        $started = (int) $data->docserverstarted;
        $extension = (string) $data->docserverextension;
        $conversionid = (int) $conversion->get('id');

        $key = implode('_', [
            'moodle',
            substr(hash('sha256', get_site_identifier()), 0, 12),
            $conversionid,
            substr($file->get_contenthash(), 0, 16),
            $started,
        ]);

        $params = [
            'async' => true,
            'codePage' => self::CODEPAGE_UTF8,
            'filetype' => $extension,
            'key' => $key,
            'outputtype' => strtolower((string) $conversion->get('targetformat')),
            'title' => 'document.' . $extension,
            'url' => source_access::build_url(
                $this->config,
                $conversionid,
                $file,
                $extension,
                $started + $this->config->maxduration + self::URL_GRACE
            ),
        ];
        if (in_array($extension, self::SPREADSHEETS, true)) {
            $params['spreadsheetLayout'] = ['fitToWidth' => 1, 'fitToHeight' => 0];
        }
        return $params;
    }

    /**
     * Download the finished PDF and attach it to the conversion.
     *
     * @param conversion $conversion The conversion.
     * @param string $url Result URL from the document server.
     */
    private function store_result(conversion $conversion, string $url): void {
        $path = make_request_directory() . '/result.pdf';
        $this->client->download_result($url, $path);
        $conversion->store_destfile_from_path($path);
        $conversion->set('status', conversion::STATUS_COMPLETE);
        $conversion->set('statusmessage', null);
    }

    /**
     * Mark a conversion as failed and record why.
     *
     * @param conversion $conversion The conversion.
     * @param docserver_exception $e The cause.
     * @param \stored_file|null $file The source file, if it still exists.
     * @return $this
     */
    private function fail(conversion $conversion, docserver_exception $e, ?\stored_file $file = null): self {
        $conversion->set('status', conversion::STATUS_FAILED);
        $conversion->set('statusmessage', $e->get_status_message());
        if ($file && $conversion->get('id')) {
            $this->trigger_event('conversion_failed', $conversion, $file, $e->errorcode);
        }
        return $this;
    }

    /**
     * Trigger one of the plugin events for a conversion.
     *
     * @param string $name Event class name in fileconverter_docserver\event.
     * @param conversion $conversion The conversion.
     * @param \stored_file $file Its source file.
     * @param string $reason Error code for failure events.
     */
    private function trigger_event(string $name, conversion $conversion, \stored_file $file, string $reason = ''): void {
        $context = \context::instance_by_id($file->get_contextid(), IGNORE_MISSING) ?: \context_system::instance();
        $other = [
            'sourcefileid' => (int) $conversion->get('sourcefileid'),
            'targetformat' => (string) $conversion->get('targetformat'),
        ];
        if ($name === 'conversion_failed') {
            $other['reason'] = $reason;
        }
        $class = '\\fileconverter_docserver\\event\\' . $name;
        $class::create([
            'context' => $context,
            'objectid' => (int) $conversion->get('id'),
            'other' => $other,
        ])->trigger();
    }
}
