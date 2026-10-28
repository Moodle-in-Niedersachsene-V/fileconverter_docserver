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

namespace fileconverter_docserver;

use core_files\conversion;
use fileconverter_docserver\local\config;
use fileconverter_docserver\local\docserver_client;
use fileconverter_docserver\local\docserver_exception;
use fileconverter_docserver\local\download_token;

/**
 * Tests for the converter.
 *
 * The document server client is the only unmanaged dependency and is replaced by a recording double.
 *
 * @package    fileconverter_docserver
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(converter::class)]
final class converter_test extends \advanced_testcase {
    /**
     * Configuration used by the tests.
     *
     * @param int $maxduration Maximum conversion duration.
     * @return config
     */
    private function make_config(int $maxduration = 1800): config {
        return new config('https://docs.example.org', '', 'jwtsecret', true, 30, $maxduration, str_repeat('a', 64));
    }

    /**
     * Client double that records requests and answers with prepared results.
     *
     * @param config $config Configuration.
     * @param array $answers Results (stdClass) or exceptions, returned in order.
     * @return docserver_client
     */
    private function make_client(config $config, array $answers): docserver_client {
        return new class ($config, $answers) extends docserver_client {
            /** @var array Recorded request parameters. */
            public array $requests = [];

            /**
             * Create the double.
             *
             * @param config $config Configuration.
             * @param array $answers Prepared answers.
             */
            public function __construct(
                config $config,
                /** @var array Prepared answers. */
                private array $answers,
            ) {
                parent::__construct($config);
            }

            /**
             * Record the request and return the next answer.
             *
             * @param array $params Request parameters.
             * @return \stdClass
             */
            public function request_conversion(array $params): \stdClass {
                $this->requests[] = $params;
                $answer = array_shift($this->answers);
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }
                return $answer;
            }

            /**
             * Write a minimal PDF instead of downloading.
             *
             * @param string $url Result URL.
             * @param string $targetpath Target path.
             */
            public function download_result(string $url, string $targetpath): void {
                file_put_contents($targetpath, "%PDF-1.4\n% test\n");
            }
        };
    }

    /**
     * Create a submission-like file and a conversion record as core does before starting.
     *
     * @param string $filename File name of the source file.
     * @return conversion
     */
    private function make_conversion(string $filename = 'Max Mustermann Aufsatz.docx'): conversion {
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'fileconverter_docserver',
            'filearea' => 'unittest',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], 'content of ' . $filename);
        $conversion = new conversion(0, (object) [
            'sourcefileid' => $file->get_id(),
            'targetformat' => 'pdf',
            'converter' => converter::CLASSNAME,
            'status' => conversion::STATUS_IN_PROGRESS,
        ]);
        $conversion->create();
        return $conversion;
    }

    /**
     * A started conversion is in progress and the request carries no personal data.
     */
    public function test_start_sends_signed_request_without_file_name(): void {
        $this->resetAfterTest();
        $config = $this->make_config();
        $client = $this->make_client($config, [(object) ['endConvert' => false, 'percent' => 10, 'fileUrl' => '']]);
        $conversion = $this->make_conversion();
        $sink = $this->redirectEvents();

        (new converter($config, $client))->start_document_conversion($conversion);

        $this->assertSame(conversion::STATUS_IN_PROGRESS, (int) $conversion->get('status'));
        $this->assertCount(1, $client->requests);
        $request = $client->requests[0];
        $this->assertSame('docx', $request['filetype']);
        $this->assertSame('pdf', $request['outputtype']);
        $this->assertSame('document.docx', $request['title']);
        $this->assertStringNotContainsString('Mustermann', $request['url']);
        $this->assertMatchesRegularExpression('~/fileconverter_docserver/source/' . $conversion->get('id')
            . '/\d+/[0-9a-f]{64}/document\.docx$~', $request['url']);
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z_]{1,128}$/', $request['key']);
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(event\conversion_requested::class, $events[0]);
    }

    /**
     * Polling with the same key and a finished answer stores the PDF.
     */
    public function test_poll_stores_result_when_finished(): void {
        $this->resetAfterTest();
        $config = $this->make_config();
        $client = $this->make_client($config, [
            (object) ['endConvert' => false, 'percent' => 0, 'fileUrl' => ''],
            (object) ['endConvert' => true, 'percent' => 100, 'fileUrl' => 'https://docs.example.org/cache/result.pdf'],
        ]);
        $conversion = $this->make_conversion();
        $converter = new converter($config, $client);

        $converter->start_document_conversion($conversion);
        $converter->poll_conversion_status($conversion);

        $this->assertSame(conversion::STATUS_COMPLETE, (int) $conversion->get('status'));
        $this->assertSame($client->requests[0]['key'], $client->requests[1]['key']);
        $this->assertStringStartsWith('%PDF-', $conversion->get_destfile()->get_content());
    }

    /**
     * A server error marks the conversion as failed with a reason and raises an event.
     */
    public function test_server_error_marks_conversion_failed(): void {
        $this->resetAfterTest();
        $config = $this->make_config();
        $client = $this->make_client($config, [
            new docserver_exception('error:invalidtoken', null, 'Conversion API error -8 (invalid token)'),
        ]);
        $conversion = $this->make_conversion();
        $sink = $this->redirectEvents();

        (new converter($config, $client))->start_document_conversion($conversion);

        $this->assertSame(conversion::STATUS_FAILED, (int) $conversion->get('status'));
        $this->assertStringContainsString('error:invalidtoken', $conversion->get('statusmessage'));
        $failed = array_filter($sink->get_events(), fn($e) => $e instanceof event\conversion_failed);
        $this->assertCount(1, $failed);
    }

    /**
     * A conversion running longer than the maximum duration fails without asking the server again.
     */
    public function test_poll_fails_after_max_duration(): void {
        $this->resetAfterTest();
        $config = $this->make_config(60);
        $client = $this->make_client($config, [(object) ['endConvert' => false, 'percent' => 0, 'fileUrl' => '']]);
        $conversion = $this->make_conversion();
        $converter = new converter($config, $client);
        $converter->start_document_conversion($conversion);
        $data = $conversion->get('data');
        $data->docserverstarted = time() - 120;
        $conversion->set('data', $data);

        $converter->poll_conversion_status($conversion);

        $this->assertSame(conversion::STATUS_FAILED, (int) $conversion->get('status'));
        $this->assertStringContainsString('error:timeout', $conversion->get('statusmessage'));
        $this->assertCount(1, $client->requests);
    }

    /**
     * Unsupported formats fail before anything is sent.
     */
    public function test_unsupported_format_is_not_sent(): void {
        $this->resetAfterTest();
        $config = $this->make_config();
        $client = $this->make_client($config, []);
        $conversion = $this->make_conversion('script.exe');

        (new converter($config, $client))->start_document_conversion($conversion);

        $this->assertSame(conversion::STATUS_FAILED, (int) $conversion->get('status'));
        $this->assertSame([], $client->requests);
    }

    /**
     * The supported conversions match office formats to PDF only.
     */
    public function test_supports(): void {
        $this->assertTrue(converter::supports('docx', 'pdf'));
        $this->assertTrue(converter::supports('PPTX', 'pdf'));
        $this->assertTrue(converter::supports('csv', 'pdf'));
        $this->assertFalse(converter::supports('pdf', 'pdf'));
        $this->assertFalse(converter::supports('docx', 'png'));
    }

    /**
     * The converter only reports itself usable when the settings are complete.
     */
    public function test_requirements_follow_settings(): void {
        $this->resetAfterTest();
        set_config('docserverurl', '', 'fileconverter_docserver');
        $this->assertFalse(converter::are_requirements_met());

        set_config('docserverurl', 'https://docs.example.org/', 'fileconverter_docserver');
        $this->assertTrue(converter::are_requirements_met());

        set_config('docserverurl', 'ftp://docs.example.org', 'fileconverter_docserver');
        $this->assertFalse(converter::are_requirements_met());
    }
}
