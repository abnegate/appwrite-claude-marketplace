<?php

declare(strict_types=1);

namespace Marketplace\Index;

final class Catalogue
{
    /**
     * @return Category[]
     */
    public static function all(): array
    {
        return [
            new Category('framework', 'Framework core', [
                'utopia-http-expert',
                'utopia-di-expert',
                'utopia-servers-expert',
                'utopia-platform-expert',
                'utopia-config-expert',
            ]),
            new Category('data', 'Data layer', [
                'utopia-database-expert',
                'utopia-mongo-expert',
                'utopia-query-expert',
                'utopia-pools-expert',
                'utopia-dsn-expert',
            ]),
            new Category('storage-io', 'Storage & I/O', [
                'utopia-storage-expert',
                'utopia-cache-expert',
                'utopia-fetch-expert',
                'utopia-compression-expert',
                'utopia-migration-expert',
            ]),
            new Category('auth-security', 'Auth & security', [
                'utopia-auth-expert',
                'utopia-jwt-expert',
                'utopia-abuse-expert',
                'utopia-waf-expert',
                'utopia-validators-expert',
            ]),
            new Category('runtime', 'Runtime & system', [
                'utopia-cli-expert',
                'utopia-system-expert',
                'utopia-orchestration-expert',
                'utopia-preloader-expert',
                'utopia-proxy-expert',
            ]),
            new Category('observability', 'Observability', [
                'utopia-logger-expert',
                'utopia-telemetry-expert',
                'utopia-audit-expert',
                'utopia-analytics-expert',
                'utopia-span-expert',
            ]),
            new Category('messaging-async', 'Messaging & async', [
                'utopia-messaging-expert',
                'utopia-queue-expert',
                'utopia-websocket-expert',
                'utopia-async-expert',
                'utopia-emails-expert',
            ]),
            new Category('domain', 'Domain logic', [
                'utopia-pay-expert',
                'utopia-vcs-expert',
                'utopia-domains-expert',
                'utopia-dns-expert',
                'utopia-locale-expert',
            ]),
            new Category('utilities', 'Utilities', [
                'utopia-ab-expert',
                'utopia-registry-expert',
                'utopia-detector-expert',
                'utopia-image-expert',
                'utopia-agents-expert',
            ]),
            new Category('misc', 'Misc', [
                'utopia-console-expert',
                'utopia-cloudevents-expert',
                'utopia-clickhouse-expert',
                'utopia-balancer-expert',
                'utopia-usage-expert',
            ]),
        ];
    }

    public static function lookup(string $skillName): string
    {
        foreach (self::all() as $category) {
            if (in_array($skillName, $category->skills, true)) {
                return $category->key;
            }
        }
        return 'other';
    }
}
