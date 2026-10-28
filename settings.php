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
 * Admin settings for fileconverter_docserver.
 *
 * @package    fileconverter_docserver
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// The connection test page is reachable from the settings page only, so it stays hidden in the admin tree.
$ADMIN->add('fileconverterplugins', new admin_externalpage(
    'fileconverter_docserver_test',
    new lang_string('testconnection', 'fileconverter_docserver'),
    new moodle_url('/files/converter/docserver/test.php'),
    'moodle/site:config',
    true
));

if ($ADMIN->fulltree) {
    $testurl = new moodle_url('/files/converter/docserver/test.php');
    $settings->add(new admin_setting_heading(
        'fileconverter_docserver/connectionheading',
        new lang_string('settings:connection', 'fileconverter_docserver'),
        new lang_string('settings:connection_desc', 'fileconverter_docserver', $testurl->out())
    ));

    $settings->add(new admin_setting_configtext(
        'fileconverter_docserver/docserverurl',
        new lang_string('settings:docserverurl', 'fileconverter_docserver'),
        new lang_string('settings:docserverurl_desc', 'fileconverter_docserver'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'fileconverter_docserver/moodleurl',
        new lang_string('settings:moodleurl', 'fileconverter_docserver'),
        new lang_string('settings:moodleurl_desc', 'fileconverter_docserver'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_heading(
        'fileconverter_docserver/securityheading',
        new lang_string('settings:security', 'fileconverter_docserver'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'fileconverter_docserver/jwtsecret',
        new lang_string('settings:jwtsecret', 'fileconverter_docserver'),
        new lang_string('settings:jwtsecret_desc', 'fileconverter_docserver'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'fileconverter_docserver/requiresigneddownload',
        new lang_string('settings:requiresigneddownload', 'fileconverter_docserver'),
        new lang_string('settings:requiresigneddownload_desc', 'fileconverter_docserver'),
        1
    ));

    $settings->add(new admin_setting_heading(
        'fileconverter_docserver/limitsheading',
        new lang_string('settings:limits', 'fileconverter_docserver'),
        ''
    ));

    $settings->add(new admin_setting_configduration(
        'fileconverter_docserver/requesttimeout',
        new lang_string('settings:requesttimeout', 'fileconverter_docserver'),
        new lang_string('settings:requesttimeout_desc', 'fileconverter_docserver'),
        30,
        1
    ));

    $settings->add(new admin_setting_configduration(
        'fileconverter_docserver/maxduration',
        new lang_string('settings:maxduration', 'fileconverter_docserver'),
        new lang_string('settings:maxduration_desc', 'fileconverter_docserver'),
        30 * MINSECS,
        MINSECS
    ));
}
