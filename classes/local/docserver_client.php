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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * HTTP client for the Conversion API of an ONLYOFFICE compatible document server.
 *
 * This is the only class that talks to the network. It uses Moodle's curl wrapper, so the
 * site's proxy and curl security settings apply.
 *
 * @package    fileconverter_docserver
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class docserver_client {
    /** @var string Path of the conversion endpoint, supported by all document server versions. */
    private const CONVERT_PATH = '/ConvertService.ashx';

    /** @var int Connect timeout in seconds. */
    private const CONNECT_TIMEOUT = 10;

    /** @var array<int, string> Error codes of the Conversion API. */
    private const ERRORS = [
        -1 => 'unknown error',
        -2 => 'conversion timeout',
        -3 => 'conversion error',
        -4 => 'error while downloading the source file',
        -5 => 'incorrect password',
        -6 => 'error while accessing the conversion result database',
        -7 => 'input error',
        -8 => 'invalid token',
        -9 => 'cannot detect the file format',
        -10 => 'size limit exceeded',
    ];

    /**
     * Create the client.
     *
     * @param config $config Plugin configuration.
     */
    public function __construct(
        /** @var config Plugin configuration. */
        private readonly config $config,
    ) {
    }

    /**
     * Create a Moodle curl instance with the configured timeouts.
     *
     * @return \curl
     */
    protected function create_curl(): \curl {
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
            'CURLOPT_TIMEOUT' => $this->config->requesttimeout,
            'CURLOPT_FOLLOWLOCATION' => false,
        ]);
        return $curl;
    }

    /**
     * Send (or repeat) a conversion request.
     *
     * Repeating the request with the same key is how the Conversion API reports progress.
     *
     * @param array $params Conversion API parameters.
     * @return \stdClass Object with endConvert (bool), percent (int) and, when finished, fileUrl (string).
     */
    public function request_conversion(array $params): \stdClass {
        $body = $params;
        if ($this->config->uses_jwt()) {
            $body['token'] = download_token::encode_request($this->config->jwtsecret, $params);
        }

        $curl = $this->create_curl();
        $curl->setHeader(['Content-Type: application/json', 'Accept: application/json']);
        $response = $curl->post($this->config->docserverurl . self::CONVERT_PATH, json_encode($body));

        $errno = (int) $curl->get_errno();
        if ($errno !== 0) {
            throw new docserver_exception('error:unreachable', null, 'curl error ' . $errno . ': ' . $curl->error);
        }
        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($httpcode !== 200) {
            throw new docserver_exception('error:invalidresponse', null, 'HTTP status ' . $httpcode);
        }

        $json = json_decode((string) $response);
        if (!$json instanceof \stdClass) {
            throw new docserver_exception('error:invalidresponse', null, 'Response is not a JSON object.');
        }
        if (isset($json->token) && is_string($json->token) && $this->config->uses_jwt()) {
            $json = download_token::decode_response($this->config->jwtsecret, $json->token);
        }

        if (isset($json->error)) {
            $code = (int) $json->error;
            $text = self::ERRORS[$code] ?? 'unexpected error';
            $errorcode = $code === -8 ? 'error:invalidtoken' : 'error:conversionfailed';
            throw new docserver_exception($errorcode, null, 'Conversion API error ' . $code . ' (' . $text . ')');
        }

        $result = new \stdClass();
        $result->endConvert = !empty($json->endConvert);
        $result->percent = (int) ($json->percent ?? 0);
        $result->fileUrl = (string) ($json->fileUrl ?? '');
        if ($result->endConvert && $result->fileUrl === '') {
            throw new docserver_exception('error:invalidresponse', null, 'Conversion finished without a result URL.');
        }
        return $result;
    }

    /**
     * Download a conversion result to a local path and check that it is a PDF.
     *
     * Only URLs on the configured document server host are accepted, so a manipulated
     * response cannot make Moodle fetch arbitrary addresses.
     *
     * @param string $url Result URL returned by the document server.
     * @param string $targetpath Local file path to write to.
     */
    public function download_result(string $url, string $targetpath): void {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $host !== $this->config->docserver_host()) {
            throw new docserver_exception('error:unexpectedresulthost', null, 'Result host: ' . $host);
        }

        $curl = $this->create_curl();
        $ok = $curl->download_one($url, null, ['filepath' => $targetpath, 'timeout' => $this->config->requesttimeout]);
        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($ok !== true || $httpcode !== 200) {
            throw new docserver_exception('error:downloadfailed', null, 'HTTP status ' . $httpcode);
        }

        $handle = is_readable($targetpath) ? fopen($targetpath, 'rb') : false;
        $magic = $handle ? (string) fread($handle, 5) : '';
        if ($handle) {
            fclose($handle);
        }
        if ($magic !== '%PDF-') {
            throw new docserver_exception('error:downloadfailed', null, 'The result is not a PDF file.');
        }
    }
}
