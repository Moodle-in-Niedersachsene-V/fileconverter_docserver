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
 * Failure while talking to the document server or handling its result.
 *
 * The error code is a language string key of this plugin; the debug info carries
 * technical details for the administrator and is never shown to students or teachers.
 *
 * @package    fileconverter_docserver
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class docserver_exception extends \moodle_exception {
    /**
     * Create the exception.
     *
     * @param string $errorcode Language string key in fileconverter_docserver.
     * @param mixed $a Optional value for the language string.
     * @param string|null $debuginfo Technical details for administrators.
     */
    public function __construct(string $errorcode, mixed $a = null, ?string $debuginfo = null) {
        parent::__construct($errorcode, config::COMPONENT, '', $a, $debuginfo);
    }

    /**
     * Short English description for the conversion status message.
     *
     * @return string
     */
    public function get_status_message(): string {
        $message = $this->errorcode;
        if ($this->debuginfo !== null && $this->debuginfo !== '') {
            $message .= ': ' . $this->debuginfo;
        }
        return \core_text::substr($message, 0, 1000);
    }
}
