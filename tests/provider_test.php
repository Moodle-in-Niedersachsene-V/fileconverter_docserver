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

namespace fileconverter_docserver\privacy;

use core_privacy\local\metadata\collection;

/**
 * Privacy provider test: the external data flow to the document server is declared.
 *
 * @package    fileconverter_docserver
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * The metadata names the document server as external location.
     */
    public function test_metadata_declares_docserver(): void {
        $collection = provider::get_metadata(new collection('fileconverter_docserver'));

        $items = $collection->get_collection();
        $this->assertCount(1, $items);
        $this->assertSame('docserver', $items[0]->get_name());
        $this->assertArrayHasKey('filecontent', $items[0]->get_privacy_fields());
    }
}
