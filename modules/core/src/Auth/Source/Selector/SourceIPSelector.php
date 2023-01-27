<?php

declare(strict_types=1);

namespace SimpleSAML\Module\core\Auth\Source\Selector;

use SimpleSAML\Assert\Assert;
use SimpleSAML\Logger;
use SimpleSAML\Module\core\Auth\Source\AbstractSourceSelector;
use SimpleSAML\Utils;

use function array_key_exists;
use function sprintf;

/**
 * Authentication source which delegates authentication to secondary
 * authentication sources based on the client's source IP
 *
 * @package simplesamlphp/simplesamlphp
 */
class SourceIPSelector extends AbstractSourceSelector
{
    /**
     * The key of the AuthId field in the state.
     */
    public const AUTHID = '\SimpleSAML\Module\core\Auth\Source\Selector\SourceIPSelector.AuthId';

    /**
     * The string used to identify our states.
     */
    public const STAGEID = '\SimpleSAML\Module\core\Auth\Source\Selector\SourceIPSelector.StageId';

    /**
     * The key where the sources is saved in the state.
     */
    public const SOURCESID = '\SimpleSAML\Module\core\Auth\Source\Selector\SourceIPSelector.SourceId';

    /**
     * @param string  The default authentication source to use when none of the zones match
     */
    protected string $defaultSource;

    /**
     * @param array  An array of zones. Each zone requires two keys;
     *               'source' containing the authsource for the zone,
     *               'subnet' containing an array of IP-ranges (CIDR notation).
     */
    protected array $zones = [];


    /**
     * Constructor for this authentication source.
     *
     * @param array $info Information about this authentication source.
     * @param array $config Configuration.
     */
    public function __construct(array $info, array $config)
    {
        // Call the parent constructor first, as required by the interface
        parent::__construct($info, $config);

        Assert::keyExists($config, 'zones');
        Assert::keyExists($config['zones'], 'default');
        Assert::stringNotEmpty($config['zones']['default']);
        $this->defaultSource = $config['zones']['default'];

        unset($config['zones']['default']);
        $zones = $config['zones'];

        foreach ($zones as $key => $zone) {
            if (!array_key_exists('source', $zone)) {
                Logger::warning(sprintf('Discarding zone %s due to missing `source` key.', $key));
            } elseif (!array_key_exists('subnet', $zone)) {
                Logger::warning(sprintf('Discarding zone %s due to missing `subnet` key.', $key));
            } else {
                $this->zones[$key] = $zone;
            }
        }
    }


    /**
     * Decide what authsource to use.
     *
     * @param array &$state Information about the current authentication.
     * @return array
     */
    protected function selectAuthSource(/** @scrutinizer ignore-unused */ array &$state): array
    {
        $netUtils = new Utils\Net();
        $ip = $_SERVER['REMOTE_ADDR'];

        $source = $this->defaultSource;
        foreach ($this->zones as $name => $zone) {
            foreach ($zone['subnet'] as $subnet) {
                if ($netUtils->ipCIDRcheck($subnet, $ip)) {
                    // Client's IP is in one of the ranges for the secondary auth source
                    Logger::info(sprintf(
                        "core:SourceIPSelector:  Selecting zone `%s` based on client IP %s",
                        $name,
                        $ip
                    ));
                    $source = $zone['source'];
                    break;
                }
            }
        }

        if ($source === $this->defaultSource) {
            Logger::info("core:SourceIPSelector:  no match on client IP; selecting default zone");
        }

        return [$source];
    }
}
