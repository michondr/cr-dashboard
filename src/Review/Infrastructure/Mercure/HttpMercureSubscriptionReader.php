<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Mercure;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;

use function count;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt;
use function is_array;
use function is_string;
use function json_decode;
use function rtrim;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;

/**
 * Queries the Mercure hub's subscription API directly (enabled via
 * `subscriptions` in docker/mercure.Caddyfile), authenticated with the same
 * HMAC JWT used for publishing (`subscribe: ["*"]` claim added alongside
 * `publish`, see HmacTokenProvider).
 */
final class HttpMercureSubscriptionReader implements MercureSubscriptionReaderInterface
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly TokenProviderInterface $tokenProvider,
    ) {
    }

    public function activeSubscriberCount(string $topic): int
    {
        $handle = curl_init(rtrim($this->hub->getUrl(), '/') . '/subscriptions/' . $topic);
        if ($handle === false) {
            return 0;
        }

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, 2);
        curl_setopt($handle, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->tokenProvider->getJwt(),
        ]);

        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if (!is_string($body) || $status !== 200) {
            return 0;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !is_array($decoded['subscriptions'] ?? null)) {
            return 0;
        }

        $subscribers = [];
        foreach ($decoded['subscriptions'] as $subscription) {
            if (!is_array($subscription) || ($subscription['active'] ?? false) !== true) {
                continue;
            }
            $subscriber = $subscription['subscriber'] ?? null;
            if (is_string($subscriber) && $subscriber !== '') {
                $subscribers[$subscriber] = true;
            }
        }

        return count($subscribers);
    }
}
