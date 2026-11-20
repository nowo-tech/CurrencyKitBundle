<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function str_starts_with;

/**
 * The strict configuration tree of `nowo_currency_kit` (contract section 11). Unknown keys are refused; ranges and
 * cross-field rules are checked here so a misconfiguration fails the container build.
 */
final class ConfigurationTree
{
    /** The ECB daily file. */
    public const ECB_DAILY = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    /** The ECB 90-day file. */
    public const ECB_90 = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml';

    /** The ECB full-history file. */
    public const ECB_HISTORY = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml';

    /**
     * Defines the tree under the root node.
     */
    public static function define(ArrayNodeDefinition $root): void
    {
        $children = $root->children();
        self::doctrine($children);
        self::sources($children);
        self::conversion($children);
        self::sync($children);
        self::outbound($children);
        self::misc($children);
        $children->end();

        $root
            ->validate()
                ->ifTrue(static function (mixed $config): bool {
                    $active  = is_array($config) && is_array($config['conversion'] ?? null) ? ($config['conversion']['source'] ?? null) : null;
                    $sources = is_array($config) && is_array($config['sources'] ?? null) ? $config['sources'] : [];
                    $ecb     = is_array($sources['ecb'] ?? null) && ($sources['ecb']['enabled'] ?? false) === true;
                    $mirror  = is_array($sources['frankfurter'] ?? null) && ($sources['frankfurter']['enabled'] ?? false) === true ? ($sources['frankfurter']['key'] ?? null) : null;

                    return !($active === 'manual' || ($active === 'ecb' && $ecb) || ($mirror !== null && $active === $mirror));
                })
                ->thenInvalid('nowo_currency_kit.conversion.source must name an enabled source (ecb, the frankfurter key) or manual; got %s.')
            ->end()
            ->validate()
                ->ifTrue(static function (mixed $config): bool {
                    $mirror = is_array($config) && is_array($config['sources'] ?? null) && is_array($config['sources']['frankfurter'] ?? null) ? $config['sources']['frankfurter'] : null;
                    if ($mirror === null || ($mirror['enabled'] ?? false) !== true) {
                        return false;
                    }
                    $uri = $mirror['base_uri'] ?? null;

                    return !is_string($uri) || !str_starts_with($uri, 'https://') && (($mirror['allow_private_network'] ?? false) !== true || !str_starts_with($uri, 'http://'));
                })
                ->thenInvalid('nowo_currency_kit.sources.frankfurter needs base_uri (https; http only with allow_private_network).')
            ->end()
            ->validate()
                ->ifTrue(static function (mixed $config): bool {
                    $security = is_array($config) && is_array($config['security'] ?? null) ? $config['security'] : [];

                    return ($security['access_checker'] ?? null) === 'allow_all' && ($security['allow_unauthenticated'] ?? false) !== true;
                })
                ->thenInvalid('nowo_currency_kit.security.access_checker: allow_all needs allow_unauthenticated: true (demos only).')
            ->end();
    }

    /**
     * `doctrine.*`.
     */
    private static function doctrine(NodeBuilder $children): void
    {
        $children
            ->arrayNode('doctrine')->addDefaultsIfNotSet()->children()
                ->scalarNode('entity_manager')->defaultNull()->info('Manager whose connection the bundle uses (null: the default one). Never flushed.')->end()
                ->scalarNode('table_prefix')->defaultValue('nowo_')
                    ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^([a-z][a-z0-9_]{0,15})?$/D', $v) !== 1)->thenInvalid('table_prefix must match ^[a-z][a-z0-9_]{0,15}$ or be empty.')->end()
                ->end()
                ->enumNode('mapping')->values(['xml', 'attribute'])->defaultValue('xml')->end()
            ->end()->end();
    }

