<?php
declare(strict_types=1);

namespace Twitkey\Models;

use Twitkey\Core\Database;
use Twitkey\Core\Helpers;

final class TwoFactor
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * True when the account requires a second factor during login.
     *
     * @param array<string, mixed> $user
     */
    public static function isEnabled(array $user): bool
    {
        return (int)($user['totp_enabled'] ?? 0) === 1 || self::passkeyCount((int)$user['id']) > 0;
    }

    /**
     * Return stored passkeys for settings and login.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function passkeys(int $userId): array
    {
        return Database::instance()->all(
            'SELECT id, credential_id, name, sign_count, created_at, last_used_at
             FROM two_factor_passkeys
             WHERE user_id = :user_id
             ORDER BY created_at DESC',
            ['user_id' => $userId]
        );
    }

    /**
     * Count passkeys for an account.
     */
    public static function passkeyCount(int $userId): int
    {
        $row = Database::instance()->one('SELECT COUNT(*) AS count FROM two_factor_passkeys WHERE user_id = :user_id', ['user_id' => $userId]);
        return (int)($row['count'] ?? 0);
    }

    /**
     * Create or return a pending TOTP setup secret.
     */
    public static function ensureTotpSecret(int $userId): string
    {
        $user = User::find($userId);
        if (!$user) {
            throw new \InvalidArgumentException('User not found.');
        }
        $secret = (string)($user['totp_secret'] ?? '');
        if ($secret !== '') {
            return $secret;
        }
        $secret = self::base32Encode(random_bytes(20));
        Database::instance()->execute(
            'UPDATE users SET totp_secret = :secret, updated_at = :updated_at WHERE id = :id',
            ['secret' => $secret, 'updated_at' => date('Y-m-d H:i:s'), 'id' => $userId]
        );
        return $secret;
    }

    /**
     * Return a Google Authenticator-compatible otpauth URL.
     *
     * @param array<string, mixed> $user
     */
    public static function otpauthUrl(array $user, string $secret): string
    {
        $issuer = Helpers::env('APP_NAME', 'Twitkey');
        $label = $issuer . ':' . (string)$user['username'];
        return 'otpauth://totp/' . rawurlencode($label)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    /**
     * Enable TOTP after verifying the current authenticator code.
     */
    public static function enableTotp(int $userId, string $code): void
    {
        $secret = self::ensureTotpSecret($userId);
        if (!self::verifyTotp($secret, $code)) {
            throw new \InvalidArgumentException('Authenticator code is invalid.');
        }
        Database::instance()->execute(
            'UPDATE users SET totp_enabled = 1, updated_at = :updated_at WHERE id = :id',
            ['updated_at' => date('Y-m-d H:i:s'), 'id' => $userId]
        );
    }

    /**
     * Disable TOTP after a valid current code.
     */
    public static function disableTotp(int $userId, string $code): void
    {
        $user = User::find($userId);
        $secret = (string)($user['totp_secret'] ?? '');
        if ($secret === '' || !self::verifyTotp($secret, $code)) {
            throw new \InvalidArgumentException('Authenticator code is invalid.');
        }
        Database::instance()->execute(
            'UPDATE users SET totp_enabled = 0, totp_secret = NULL, updated_at = :updated_at WHERE id = :id',
            ['updated_at' => date('Y-m-d H:i:s'), 'id' => $userId]
        );
    }

    /**
     * Verify a six-digit TOTP code with one time-step of clock drift.
     */
    public static function verifyTotp(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $secretBytes = self::base32Decode($secret);
        $counter = intdiv(time(), 30);
        for ($drift = -1; $drift <= 1; $drift++) {
            if (hash_equals(self::totpCode($secretBytes, $counter + $drift), $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return public-key creation options for navigator.credentials.create().
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function passkeyCreationOptions(array $user): array
    {
        $challenge = self::base64url(random_bytes(32));
        $_SESSION['passkey_register_challenge'] = $challenge;
        return [
            'challenge' => $challenge,
            'rp' => ['name' => Helpers::env('APP_NAME', 'Twitkey'), 'id' => self::rpId()],
            'user' => [
                'id' => self::base64url(pack('N', (int)$user['id'])),
                'name' => (string)$user['username'],
                'displayName' => (string)$user['display_name'],
            ],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]],
            'excludeCredentials' => array_map(
                static fn(array $key): array => ['type' => 'public-key', 'id' => (string)$key['credential_id']],
                self::passkeys((int)$user['id'])
            ),
            'timeout' => 60000,
            'attestation' => 'none',
            'authenticatorSelection' => [
                'residentKey' => 'required',
                'requireResidentKey' => true,
                'userVerification' => 'required',
            ],
        ];
    }

    /**
     * Store a newly-created ES256 passkey after validating the WebAuthn response.
     *
     * @param array<string, mixed> $payload
     */
    public static function registerPasskey(int $userId, array $payload, string $name): void
    {
        $clientData = self::jsonFromB64((string)($payload['response']['clientDataJSON'] ?? ''));
        self::verifyClientData($clientData, 'webauthn.create', (string)($_SESSION['passkey_register_challenge'] ?? ''));

        $attestation = self::cborDecode(self::base64urlDecode((string)($payload['response']['attestationObject'] ?? '')));
        if (!is_array($attestation) || !isset($attestation['authData']) || !is_string($attestation['authData'])) {
            throw new \RuntimeException('Passkey attestation is invalid.');
        }
        $attested = self::parseAttestedCredential($attestation['authData']);
        $credentialId = self::base64url($attested['credential_id']);
        if (!hash_equals($credentialId, (string)($payload['id'] ?? ''))) {
            throw new \RuntimeException('Passkey credential id mismatch.');
        }
        $name = Helpers::mbLimit(trim($name), 80);
        Database::instance()->execute(
            'INSERT INTO two_factor_passkeys (user_id, credential_id, public_key, alg, sign_count, name)
             VALUES (:user_id, :credential_id, :public_key, :alg, :sign_count, :name)',
            [
                'user_id' => $userId,
                'credential_id' => $credentialId,
                'public_key' => $attested['public_key'],
                'alg' => -7,
                'sign_count' => $attested['sign_count'],
                'name' => $name !== '' ? $name : 'Passkey',
            ]
        );
        unset($_SESSION['passkey_register_challenge']);
    }

    /**
     * Return public-key request options for navigator.credentials.get().
     *
     * @return array<string, mixed>
     */
    public static function passkeyRequestOptions(int $userId): array
    {
        $keys = self::passkeys($userId);
        if ($keys === []) {
            throw new \RuntimeException('No passkeys are registered for this account.');
        }
        $challenge = self::base64url(random_bytes(32));
        $_SESSION['passkey_login_challenge'] = $challenge;
        return [
            'challenge' => $challenge,
            'rpId' => self::rpId(),
            'timeout' => 60000,
            'userVerification' => 'preferred',
            'allowCredentials' => array_map(
                static fn(array $key): array => ['type' => 'public-key', 'id' => (string)$key['credential_id']],
                $keys
            ),
        ];
    }

    /**
     * Return request options for a passwordless passkey login.
     *
     * @return array<string, mixed>
     */
    public static function passwordlessPasskeyRequestOptions(): array
    {
        $challenge = self::base64url(random_bytes(32));
        $_SESSION['passkey_passwordless_challenge'] = $challenge;
        return [
            'challenge' => $challenge,
            'rpId' => self::rpId(),
            'timeout' => 60000,
            'userVerification' => 'required',
        ];
    }

    /**
     * Verify a passkey assertion for the pending login user.
     *
     * @param array<string, mixed> $payload
     */
    public static function verifyPasskeyAssertion(int $userId, array $payload): void
    {
        $credentialId = (string)($payload['id'] ?? '');
        $key = Database::instance()->one(
            'SELECT * FROM two_factor_passkeys WHERE user_id = :user_id AND credential_id = :credential_id',
            ['user_id' => $userId, 'credential_id' => $credentialId]
        );
        if (!$key) {
            throw new \RuntimeException('Passkey is not registered for this account.');
        }
        self::verifyAssertionWithKey($key, $payload, (string)($_SESSION['passkey_login_challenge'] ?? ''));
        unset($_SESSION['passkey_login_challenge']);
    }

    /**
     * Verify a discoverable passkey assertion and return its user.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function verifyPasswordlessPasskeyAssertion(array $payload): array
    {
        $credentialId = (string)($payload['id'] ?? '');
        $key = Database::instance()->one(
            'SELECT pk.*, u.is_system, u.is_suspended, u.is_deleted
             FROM two_factor_passkeys pk
             JOIN users u ON u.id = pk.user_id
             WHERE pk.credential_id = :credential_id
             LIMIT 1',
            ['credential_id' => $credentialId]
        );
        if (!$key) {
            throw new \RuntimeException('Passkey is not registered.');
        }
        if ((int)($key['is_system'] ?? 0) === 1 || (int)($key['is_suspended'] ?? 0) === 1 || (int)($key['is_deleted'] ?? 0) === 1) {
            throw new \RuntimeException('That account cannot sign in.');
        }
        self::verifyAssertionWithKey($key, $payload, (string)($_SESSION['passkey_passwordless_challenge'] ?? ''));
        unset($_SESSION['passkey_passwordless_challenge']);
        $user = User::find((int)$key['user_id']);
        if (!$user) {
            throw new \RuntimeException('Passkey account no longer exists.');
        }
        return $user;
    }

    /**
     * Verify an assertion against one stored passkey.
     *
     * @param array<string, mixed> $key
     * @param array<string, mixed> $payload
     */
    private static function verifyAssertionWithKey(array $key, array $payload, string $challenge): void
    {
        $clientDataJson = self::base64urlDecode((string)($payload['response']['clientDataJSON'] ?? ''));
        $clientData = json_decode($clientDataJson, true);
        if (!is_array($clientData)) {
            throw new \RuntimeException('Passkey client data is invalid.');
        }
        self::verifyClientData($clientData, 'webauthn.get', $challenge);

        $authenticatorData = self::base64urlDecode((string)($payload['response']['authenticatorData'] ?? ''));
        self::verifyRpAndUserPresent($authenticatorData);
        $signature = self::base64urlDecode((string)($payload['response']['signature'] ?? ''));
        $signed = $authenticatorData . hash('sha256', $clientDataJson, true);
        if (!function_exists('openssl_verify')) {
            throw new \RuntimeException('Passkey verification requires the PHP OpenSSL extension.');
        }
        $verified = openssl_verify($signed, $signature, (string)$key['public_key'], OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new \RuntimeException('Passkey signature is invalid.');
        }

        $signCount = self::signCount($authenticatorData);
        Database::instance()->execute(
            'UPDATE two_factor_passkeys
             SET sign_count = CASE WHEN :sign_count > sign_count THEN :sign_count ELSE sign_count END,
                 last_used_at = :last_used_at
             WHERE id = :id',
            ['sign_count' => $signCount, 'last_used_at' => date('Y-m-d H:i:s'), 'id' => (int)$key['id']]
        );
    }

    /**
     * Delete a passkey owned by a user.
     */
    public static function deletePasskey(int $userId, int $passkeyId): void
    {
        Database::instance()->execute('DELETE FROM two_factor_passkeys WHERE id = :id AND user_id = :user_id', ['id' => $passkeyId, 'user_id' => $userId]);
    }

    private static function totpCode(string $secretBytes, int $counter): string
    {
        $binaryCounter = pack('N2', intdiv($counter, 0x100000000), $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $binaryCounter, $secretBytes, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0, $length = strlen($bits); $i < $length; $i += 5) {
            $chunk = substr($bits, $i, 5);
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $out .= self::BASE32_ALPHABET[bindec($chunk)];
        }
        return $out;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
        $bits = '';
        for ($i = 0, $length = strlen($secret); $i < $length; $i++) {
            $index = strpos(self::BASE32_ALPHABET, $secret[$i]);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        for ($i = 0, $length = strlen($bits) - 7; $i < $length; $i += 8) {
            $bytes .= chr(bindec(substr($bits, $i, 8)));
        }
        return $bytes;
    }

    private static function jsonFromB64(string $value): array
    {
        $json = json_decode(self::base64urlDecode($value), true);
        if (!is_array($json)) {
            throw new \RuntimeException('Passkey client data is invalid.');
        }
        return $json;
    }

    private static function verifyClientData(array $clientData, string $type, string $challenge): void
    {
        if ($challenge === '' || (string)($clientData['type'] ?? '') !== $type || !hash_equals($challenge, (string)($clientData['challenge'] ?? ''))) {
            throw new \RuntimeException('Passkey challenge is invalid.');
        }
        if (strtolower((string)($clientData['origin'] ?? '')) !== self::origin()) {
            throw new \RuntimeException('Passkey origin is invalid.');
        }
    }

    private static function verifyRpAndUserPresent(string $authenticatorData): void
    {
        if (strlen($authenticatorData) < 37) {
            throw new \RuntimeException('Passkey authenticator data is invalid.');
        }
        if (!hash_equals(substr($authenticatorData, 0, 32), hash('sha256', self::rpId(), true))) {
            throw new \RuntimeException('Passkey relying party is invalid.');
        }
        if ((ord($authenticatorData[32]) & 0x01) !== 0x01) {
            throw new \RuntimeException('Passkey did not confirm user presence.');
        }
    }

    /**
     * @return array{credential_id:string,public_key:string,sign_count:int}
     */
    private static function parseAttestedCredential(string $authData): array
    {
        self::verifyRpAndUserPresent($authData);
        if ((ord($authData[32]) & 0x40) !== 0x40 || strlen($authData) < 55) {
            throw new \RuntimeException('Passkey attested credential data is missing.');
        }
        $signCount = self::signCount($authData);
        $offset = 37 + 16;
        $credentialLength = unpack('n', substr($authData, $offset, 2))[1];
        $offset += 2;
        $credentialId = substr($authData, $offset, $credentialLength);
        $offset += $credentialLength;
        $cose = self::cborDecode(substr($authData, $offset));
        if (!is_array($cose) || (int)($cose[3] ?? 0) !== -7 || (int)($cose[1] ?? 0) !== 2 || (int)($cose[-1] ?? 0) !== 1) {
            throw new \RuntimeException('Only ES256 passkeys are supported.');
        }
        $x = $cose[-2] ?? null;
        $y = $cose[-3] ?? null;
        if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            throw new \RuntimeException('Passkey public key is invalid.');
        }
        return [
            'credential_id' => $credentialId,
            'public_key' => self::ecP256Pem($x, $y),
            'sign_count' => $signCount,
        ];
    }

    private static function signCount(string $authData): int
    {
        return unpack('N', substr($authData, 33, 4))[1];
    }

    private static function cborDecode(string $data): mixed
    {
        $offset = 0;
        return self::cborValue($data, $offset);
    }

    private static function cborValue(string $data, int &$offset): mixed
    {
        if ($offset >= strlen($data)) {
            throw new \RuntimeException('CBOR data ended unexpectedly.');
        }
        $initial = ord($data[$offset++]);
        $major = $initial >> 5;
        $additional = $initial & 0x1f;
        $length = self::cborLength($data, $offset, $additional);
        if ($major === 0) {
            return $length;
        }
        if ($major === 1) {
            return -1 - $length;
        }
        if ($major === 2 || $major === 3) {
            $value = substr($data, $offset, $length);
            $offset += $length;
            return $value;
        }
        if ($major === 4) {
            $items = [];
            for ($i = 0; $i < $length; $i++) {
                $items[] = self::cborValue($data, $offset);
            }
            return $items;
        }
        if ($major === 5) {
            $map = [];
            for ($i = 0; $i < $length; $i++) {
                $key = self::cborValue($data, $offset);
                $map[$key] = self::cborValue($data, $offset);
            }
            return $map;
        }
        if ($major === 7) {
            return match ($additional) {
                20 => false,
                21 => true,
                22 => null,
                default => $length,
            };
        }
        throw new \RuntimeException('Unsupported CBOR value.');
    }

    private static function cborLength(string $data, int &$offset, int $additional): int
    {
        if ($additional < 24) {
            return $additional;
        }
        $bytes = match ($additional) {
            24 => 1,
            25 => 2,
            26 => 4,
            default => throw new \RuntimeException('Unsupported CBOR length.'),
        };
        $raw = substr($data, $offset, $bytes);
        $offset += $bytes;
        return match ($bytes) {
            1 => ord($raw),
            2 => unpack('n', $raw)[1],
            4 => unpack('N', $raw)[1],
        };
    }

    private static function ecP256Pem(string $x, string $y): string
    {
        $point = "\x04" . $x . $y;
        $spki = self::derSequence(
            self::derSequence(self::derOid('1.2.840.10045.2.1'), self::derOid('1.2.840.10045.3.1.7')),
            self::derBitString($point)
        );
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derSequence(string ...$parts): string
    {
        $body = implode('', $parts);
        return "\x30" . self::derLength(strlen($body)) . $body;
    }

    private static function derBitString(string $value): string
    {
        $body = "\x00" . $value;
        return "\x03" . self::derLength(strlen($body)) . $body;
    }

    private static function derOid(string $oid): string
    {
        $parts = array_map('intval', explode('.', $oid));
        $body = chr($parts[0] * 40 + $parts[1]);
        for ($i = 2; $i < count($parts); $i++) {
            $value = $parts[$i];
            $encoded = chr($value & 0x7f);
            while ($value >>= 7) {
                $encoded = chr(($value & 0x7f) | 0x80) . $encoded;
            }
            $body .= $encoded;
        }
        return "\x06" . self::derLength(strlen($body)) . $body;
    }

    private static function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function base64urlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64url value.');
        }
        return $decoded;
    }

    private static function rpId(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? parse_url(Helpers::env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';
        return strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);
    }

    private static function origin(): string
    {
        $proto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        if ($proto === '') {
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        }
        $host = $_SERVER['HTTP_HOST'] ?? parse_url(Helpers::env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';
        return strtolower($proto) . '://' . strtolower((string)$host);
    }
}
