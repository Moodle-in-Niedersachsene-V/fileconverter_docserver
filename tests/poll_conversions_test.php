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
 * Tests for the polling task.
 *
 * @package    fileconverter_docserver
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(poll_conversions::class)]
final class poll_conversions_test extends \advanced_testcase {
    /**
     * Running conversions that exceeded the maximum duration are failed by the task; others stay untouched.
     */
    public function test_task_fails_overdue_conversions_only(): void {
        $this->resetAfterTest();
        set_config('docserverurl', 'https://docs.example.org', 'fileconverter_docserver');
        set_config('converter_plugins_sortorder', 'docserver');
        $fs = get_file_storage();
        $ids = [];
        foreach (['ours' => converter::CLASSNAME, 'other' => '\\fileconverter_unoconv\\converter'] as $name => $class) {
            $file = $fs->create_file_from_string([
                'contextid' => \context_system::instance()->id,
                'component' => 'fileconverter_docserver',
                'filearea' => 'unittest',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => $name . '.docx',
            ], $name);
            $conversion = new conversion(0, (object) [
                'sourcefileid' => $file->get_id(),
                'targetformat' => 'pdf',
                'converter' => $class,
                'status' => conversion::STATUS_IN_PROGRESS,
                'data' => json_encode(['docserverstarted' => time() - DAYSECS, 'docserverextension' => 'docx']),
            ]);
            $conversion->create();
            $ids[$name] = (int) $conversion->get('id');
        }

        ob_start();
        (new poll_conversions())->execute();
        $output = ob_get_clean();

        $ours = new conversion($ids['ours']);
        $other = new conversion($ids['other']);
        $this->assertSame(conversion::STATUS_FAILED, (int) $ours->get('status'));
        $this->assertStringContainsString('error:timeout', $ours->get('statusmessage'));
        $this->assertSame(conversion::STATUS_IN_PROGRESS, (int) $other->get('status'));
        $this->assertStringContainsString('1 failed', $output);
    }
}
