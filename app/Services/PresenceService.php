<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class PresenceService
{
    private const KEY = 'users:online';

    private const STALE_AFTER_SECONDS = 40;

    public static function heartbeat(int $userId): void
    {
        Redis::zadd(self::KEY, now()->timestamp, $userId);
    }

    public static function isOnline(int $userId): bool
    {
        return self::onlineStatuses([$userId])[$userId];
    }

    public static function onlineStatuses(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $scores = Redis::zmscore(self::KEY, ...$userIds);
        $threshold = self::threshold();

        return array_combine(
            $userIds,
            array_map(fn ($score) => $score !== null && (float) $score >= $threshold, $scores)
        );
    }

    public static function onlineUserIds(): array
    {
        return array_map('intval', Redis::zrangebyscore(self::KEY, self::threshold(), '+inf'));
    }

    public static function cleanup(): void
    {
        Redis::zremrangebyscore(self::KEY, '-inf', '('.self::threshold());
    }

    private static function threshold(): int
    {
        return now()->subSeconds(self::STALE_AFTER_SECONDS)->timestamp;
    }
}
