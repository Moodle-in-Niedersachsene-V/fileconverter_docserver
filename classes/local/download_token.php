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

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Signs and verifies the URL from which the document server downloads a source file.
 *
 * The document server cannot log in to Moodle, so the download endpoint is not session based.
 * Access is bound to an HMAC over the conversion, the file and an expiry time, keyed with a
 * site-local secret; optionally the JWT the document server sends with every download is checked too.
 *
 * @package    fileconverter_docserver
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class download_token {
    /** @var string HMAC algorithm for URL signatures. */
    private const HMAC_ALGO = 'sha256';

    /** @var string JWT algorithm used by the document server. */
    private const JWT_ALGO = 'HS256';

    /** @var int Allowed clock difference between Moodle and the document server in seconds. */
    private const JWT_LEEWAY = 60;

    /**
     * Create the signature for a download URL.
     *
     * @param string $urlsecret Site-local signing secret.
     * @param int $conversionid Id of the conversion record.
     * @param \stored_file $file Source file of the conversion.
     * @param int $expires Unix time after which the URL is no longer valid.
     * @return string Hex encoded signature.
     */
    public static function sign(string $urlsecret, int $conversionid, \stored_file $file, int $expires): string {
        if ($urlsecret === '') {
            throw new \coding_exception('The download URL secret of fileconverter_docserver is missing.');
        }
        $message = implode('|', [$conversionid, $file->get_id(), $file->get_contenthash(), $expires]);
        return hash_hmac(self::HMAC_ALGO, $message, $urlsecret);
    }

    /**
     * Check a download URL signature in constant time.
     *
     * @param string $urlsecret Site-local signing secret.
     * @param int $conversionid Id of the conversion record.
     * @param \stored_file $file Source file of the conversion.
     * @param int $expires Expiry time taken from the URL.
     * @param string $signature Signature taken from the URL.
     * @param int $now Current Unix time.
     * @return bool
     */
    public static function verify(
        string $urlsecret,
        int $conversionid,
        \stored_file $file,
        int $expires,
        string $signature,
        int $now
    ): bool {
        if ($urlsecret === '' || $expires < $now) {
            return false;
        }
        return hash_equals(self::sign($urlsecret, $conversionid, $file, $expires), $signature);
    }

    /**
     * Check the JWT the document server sends in the Authorization header of a download request.
     *
     * The token must be signed with the shared secret and its URL must contain the URL signature,
     * so a token captured for one file cannot be replayed for another.
     *
     * @param string $jwtsecret Shared JWT secret of the document server.
     * @param string $authorization Raw value of the Authorization header.
     * @param string $signature URL signature of the requested download.
     * @return bool
     */
    public static function verify_docserver_jwt(string $jwtsecret, string $authorization, string $signature): bool {
        if ($jwtsecret === '' || $signature === '') {
            return false;
        }
        if (!preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches)) {
            return false;
        }
        $previousleeway = JWT::$leeway;
        JWT::$leeway = self::JWT_LEEWAY;
        try {
            $claims = JWT::decode($matches[1], new Key($jwtsecret, self::JWT_ALGO));
        } catch (\Throwable $e) {
            // Any decoding failure (bad signature, expired, malformed) means the request is not from our server.
            return false;
        } finally {
            JWT::$leeway = $previousleeway;
        }
        $url = (string) ($claims->payload->url ?? $claims->url ?? '');
        return $url !== '' && str_contains($url, '/' . $signature . '/');
    }

    /**
     * Sign a request body for the document server.
     *
     * @param string $jwtsecret Shared JWT secret of the document server.
     * @param array $payload Request parameters.
     * @return string
     */
    public static function encode_request(string $jwtsecret, array $payload): string {
        return JWT::encode($payload, $jwtsecret, self::JWT_ALGO);
    }

    /**
     * Decode a signed response of the document server.
     *
     * @param string $jwtsecret Shared JWT secret of the document server.
     * @param string $token The token from the response body.
     * @return \stdClass The response parameters.
     */
    public static function decode_response(string $jwtsecret, string $token): \stdClass {
        try {
            $claims = JWT::decode($token, new Key($jwtsecret, self::JWT_ALGO));
        } catch (\Throwable $e) {
            throw new docserver_exception('error:invalidresponse', null, 'Response token rejected: ' . $e->getMessage());
        }
        return isset($claims->payload) && is_object($claims->payload) ? $claims->payload : $claims;
    }
}
