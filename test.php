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
 * Connection test for the document server.
 *
 * @package    fileconverter_docserver
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('fileconverter_docserver_test');

$pageurl = new moodle_url('/files/converter/docserver/test.php');
$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'fileconverterdocserver']);
$PAGE->set_url($pageurl);

$result = null;
if (data_submitted() && confirm_sesskey()) {
    core_php_time_limit::raise(120);
    $result = \fileconverter_docserver\local\connection_test::run(60);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('testconnection', 'fileconverter_docserver')
    . $OUTPUT->help_icon('testconnection', 'fileconverter_docserver'));

if ($result !== null) {
    $type = $result->success ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR;
    $message = s($result->message);
    if ($result->details !== '') {
        $message .= html_writer::tag('pre', s($result->details), ['class' => 'mt-2 mb-0']);
    }
    echo $OUTPUT->notification($message, $type, false);
}

echo html_writer::tag('p', get_string('test:intro', 'fileconverter_docserver'));
echo $OUTPUT->single_button(
    $pageurl,
    get_string('test:run', 'fileconverter_docserver'),
    'post',
    ['type' => single_button::BUTTON_PRIMARY]
);
echo html_writer::tag(
    'p',
    html_writer::link($settingsurl, get_string('test:backtosettings', 'fileconverter_docserver')),
    ['class' => 'mt-3']
);

echo $OUTPUT->footer();
