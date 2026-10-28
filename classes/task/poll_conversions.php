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

namespace fileconverter_docserver\task;

use core_files\conversion;
use fileconverter_docserver\converter;

/**
 * Poll the document server for running conversions and store finished PDFs.
 *
 * Runs every minute but does nothing beyond one indexed query while no conversion is running.
 *
 * @package    fileconverter_docserver
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class poll_conversions extends \core\task\scheduled_task {
    /** @var int Maximum number of conversions handled in one run. */
    private const BATCH_SIZE = 200;

    /**
     * Name of the task shown to administrators.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:pollconversions', 'fileconverter_docserver');
    }

    /**
     * Poll all running conversions of this converter.
     */
    public function execute() {
        global $DB;

        $records = $DB->get_records_select(
            conversion::TABLE,
            'status = :status AND converter = :converter',
            ['status' => conversion::STATUS_IN_PROGRESS, 'converter' => converter::CLASSNAME],
            'id ASC',
            'id',
            0,
            self::BATCH_SIZE
        );
        if (!$records) {
            return;
        }

        $coreconverter = new \core_files\converter();
        $counts = ['done' => 0, 'failed' => 0, 'running' => 0];
        foreach (array_keys($records) as $id) {
            $conversion = new conversion((int) $id);
            $coreconverter->poll_conversion($conversion);
            $status = (int) $conversion->get('status');
            if ($status === conversion::STATUS_COMPLETE) {
                $counts['done']++;
            } else if ($status === conversion::STATUS_FAILED) {
                $counts['failed']++;
                mtrace('Conversion ' . $id . ' failed: ' . $conversion->get('statusmessage'));
            } else {
                $counts['running']++;
            }
        }
        mtrace("Document server conversions: {$counts['done']} finished, {$counts['failed']} failed, "
            . "{$counts['running']} still running.");
    }
}
