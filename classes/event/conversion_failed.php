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

namespace fileconverter_docserver\event;

/**
 * Event raised when a conversion on the document server fails.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - int sourcefileid: id of the source file.
 *      - string targetformat: target file extension.
 *      - string reason: error code of the failure.
 * }
 *
 * @package    fileconverter_docserver
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conversion_failed extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'file_conversion';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:conversionfailed', 'fileconverter_docserver');
    }

    /**
     * Non-localised event description.
     *
     * @return string
     */
    public function get_description() {
        $sourcefileid = (int) $this->other['sourcefileid'];
        $targetformat = s($this->other['targetformat']);
        return "The document converter failed to convert the file with id '$sourcefileid' to '$targetformat'.";
    }

    /**
     * Validate the custom data.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['sourcefileid', 'targetformat'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '$key' value must be set in other.");
            }
        }
    }

    /**
     * Conversion records are not part of backups.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'file_conversion', 'restore' => self::NOT_MAPPED];
    }

    /**
     * The other data holds no ids that need mapping on restore.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
