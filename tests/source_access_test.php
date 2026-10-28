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

use core_files\conversion;
use fileconverter_docserver\converter;
use Firebase\JWT\JWT;

/**
 * Tests for the access rules of the download endpoint.
 *
 * @package    fileconverter_docserver
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(source_access::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(download_token::class)]
final class source_access_test extends \advanced_testcase {
    /** @var string URL signing secret used in the tests. */
    private const URLSECRET = 'urlsecret-for-tests-0123456789abcdef0123456789abcdef0123456789ab';

    /** @var string JWT secret used in the tests. */
    private const JWTSECRET = 'jwt-secret-for-tests';

    /**
     * Configuration used by the tests.
     *
     * @param string $jwtsecret JWT secret, empty to disable JWT.
     * @return config
     */
    private function make_config(string $jwtsecret = ''): config {
        return new config('https://docs.example.org', '', $jwtsecret, true, 30, 1800, self::URLSECRET);
    }

    /**
     * Create a source file and a conversion record.
     *
     * @param int $status Conversion status.
     * @param string $convertername Converter class stored in the record.
     * @return array [conversion, stored_file]
     */
    private function make_conversion(
        int $status = conversion::STATUS_IN_PROGRESS,
        string $convertername = converter::CLASSNAME
    ): array {
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'fileconverter_docserver',
            'filearea' => 'unittest',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'submission.docx',
        ], 'secret student text');
        $conversion = new conversion(0, (object) [
            'sourcefileid' => $file->get_id(),
            'targetformat' => 'pdf',
            'converter' => $convertername,
            'status' => $status,
        ]);
        $conversion->create();
        return [$conversion, $file];
    }

    /**
     * A correctly signed request for a running conversion gets the file.
     */
    public function test_valid_signature_releases_file(): void {
        $this->resetAfterTest();
        [$conversion, $file] = $this->make_conversion();
        $expires = time() + 600;
        $signature = download_token::sign(self::URLSECRET, $conversion->get('id'), $file, $expires);

        $result = source_access::resolve($this->make_config(), $conversion->get('id'), $expires, $signature, '', time());

        $this->assertSame((int) $file->get_id(), (int) $result->get_id());
    }

    /**
     * Guessing ids, tampering, expiry and finished conversions are all refused.
     */
    public function test_invalid_requests_are_refused(): void {
        $this->resetAfterTest();
        $config = $this->make_config();
        [$conversion, $file] = $this->make_conversion();
        $id = (int) $conversion->get('id');
        $expires = time() + 600;
        $signature = download_token::sign(self::URLSECRET, $id, $file, $expires);

        $this->assertNull(source_access::resolve($config, $id, $expires, str_repeat('0', 64), '', time()));
        $this->assertNull(source_access::resolve($config, $id, $expires + 1, $signature, '', time()));
        $this->assertNull(source_access::resolve($config, $id + 1, $expires, $signature, '', time()));
        $this->assertNull(source_access::resolve($config, $id, $expires, $signature, '', $expires + 1));

        $conversion->set('status', conversion::STATUS_COMPLETE)->update();
        $this->assertNull(source_access::resolve($config, $id, $expires, $signature, '', time()));
    }

    /**
     * Conversions of other converters are never served.
     */
    public function test_other_converter_is_refused(): void {
        $this->resetAfterTest();
        [$conversion, $file] = $this->make_conversion(conversion::STATUS_IN_PROGRESS, '\\fileconverter_unoconv\\converter');
        $expires = time() + 600;
        $signature = download_token::sign(self::URLSECRET, $conversion->get('id'), $file, $expires);

        $this->assertNull(source_access::resolve($this->make_config(), $conversion->get('id'), $expires, $signature, '', time()));
    }

    /**
     * With a JWT secret, the download needs the document server's token for this very URL.
     */
    public function test_jwt_is_required_and_bound_to_url(): void {
        $this->resetAfterTest();
        $config = $this->make_config(self::JWTSECRET);
        [$conversion, $file] = $this->make_conversion();
        $id = (int) $conversion->get('id');
        $expires = time() + 600;
        $signature = download_token::sign(self::URLSECRET, $id, $file, $expires);
        $url = source_access::build_url($config, $id, $file, 'docx', $expires);
        $valid = 'Bearer ' . JWT::encode(['payload' => ['url' => $url], 'operation' => 'download'], self::JWTSECRET, 'HS256');
        $wrongsecret = 'Bearer ' . JWT::encode(['payload' => ['url' => $url]], 'other-secret', 'HS256');
        $otherurl = 'Bearer ' . JWT::encode(
            ['payload' => ['url' => 'https://moodle.example.org/x/' . str_repeat('1', 64) . '/a']],
            self::JWTSECRET,
            'HS256'
        );

        $this->assertNull(source_access::resolve($config, $id, $expires, $signature, '', time()));
        $this->assertNull(source_access::resolve($config, $id, $expires, $signature, $wrongsecret, time()));
        $this->assertNull(source_access::resolve($config, $id, $expires, $signature, $otherurl, time()));
        $released = source_access::resolve($config, $id, $expires, $signature, $valid, time());
        $this->assertSame((int) $file->get_id(), (int) $released->get_id());
    }

    /**
     * The download URL uses the alternative Moodle URL when configured and hides the file name.
     */
    public function test_build_url_uses_internal_moodle_url(): void {
        global $CFG;
        $this->resetAfterTest();
        [$conversion, $file] = $this->make_conversion();
        $config = new config('https://docs.example.org', 'http://moodle-internal:8080', '', true, 30, 1800, self::URLSECRET);

        $url = source_access::build_url($config, $conversion->get('id'), $file, 'docx', time() + 600);

        $this->assertStringStartsWith('http://moodle-internal:8080/', $url);
        $this->assertStringNotContainsString($CFG->wwwroot, $url);
        $this->assertStringEndsWith('/document.docx', $url);
    }
}
