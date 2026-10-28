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
 * Tests for the document server client.
 *
 * The HTTP transport (Moodle's curl class) is replaced by a double that returns prepared responses.
 *
 * @package    fileconverter_docserver
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(docserver_client::class)]
final class docserver_client_test extends \advanced_testcase {
    /**
     * Client whose curl returns a prepared response.
     *
     * @param string $jwtsecret JWT secret.
     * @param string $body Response body.
     * @param int $httpcode HTTP status.
     * @param int $errno Curl error number.
     * @param string|null $download Content written by download_one, or null for a failed download.
     * @return docserver_client
     */
    private function make_client(
        string $jwtsecret,
        string $body,
        int $httpcode = 200,
        int $errno = 0,
        ?string $download = null
    ): docserver_client {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $config = new config('https://docs.example.org', '', $jwtsecret, true, 30, 1800, str_repeat('a', 64));
        $curl = new class ($body, $httpcode, $errno, $download) extends \curl {
            /** @var array Recorded POST bodies. */
            public array $posts = [];

            /**
             * Create the double.
             *
             * @param string $body Response body.
             * @param int $code HTTP status.
             * @param int $err Curl error number.
             * @param string|null $download Download content.
             */
            public function __construct(
                /** @var string Response body. */
                private string $body,
                /** @var int HTTP status. */
                private int $code,
                /** @var int Curl error number. */
                private int $err,
                /** @var string|null Download content. */
                private ?string $download,
            ) {
                parent::__construct();
            }

            /**
             * Record the body and return the prepared response.
             *
             * @param string $url URL.
             * @param array|string $params Body.
             * @param array $options Options.
             * @return string
             */
            public function post($url, $params = '', $options = []) {
                $this->posts[] = $params;
                $this->errno = $this->err;
                $this->info = ['http_code' => $this->code];
                return $this->body;
            }

            /**
             * Write the prepared download.
             *
             * @param string $url URL.
             * @param array|null $params Query parameters.
             * @param array $options Options.
             * @return bool|string
             */
            public function download_one($url, $params, $options = []) {
                $this->info = ['http_code' => $this->download === null ? 404 : 200];
                if ($this->download === null) {
                    return 'not found';
                }
                file_put_contents($options['filepath'], $this->download);
                return true;
            }
        };
        return new class ($config, $curl) extends docserver_client {
            /**
             * Create the client.
             *
             * @param config $config Configuration.
             * @param \curl $curl Curl double.
             */
            public function __construct(
                config $config,
                /** @var \curl Curl double. */
                public \curl $curl,
            ) {
                parent::__construct($config);
            }

            /**
             * Return the double.
             *
             * @return \curl
             */
            protected function create_curl(): \curl {
                return $this->curl;
            }
        };
    }

    /**
     * Requests carry the parameters and a JWT over them.
     */
    public function test_request_is_signed(): void {
        $client = $this->make_client('s3cret', '{"endConvert":false,"percent":40}');

        $result = $client->request_conversion(['key' => 'k1', 'url' => 'https://moodle.example.org/f']);

        $this->assertFalse($result->endConvert);
        $this->assertSame(40, $result->percent);
        $body = json_decode($client->curl->posts[0]);
        $claims = \Firebase\JWT\JWT::decode($body->token, new \Firebase\JWT\Key('s3cret', 'HS256'));
        $this->assertSame('k1', $claims->key);
        $this->assertSame('k1', $body->key);
    }

    /**
     * Error -8 is reported as an invalid token, other codes as conversion errors.
     */
    public function test_error_codes_are_mapped(): void {
        try {
            $this->make_client('wrong', '{"error":-8}')->request_conversion(['key' => 'k']);
            $this->fail('Expected exception.');
        } catch (docserver_exception $e) {
            $this->assertSame('error:invalidtoken', $e->errorcode);
        }
        try {
            $this->make_client('', '{"error":-4}')->request_conversion(['key' => 'k']);
            $this->fail('Expected exception.');
        } catch (docserver_exception $e) {
            $this->assertSame('error:conversionfailed', $e->errorcode);
            $this->assertStringContainsString('-4', $e->get_status_message());
        }
    }

    /**
     * Network errors and non-JSON answers fail loudly.
     */
    public function test_transport_failures(): void {
        $this->expectException(docserver_exception::class);
        $this->make_client('', '', 0, 7)->request_conversion(['key' => 'k']);
    }

    /**
     * Results are only downloaded from the document server host and must be PDFs.
     */
    public function test_download_checks_host_and_type(): void {
        $target = make_request_directory() . '/r.pdf';

        $this->make_client('', '', 200, 0, "%PDF-1.7\n")->download_result('https://docs.example.org/cache/a.pdf', $target);
        $this->assertStringStartsWith('%PDF-', file_get_contents($target));

        try {
            $this->make_client('', '', 200, 0, "%PDF-1.7\n")->download_result('https://evil.example.com/a.pdf', $target);
            $this->fail('Expected exception.');
        } catch (docserver_exception $e) {
            $this->assertSame('error:unexpectedresulthost', $e->errorcode);
        }

        try {
            $this->make_client('', '', 200, 0, '<html>')->download_result('https://docs.example.org/a.pdf', $target);
            $this->fail('Expected exception.');
        } catch (docserver_exception $e) {
            $this->assertSame('error:downloadfailed', $e->errorcode);
        }
    }
}
