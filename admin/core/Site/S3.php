<?php

declare(strict_types=1);

namespace Cms\Site;

use RuntimeException;

/**
 * S3-COMPATIBLE STORAGE, BY SIGNED REQUESTS OVER CURL.
 *
 * Amazon S3, Backblaze B2, Wasabi, MinIO and the like all take the same API,
 * signed with AWS's Signature Version 4. This is the few calls a backup's copy
 * needs (`BackupCopy`), written here so nothing is added: an
 * object put whole or in parts (a multipart upload), a listing by prefix, and
 * a delete. Anything the storage refuses is a RuntimeException in its own
 * words, which never hold the secret.
 *
 * AN OBJECT'S ADDRESS is the bucket's own host, `https://<bucket>.<endpoint's
 * host>/<key>` ("virtual-hosted"), or with `$pathStyle` the endpoint's, the
 * bucket first in the path, `https://<endpoint's host>/<bucket>/<key>`, which
 * storage reached at an address of its own (MinIO, a local stand-in) needs.
 *
 * ONE CANONICAL FORM, SIGNED AND SENT: the path, the query and the headers are
 * built once and both signed and sent as built, so the request cannot differ
 * from what its signature says.
 */
final class S3
{
    private const TIMEOUT         = 600;
    private const CONNECT_TIMEOUT = 15;

    public function __construct(
        private string $endpoint,
        private string $region,
        private string $bucket,
        private string $key,
        private string $secret,
        private bool $pathStyle = false,
    ) {
    }

    /** "example-backups at s3.us-west-002.backblazeb2.com", for a sentence. */
    public function label(): string
    {
        return $this->bucket . ' at ' . (string) (parse_url($this->endpoint, PHP_URL_HOST) ?? $this->endpoint);
    }

    /** One object, whole. */
    public function put(string $object, string $body): void
    {
        $this->send('PUT', $object, [], $body);
    }

    /** A multipart upload begun: its id. */
    public function begin(string $object): string
    {
        [, $answer] = $this->send('POST', $object, ['uploads' => ''], '');
        if (preg_match('~<UploadId>([^<]+)</UploadId>~', $answer, $m) !== 1) {
            throw new RuntimeException('the storage began no upload: ' . self::clip($answer));
        }

        return self::text($m[1]);
    }

    /** One part of an upload, numbered from 1, every part but the last 5 MiB or more: its ETag. */
    public function part(string $object, string $upload, int $number, string $body): string
    {
        [, , $headers] = $this->send('PUT', $object, ['partNumber' => (string) $number, 'uploadId' => $upload], $body);
        $etag = trim((string) ($headers['etag'] ?? ''));
        if ($etag === '') {
            throw new RuntimeException('the storage gave part ' . $number . ' no ETag');
        }

        return $etag;
    }

