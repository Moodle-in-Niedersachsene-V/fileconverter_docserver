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

use fileconverter_docserver\converter;

/**
 * Tests for the connection test.
 *
 * @package    fileconverter_docserver
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(connection_test::class)]
final class connection_test_test extends \advanced_testcase {
    /**
     * Without settings the test reports the missing configuration.
     */
    public function test_reports_missing_configuration(): void {
        $this->resetAfterTest();
        set_config('docserverurl', '', 'fileconverter_docserver');

        $result = connection_test::run(5);

        $this->assertFalse($result->success);
        $this->assertSame(get_string('test:notconfigured', 'fileconverter_docserver'), $result->message);
    }

    /**
     * A working server yields success and leaves no files or conversion records behind.
     */
    public function test_success_cleans_up(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('docserverurl', 'https://docs.example.org', 'fileconverter_docserver');
        $config = config::load();
        $client = new class ($config) extends docserver_client {
            /**
             * Answer that the conversion is finished.
             *
             * @param array $params Request parameters.
             * @return \stdClass
             */
            public function request_conversion(array $params): \stdClass {
                return (object) ['endConvert' => true, 'percent' => 100, 'fileUrl' => 'https://docs.example.org/r.pdf'];
            }

            /**
             * Write a minimal PDF.
             *
             * @param string $url Result URL.
             * @param string $targetpath Target path.
             */
            public function download_result(string $url, string $targetpath): void {
                file_put_contents($targetpath, "%PDF-1.4\n");
            }
        };

        $result = connection_test::run(5, new converter($config, $client));

        $this->assertTrue($result->success);
        $this->assertSame(9, $result->bytes);
        $this->assertSame(0, $DB->count_records('file_conversion'));
        $this->assertSame(0, $DB->count_records_select(
            'files',
            "component = ? AND filename <> '.'",
            ['fileconverter_docserver']
        ));
    }
}
