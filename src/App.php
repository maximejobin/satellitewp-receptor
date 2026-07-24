<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor;

use SatelliteWP\Xtractor\Http\PayloadValidator;
use SatelliteWP\Xtractor\Http\Receptor;
use SatelliteWP\Xtractor\Http\SignatureVerifier;
use SatelliteWP\Xtractor\Pipeline\Pipeline;
use SatelliteWP\Xtractor\Pipeline\SummaryBuilder;
use SatelliteWP\Xtractor\Probe\DnsProbe;
use SatelliteWP\Xtractor\Probe\HttpProbe;
use SatelliteWP\Xtractor\Probe\PageSpeedProbe;
use SatelliteWP\Xtractor\Probe\ProbeRegistry;
use SatelliteWP\Xtractor\Probe\RdapProbe;
use SatelliteWP\Xtractor\Probe\TlsProbe;
use SatelliteWP\Xtractor\Reference\EndOfLife;
use SatelliteWP\Xtractor\Rules\RuleCatalog;
use SatelliteWP\Xtractor\Rules\RuleEngine;
use SatelliteWP\Xtractor\Storage\DataStore;
use SatelliteWP\Xtractor\Storage\Index;
use SatelliteWP\Xtractor\Storage\KeyStore;

/**
 * Tiny hand-rolled service registry. Everything is lazy and cached.
 */
final class App
{
    /** @var array<string, mixed> */
    private array $services = [];

    public function __construct(public readonly Config $config)
    {
    }

    public function dataStore(): DataStore
    {
        return $this->services[DataStore::class] ??= new DataStore(
            (string) $this->config->get('data_dir')
        );
    }

    public function index(): Index
    {
        return $this->services[Index::class] ??= new Index(
            (string) $this->config->get('data_dir') . '/index.sqlite'
        );
    }

    public function keyStore(): KeyStore
    {
        return $this->services[KeyStore::class] ??= new KeyStore(
            (string) $this->config->get('data_dir') . '/keys.json'
        );
    }

    public function signatureVerifier(): SignatureVerifier
    {
        return $this->services[SignatureVerifier::class] ??= new SignatureVerifier(
            $this->keyStore(),
            (int) $this->config->get('replay_window_seconds', 300),
            (bool) $this->config->get('allow_unsigned', false)
        );
    }

    public function payloadValidator(): PayloadValidator
    {
        return $this->services[PayloadValidator::class] ??= new PayloadValidator();
    }

    public function receptor(): Receptor
    {
        return $this->services[Receptor::class] ??= new Receptor(
            $this->signatureVerifier(),
            $this->payloadValidator(),
            $this->dataStore(),
            $this->index(),
            (int) $this->config->get('max_body_bytes', 10 * 1024 * 1024)
        );
    }

    public function probeRegistry(): ProbeRegistry
    {
        if (!isset($this->services[ProbeRegistry::class])) {
            $registry = new ProbeRegistry((array) $this->config->get('probes.enabled', []));

            $connectTimeout = (int) $this->config->get('probes.connect_timeout', 5);
            $timeout        = (int) $this->config->get('probes.timeout', 15);
            $userAgent      = (string) $this->config->get('probes.user_agent', 'SatelliteWP-Xtractor/1.0');

            $registry->register(new HttpProbe($connectTimeout, $timeout, $userAgent));
            $registry->register(new DnsProbe());
            $registry->register(new TlsProbe($connectTimeout));
            $registry->register(new RdapProbe(
                (string) $this->config->get('rdap_base_url', 'https://rdap.org'),
                $connectTimeout,
                $timeout,
                $userAgent
            ));

            $strategy   = (string) $this->config->get('pagespeed.strategy', 'mobile');
            $strategies = $strategy === 'both' ? ['mobile', 'desktop'] : [$strategy];
            $registry->register(new PageSpeedProbe(
                $this->config->get('pagespeed.api_key'),
                $strategies,
                (array) $this->config->get('pagespeed.categories', ['performance']),
                (string) $this->config->get('pagespeed.locale', 'fr'),
                (int) $this->config->get('pagespeed.timeout', 60),
                (int) $this->config->get('pagespeed.min_score', 90),
                $userAgent
            ));

            $this->services[ProbeRegistry::class] = $registry;
        }

        return $this->services[ProbeRegistry::class];
    }

    public function ruleEngine(): RuleEngine
    {
        return $this->services[RuleEngine::class] ??= new RuleEngine(
            RuleCatalog::load(
                (string) $this->config->get('rules.catalog', dirname(__DIR__) . '/config/rules.php'),
                (array) $this->config->get('rules.thresholds', [])
            )
        );
    }

    public function endOfLife(): EndOfLife
    {
        return $this->services[EndOfLife::class] ??= new EndOfLife(
            (string) $this->config->get('data_dir') . '/reference'
        );
    }

    /**
     * Server-side reference data injected into every rule Context.
     *
     * @return array<string, mixed>
     */
    public function referenceData(): array
    {
        return ['eol' => $this->endOfLife()];
    }

    public function pipeline(): Pipeline
    {
        return $this->services[Pipeline::class] ??= new Pipeline(
            $this->probeRegistry(),
            $this->dataStore(),
            $this->index(),
            new SummaryBuilder(),
            $this->ruleEngine(),
            $this->referenceData()
        );
    }
}