    /**
     * An upload's parts put together as one object.
     *
     * @param list<string> $etags each part's ETag, in order
     */
    public function finish(string $object, string $upload, array $etags): void
    {
        $xml = '<CompleteMultipartUpload>';
        foreach ($etags as $i => $etag) {
            $xml .= '<Part><PartNumber>' . ($i + 1) . '</PartNumber><ETag>' . htmlspecialchars($etag, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</ETag></Part>';
        }
        $xml .= '</CompleteMultipartUpload>';
        [, $answer] = $this->send('POST', $object, ['uploadId' => $upload], $xml, ['content-type' => 'application/xml']);
        // AN ERROR CAN COME WITH A 200: the storage answers at once and says
        // how the joining went in the body.
        if (str_contains($answer, '<Error>')) {
            throw new RuntimeException(self::error(200, $answer));
        }
    }

    /** An upload given up, its parts deleted. */
    public function abort(string $object, string $upload): void
    {
        $this->send('DELETE', $object, ['uploadId' => $upload], '');
    }

    /**
     * Every object whose key begins with `$prefix`, page after page.
     *
     * @return list<array{key: string, size: int}>
     */
    public function list(string $prefix): array
    {
        $out   = [];
        $token = null;
        do {
            $query = ['list-type' => '2', 'prefix' => $prefix] + ($token !== null ? ['continuation-token' => $token] : []);
            [, $answer] = $this->send('GET', '', $query, '');
            preg_match_all('~<Contents>(.*?)</Contents>~s', $answer, $items);
            foreach ($items[1] as $item) {
                if (preg_match('~<Key>(.*?)</Key>~s', $item, $k) === 1) {
                    $out[] = ['key' => self::text($k[1]), 'size' => preg_match('~<Size>(\d+)</Size>~', $item, $s) === 1 ? (int) $s[1] : 0];
                }
            }
            $token = preg_match('~<IsTruncated>true</IsTruncated>~', $answer) === 1
                && preg_match('~<NextContinuationToken>(.*?)</NextContinuationToken>~s', $answer, $t) === 1
                ? self::text($t[1]) : null;
        } while ($token !== null);

        return $out;
    }

    /** One object deleted. */
    public function delete(string $object): void
    {
        $this->send('DELETE', $object, [], '');
    }

    /**
     * One signed request: its status, its body and its headers, their names
     * in lower case. Anything but a 2xx is refused in the storage's words.
     *
     * @param array<string, string> $query
     * @param array<string, string> $extra more headers to sign and send, names in lower case
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    private function send(string $method, string $object, array $query, string $body, array $extra = []): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException("this server's PHP has no curl");
        }
        $url    = parse_url($this->endpoint) ?: [];
        $scheme = strtolower((string) ($url['scheme'] ?? 'https'));
        $host   = strtolower((string) ($url['host'] ?? ''));
        $port   = isset($url['port']) && !in_array([$scheme, (int) $url['port']], [['https', 443], ['http', 80]], true) ? ':' . (int) $url['port'] : '';
        $where  = ($this->pathStyle ? $host : $this->bucket . '.' . $host) . $port;
        $path   = $this->pathStyle
            ? '/' . rawurlencode($this->bucket) . ($object !== '' ? '/' . self::encodePath($object) : '')
            : '/' . self::encodePath($object);

        ksort($query, SORT_STRING);
        $canonicalQuery = implode('&', array_map(
            static fn (string $k, string $v): string => rawurlencode($k) . '=' . rawurlencode($v), array_keys($query), array_values($query)));

        $now     = time();
        $amzDate = gmdate('Ymd\THis\Z', $now);
        $day     = gmdate('Ymd', $now);
        $payload = hash('sha256', $body);
        $headers = ['host' => $where, 'x-amz-content-sha256' => $payload, 'x-amz-date' => $amzDate] + $extra;
        ksort($headers, SORT_STRING);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim($value) . "\n";
        }
        $signed    = implode(';', array_keys($headers));
        $canonical = $method . "\n" . $path . "\n" . $canonicalQuery . "\n" . $canonicalHeaders . "\n" . $signed . "\n" . $payload;
        $scope     = $day . '/' . $this->region . '/s3/aws4_request';
        $toSign    = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonical);
        $signing   = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3',
            hash_hmac('sha256', $this->region, hash_hmac('sha256', $day, 'AWS4' . $this->secret, true), true), true), true);
        $lines     = ['Authorization: AWS4-HMAC-SHA256 Credential=' . $this->key . '/' . $scope . ', SignedHeaders=' . $signed
                         . ', Signature=' . hash_hmac('sha256', $toSign, $signing), 'Expect:'];
        foreach ($headers as $name => $value) {
            if ($name !== 'host') {
                $lines[] = $name . ': ' . $value;
            }
        }

        $handle = curl_init($scheme . '://' . $where . $path . ($canonicalQuery !== '' ? '?' . $canonicalQuery : ''));
        if ($handle === false) {
            throw new RuntimeException('curl could not start a request to the storage');
        }
        $got = [];
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            // The key signs the request: the certificate is checked.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$got): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $got[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($method === 'PUT' || $method === 'POST') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $answer = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($handle);

        if ($answer === false || $status === 0) {
            throw new RuntimeException('the storage could not be reached: ' . ($error !== '' ? $error : 'no answer'));
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(self::error($status, (string) $answer));
        }

        return [$status, (string) $answer, $got];
    }

    /** A refusal, as the storage gave it: its code and message, else the start of its answer. */
    private static function error(int $status, string $answer): string
    {
        $code    = preg_match('~<Code>(.*?)</Code>~s', $answer, $c) === 1 ? self::text($c[1]) : '';
        $message = preg_match('~<Message>(.*?)</Message>~s', $answer, $m) === 1 ? self::text($m[1]) : '';

        return 'the storage answered ' . $status . ($code !== '' ? ' ' . $code : '')
            . ($message !== '' ? ': ' . $message : ($code === '' && trim($answer) !== '' ? ': ' . self::clip($answer) : ''));
    }

    /** A key's path, each part encoded and the slashes kept. */
    private static function encodePath(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    private static function text(string $xml): string
    {
        return html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function clip(string $answer): string
    {
        return mb_substr(trim((string) preg_replace('~\s+~', ' ', strip_tags($answer))), 0, 200);
    }
}
