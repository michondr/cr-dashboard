<?php

declare(strict_types=1);

namespace App\Mercure;

use Symfony\Component\Mercure\Jwt\TokenProviderInterface;

use function base64_encode;
use function hash_hmac;
use function json_encode;
use function rtrim;
use function strtr;

/**
 * Signs a fixed JWT (`{"mercure":{"publish":["*"],"subscribe":["*"]}}`,
 * HS256) with `MERCURE_JWT_SECRET`. Avoids adding lcobucci/jwt as a
 * dependency: the Mercure hub only needs a standards-compliant HS256 token,
 * which is cheap to build with `hash_hmac` directly. The `subscribe` claim
 * (added alongside `publish` for item 7) lets the same token read the hub's
 * subscription API for the "N online" count.
 */
final class HmacTokenProvider implements TokenProviderInterface
{
    public function __construct(private readonly string $secret)
    {
    }

    public function getJwt(): string
    {
        $header = $this->encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = $this->encode(['mercure' => ['publish' => ['*'], 'subscribe' => ['*']]]);
        $signingInput = $header . '.' . $payload;
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $signingInput, $this->secret, true));

        return $signingInput . '.' . $signature;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return $this->base64UrlEncode((string) json_encode($data));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