    /**
     * `sources.*`.
     */
    private static function sources(NodeBuilder $children): void
    {
        $children
            ->arrayNode('sources')->addDefaultsIfNotSet()->children()
                ->arrayNode('ecb')->addDefaultsIfNotSet()->children()
                    ->booleanNode('enabled')->defaultTrue()->end()
                    ->scalarNode('label')->defaultValue('ECB')->end()
                    ->scalarNode('daily_url')->defaultValue(self::ECB_DAILY)
                        ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('#^https://[^\s]+$#D', $v) !== 1)->thenInvalid('The ECB feed URLs must be https.')->end()
                    ->end()
                    ->scalarNode('last_90_days_url')->defaultValue(self::ECB_90)
                        ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('#^https://[^\s]+$#D', $v) !== 1)->thenInvalid('The ECB feed URLs must be https.')->end()
                    ->end()
                    ->scalarNode('history_url')->defaultValue(self::ECB_HISTORY)
                        ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('#^https://[^\s]+$#D', $v) !== 1)->thenInvalid('The ECB feed URLs must be https.')->end()
                    ->end()
                ->end()->end()
                ->arrayNode('frankfurter')->addDefaultsIfNotSet()->children()
                    ->booleanNode('enabled')->defaultFalse()->end()
                    ->scalarNode('key')->defaultValue('mirror')
                        ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $v) !== 1 || in_array($v, ['ecb', 'manual', 'identity'], true))->thenInvalid('The frankfurter key must match ^[a-z][a-z0-9_]{0,31}$ and not be ecb, manual or identity.')->end()
                    ->end()
                    ->scalarNode('label')->defaultValue('ECB')->end()
                    ->scalarNode('base_uri')->defaultNull()->end()
                    ->scalarNode('timezone')->defaultValue('Europe/Berlin')->end()
                    ->booleanNode('allow_private_network')->defaultFalse()->end()
                    ->booleanNode('rebase')->defaultFalse()->end()
                ->end()->end()
                ->arrayNode('manual')->addDefaultsIfNotSet()->children()
                    ->scalarNode('label')->defaultValue('Manual')->end()
                ->end()->end()
            ->end()->end();
    }

    /**
     * `conversion.*`.
     */
    private static function conversion(NodeBuilder $children): void
    {
        $children
            ->arrayNode('conversion')->addDefaultsIfNotSet()->children()
                ->scalarNode('source')->defaultValue('ecb')->end()
                ->integerNode('max_lookback_days')->defaultValue(7)->min(4)->max(31)->end()
                ->integerNode('max_staleness_days')->defaultValue(10)->min(1)->max(366)->end()
                ->scalarNode('on_stale')->defaultValue('fail')->info('fail | flag (accepts %env()%)')->end()
                ->enumNode('rounding_mode')->values(['half_up', 'half_even', 'half_down', 'down', 'up', 'ceiling', 'floor'])->defaultValue('half_up')->end()
            ->end()->end();
    }

    /**
     * `sync.*` and `backfill.*`.
     */
    private static function sync(NodeBuilder $children): void
    {
        $children
            ->arrayNode('sync')->addDefaultsIfNotSet()->children()
                ->enumNode('initial')->values(['last_90_days', 'none'])->defaultValue('last_90_days')->end()
                ->arrayNode('times')->scalarPrototype()
                    ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $v) !== 1)->thenInvalid('sync.times entries are HH:MM.')->end()
                ->end()->defaultValue(['16:15', '17:00', '18:30'])->end()
                ->scalarNode('catch_up_time')->defaultValue('08:00')
                    ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $v) !== 1)->thenInvalid('sync.catch_up_time is HH:MM.')->end()
                ->end()
                ->enumNode('on_changed_value')->values(['replace', 'keep'])->defaultValue('replace')->end()
                ->integerNode('max_daily_change_percent')->defaultValue(50)->min(1)->max(1000)->end()
                ->arrayNode('lock')->addDefaultsIfNotSet()->children()
                    ->enumNode('store')->values(['database'])->defaultValue('database')->info('0.1 ships the database lease only')->end()
                    ->integerNode('ttl_seconds')->defaultValue(600)->min(60)->max(3600)->end()
                ->end()->end()
            ->end()->end()
            ->arrayNode('backfill')->addDefaultsIfNotSet()->children()
                ->integerNode('max_days')->defaultValue(10000)->min(1)->max(20000)->end()
                ->integerNode('chunk_days')->defaultValue(366)->min(1)->max(366)->end()
            ->end()->end();
    }

    /**
     * `outbound.*`.
     */
    private static function outbound(NodeBuilder $children): void
    {
        $children
            ->arrayNode('outbound')->addDefaultsIfNotSet()->children()
                ->scalarNode('enabled')->defaultTrue()->info('false: no HTTP and no DNS at all (accepts %env(bool:…)%)')->end()
                ->integerNode('timeout')->defaultValue(10)->min(1)->max(60)->end()
                ->integerNode('max_duration')->defaultValue(30)->min(1)->max(120)->end()
                ->integerNode('retries')->defaultValue(2)->min(0)->max(5)->end()
                ->scalarNode('contact_url')->defaultValue('https://github.com/nowo-tech/CurrencyKitBundle')
                    ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('#^https://[^\s@]+$#D', $v) !== 1)->thenInvalid('outbound.contact_url must be an https URL (never an e-mail address).')->end()
                ->end()
                ->arrayNode('max_bytes')->addDefaultsIfNotSet()->children()
                    ->integerNode('daily')->defaultValue(262144)->min(1024)->end()
                    ->integerNode('last_90_days')->defaultValue(4194304)->min(1024)->end()
                    ->integerNode('history')->defaultValue(67108864)->min(1024)->end()
                    ->integerNode('api')->defaultValue(4194304)->min(1024)->end()
                ->end()->end()
            ->end()->end();
    }

    /**
     * `manual.*`, `scheduler.*`, `cache.*`, `twig.*`, `ui.*`, `security.*`, `audit.*`.
     */
    private static function misc(NodeBuilder $children): void
    {
        $children
            ->arrayNode('manual')->addDefaultsIfNotSet()->children()
                ->integerNode('max_deviation_percent')->defaultValue(50)->min(1)->max(1000)->end()
                ->booleanNode('allow_past')->defaultFalse()->end()
            ->end()->end()
            ->arrayNode('scheduler')->addDefaultsIfNotSet()->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->scalarNode('transport')->defaultNull()->info('Messenger routing hint for SyncReferenceRatesMessage (documentation only)')->end()
            ->end()->end()
            ->arrayNode('cache')->addDefaultsIfNotSet()->children()
                ->scalarNode('pool')->defaultNull()->end()
                ->integerNode('ttl')->defaultValue(300)->min(1)->max(300)->end()
            ->end()->end()
            ->arrayNode('twig')->addDefaultsIfNotSet()->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->enumNode('on_missing_rate')->values(['note', 'original', 'exception'])->defaultValue('note')->end()
                ->enumNode('date_format')->values(['iso', 'locale'])->defaultValue('iso')->end()
            ->end()->end()
            ->arrayNode('ui')->addDefaultsIfNotSet()->children()
                ->booleanNode('enabled')->defaultFalse()
                    ->validate()->ifTrue(static fn (mixed $v): bool => $v === true)->thenInvalid('The currency kit UI arrives in 0.2.0; keep ui.enabled false.')->end()
                ->end()
                ->scalarNode('path_prefix')->defaultValue('/admin/currency')->end()
            ->end()->end()
            ->arrayNode('security')->addDefaultsIfNotSet()->children()
                ->arrayNode('access_roles')->scalarPrototype()->end()->defaultValue(['ROLE_ADMIN'])->end()
                ->scalarNode('access_checker')->defaultNull()->info('Service id of a CurrencyKitAccessCheckerInterface, or allow_all (demos, with allow_unauthenticated)')->end()
                ->booleanNode('allow_unauthenticated')->defaultFalse()->end()
                ->arrayNode('dashboard_roles')->scalarPrototype()->end()->defaultValue([])->end()
                ->arrayNode('manage_roles')->scalarPrototype()->end()->defaultValue(['ROLE_ADMIN'])->end()
            ->end()->end()
            ->arrayNode('audit')->addDefaultsIfNotSet()->children()
                ->scalarNode('sink')->defaultNull()->end()
            ->end()->end();
    }
}
