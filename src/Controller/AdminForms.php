<?php

declare(strict_types=1);

namespace Qoliber\TridentSylius\Controller;

/**
 * The action forms each screen offers: action => fields (`name => type`,
 * type: text | textarea | select:a|b|c). The server validates every field
 * again ({@see \Qoliber\Trident\Admin\AdminService::action()}).
 */
final class AdminForms
{
    private const FORMS = [
        'dashboard' => ['outbox_drain' => ['force' => 'select:0|1']],
        'purge' => [
            'purge_urls' => ['urls' => 'textarea'],
            'purge_tags' => ['tags' => 'textarea'],
            'purge_entities' => ['kind' => 'select:product|taxon', 'ids' => 'textarea'],
            'purge_pattern' => ['pattern' => 'text'],
            'purge_preview' => ['pattern' => 'text'],
            'purge_host' => ['host' => 'text'],
            'purge_all' => [],
        ],
        'warmer' => ['warmer_run' => ['sitemaps' => 'textarea'], 'warmer_cancel' => [], 'warmer_queue_shop' => ['limit' => 'text'], 'warmer_queue' => ['urls' => 'textarea']],
        'launch' => ['launch_start' => ['urls' => 'textarea'], 'launch_complete' => [], 'launch_abort' => ['reason' => 'text']],
        'reflect' => ['reflect_enable' => ['level' => 'select:|full|selective|ttl_extension', 'duration' => 'text', 'reason' => 'text'], 'reflect_disable' => ['mode' => 'select:|replay|hard']],
        'denoisers' => [
            'denoiser_query_pin' => ['param' => 'text', 'class' => 'select:noise|signal', 'host' => 'text', 'path_prefix' => 'text'],
            'denoiser_query_unpin' => ['param' => 'text', 'host' => 'text', 'path_prefix' => 'text'],
            'denoiser_path_pin' => ['status' => 'select:dead|alive', 'host' => 'text', 'path_prefix' => 'text'],
            'denoiser_path_unpin' => ['host' => 'text', 'path_prefix' => 'text'],
            'denoiser_zone_delete' => ['host' => 'text', 'path_prefix' => 'text'],
            'denoiser_scope_delete' => ['host' => 'text', 'path_prefix' => 'text'],
            'denoiser_reset' => ['which' => 'select:all|query|path'],
        ],
        'bans' => ['ban_create' => ['pattern' => 'text', 'type' => 'select:url|tag|pattern'], 'ban_delete' => ['id' => 'text']],
        'backends' => ['backend_drain' => ['name' => 'text'], 'backend_restore' => ['name' => 'text']],
        'discovery' => ['discovery_refresh' => ['name' => 'text']],
    ];

    /**
     * @return array<string, array<string, string>>
     */
    public static function forScreen(string $screen): array
    {
        return self::FORMS[$screen] ?? [];
    }
}
