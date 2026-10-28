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
 * End-to-end connection test: converts a bundled DOCX file through the document server.
 *
 * The test exercises both directions: Moodle reaches the document server, and the document
 * server downloads the file from Moodle through the signed download URL.
 *
 * @package    fileconverter_docserver
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connection_test {
    /** @var string File area of the test document. */
    private const FILEAREA = 'connectiontest';

    /** @var string Name of the bundled test document. */
    private const FIXTURE = 'connection-test.docx';

    /**
     * Run the test and clean up afterwards.
     *
     * @param int $maxwait Maximum time to wait for the result in seconds.
     * @param converter|null $converter Converter to use; a configured one is created when null.
     * @return \stdClass Object with success (bool), message (string, language string already resolved),
     *                   details (string, technical, English), seconds (int) and bytes (int).
     */
    public static function run(int $maxwait, ?converter $converter = null): \stdClass {
        $result = (object) ['success' => false, 'message' => '', 'details' => '', 'seconds' => 0, 'bytes' => 0];

        $config = config::load();
        if (!$config->is_complete()) {
            $result->message = get_string('test:notconfigured', config::COMPONENT);
            return $result;
        }
        $converter = $converter ?? new converter($config);

        $file = self::create_test_file();
        $conversion = new conversion(0, (object) [
            'sourcefileid' => $file->get_id(),
            'targetformat' => 'pdf',
            'converter' => converter::CLASSNAME,
            'status' => conversion::STATUS_IN_PROGRESS,
        ]);
        $conversion->create();

        $start = time();
        try {
            $converter->start_document_conversion($conversion);
            $conversion->update();
            while ((int) $conversion->get('status') === conversion::STATUS_IN_PROGRESS && time() - $start < $maxwait) {
                sleep(1);
                $converter->poll_conversion_status($conversion);
                $conversion->update();
            }

            $result->seconds = time() - $start;
            $status = (int) $conversion->get('status');
            $destfile = $status === conversion::STATUS_COMPLETE ? $conversion->get_destfile() : false;
            if ($destfile) {
                $result->success = true;
                $result->bytes = (int) $destfile->get_filesize();
                $result->message = get_string('test:success', config::COMPONENT, $result);
                $destfile->delete();
            } else if ($status === conversion::STATUS_IN_PROGRESS) {
                $result->message = get_string('test:timeout', config::COMPONENT, $maxwait);
            } else {
                $result->message = get_string('test:failed', config::COMPONENT);
                $result->details = (string) $conversion->get('statusmessage');
            }
        } finally {
            $conversion->delete();
            $file->delete();
        }
        return $result;
    }

    /**
     * Store the bundled test document in the plugin's file area.
     *
     * @return \stored_file
     */
    private static function create_test_file(): \stored_file {
        $record = [
            'contextid' => \context_system::instance()->id,
            'component' => config::COMPONENT,
            'filearea' => self::FILEAREA,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => self::FIXTURE,
        ];
        $fs = get_file_storage();
        $existing = $fs->get_file(
            $record['contextid'],
            $record['component'],
            $record['filearea'],
            $record['itemid'],
            $record['filepath'],
            $record['filename']
        );
        if ($existing) {
            $existing->delete();
        }
        return $fs->create_file_from_pathname($record, dirname(__DIR__, 2) . '/tests/fixtures/' . self::FIXTURE);
    }
}
