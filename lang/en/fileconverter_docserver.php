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
 * English language strings for fileconverter_docserver.
 *
 * @package    fileconverter_docserver
 * @category   string
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['error:conversionfailed'] = 'The document server could not convert the file.';
$string['error:downloadfailed'] = 'The converted file could not be downloaded from the document server.';
$string['error:invalidresponse'] = 'The document server sent an invalid response.';
$string['error:invalidtoken'] = 'The document server rejected the request signature. Check the JWT secret.';
$string['error:sourcemissing'] = 'The file to convert no longer exists.';
$string['error:timeout'] = 'The conversion took longer than the configured maximum duration.';
$string['error:unexpectedresulthost'] = 'The document server returned a result on an unexpected host.';
$string['error:unreachable'] = 'The document server could not be reached.';
$string['error:unsupportedformat'] = 'This file format is not supported.';
$string['event:conversionfailed'] = 'Document conversion failed';
$string['event:conversionrequested'] = 'Document conversion requested';
$string['pluginname'] = 'Document server converter (ONLYOFFICE compatible)';
$string['privacy:metadata:docserver'] = 'To convert a submitted file to PDF, its content is sent to the document server configured by the site administrator. The server keeps it only while converting.';
$string['privacy:metadata:docserver:filecontent'] = 'The content of the file to convert.';
$string['privacy:metadata:docserver:filetype'] = 'The file type (extension) of the file to convert. The original file name is not sent.';
$string['settings:connection'] = 'Connection';
$string['settings:connection_desc'] = 'Converts Word, Excel, PowerPoint and OpenDocument files to PDF through the Conversion API of an ONLYOFFICE compatible document server, for example to annotate assignment submissions. After saving the settings, use the <a href="{$a}">connection test</a> to check both directions.';
$string['settings:docserverurl'] = 'Document server URL';
$string['settings:docserverurl_desc'] = 'Base URL of the document server as Moodle reaches it, for example https://onlyoffice.example.org. Moodle sends conversion requests here and downloads the results from this host only.';
$string['settings:jwtsecret'] = 'JWT secret';
$string['settings:jwtsecret_desc'] = 'The secret configured on the document server (services.CoAuthoring.secret). Leave empty only if JWT is disabled on the server, which is not recommended.';
$string['settings:limits'] = 'Limits';
$string['settings:maxduration'] = 'Maximum conversion duration';
$string['settings:maxduration_desc'] = 'A conversion that has not finished after this time is marked as failed. Download links for the document server expire five minutes later.';
$string['settings:moodleurl'] = 'Moodle URL for the document server';
$string['settings:moodleurl_desc'] = 'Only needed if the document server reaches Moodle under a different address than the normal site URL, for example in container setups. Leave empty to use the site URL.';
$string['settings:requesttimeout'] = 'Request timeout';
$string['settings:requesttimeout_desc'] = 'Maximum time for a single request to the document server, including the download of a result.';
$string['settings:requiresigneddownload'] = 'Require signed downloads';
$string['settings:requiresigneddownload_desc'] = 'If a JWT secret is set, Moodle only hands out a file when the download request carries a valid token of the document server. Requires that the web server passes the Authorization header to PHP.';
$string['settings:security'] = 'Security';
$string['task:pollconversions'] = 'Poll the document server for finished conversions';
$string['test:backtosettings'] = 'Back to the settings';
$string['test:failed'] = 'The test conversion failed.';
$string['test:intro'] = 'The test converts a small Word document. It checks that Moodle reaches the document server and that the document server can download the file from Moodle.';
$string['test:notconfigured'] = 'The document server URL is not set or not valid.';
$string['test:run'] = 'Run connection test';
$string['test:success'] = 'The connection works: the test document was converted to PDF ({$a->bytes} bytes) in {$a->seconds} seconds.';
$string['test:timeout'] = 'No result within {$a} seconds. The document server may be overloaded or unable to download the file from Moodle.';
$string['testconnection'] = 'Document server connection test';
$string['testconnection_help'] = 'Sends a small Word document to the document server and waits up to one minute for the PDF.

If the test fails, check:

* the document server URL and that Moodle can reach it (firewall, curl security settings),
* that the document server can reach Moodle (or the Moodle URL for the document server),
* the JWT secret,
* that the web server passes the Authorization header to PHP when signed downloads are required.';
