<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Mercure\HmacTokenProvider;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function base64_encode;
use function ceil;
use function explode;
use function hash_equals;
use function hash_hmac;
use function json_decode;
use function rtrim;
use function str_pad;
use function strlen;
use function strtr;

use const STR_PAD_RIGHT;

final class HmacTokenProviderTest extends TestCase
{
    public function testProducesAWellFormedJwtSignedWithTheSecret(): void
    {
        $provider = new HmacTokenProvider('super-secret');
        $jwt = $provider->getJwt();

        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);

        [$header, $payload, $signature] = $parts;

        $decodedHeader = json_decode($this->base64UrlDecode($header), true);
        self::assertSame(['typ' => 'JWT', 'alg' => 'HS256'], $decodedHeader);

        $decodedPayload = json_decode($this->base64UrlDecode($payload), true);
        self::assertSame(['mercure' => ['publish' => ['*']]], $decodedPayload);

        $expectedSignature = $this->base64UrlEncode(
            hash_hmac('sha256', $header . '.' . $payload, 'super-secret', true),
        );
        self::assertTrue(hash_equals($expectedSignature, $signature));
    }

    public function testDifferentSecretsProduceDifferentSignatures(): void
    {
        $a = (new HmacTokenProvider('secret-a'))->getJwt();
        $b = (new HmacTokenProvider('secret-b'))->getJwt();

        self::assertNotSame($a, $b);
    }

    private function base64UrlDecode(string $data): string
    {
        $padded = str_pad($data, (int) (4 * ceil(strlen($data) / 4)), '=', STR_PAD_RIGHT);

        return (string) base64_decode(strtr($padded, '-_', '+/'), true);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
