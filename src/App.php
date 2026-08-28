<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor;

use SatelliteWP\Xtractor\Catalog\SoftwareCatalog;
use SatelliteWP\Xtractor\Http\GoogleAuth;
use SatelliteWP\Xtractor\Http\PayloadValidator;
use SatelliteWP\Xtractor\Http\Receptor;
use SatelliteWP\Xtractor\Http\SignatureVerifier;
use SatelliteWP\Xtractor\Integration\BlogVaultClient;
use SatelliteWP\Xtractor\Integration\WordfenceClient;
use SatelliteWP\Xtractor\Pipeline\Pipeline;
use SatelliteWP\Xtractor\Probe\BlogVaultProbe;
use SatelliteWP\Xtractor\Probe\DnsProbe;
use SatelliteWP\Xtractor\Probe\HttpProbe;
use SatelliteWP\Xtractor\Probe\PageSpeedProbe;
use SatelliteWP\Xtractor\Probe\ProbeRegistry;
use SatelliteWP\Xtractor\Probe\RdapProbe;
use SatelliteWP\Xtractor\Probe\TlsProbe;
use SatelliteWP\Xtractor\Probe\WordfenceProbe;
use SatelliteWP\Xtractor\Reference\EndOfLife;
use SatelliteWP\Xtractor\Reference\WordfenceIndex;
use SatelliteWP\Xtractor\Rules\RuleCatalog;
use SatelliteWP\Xtractor\Rules\RuleEngine;
use SatelliteWP\Xtractor\Rules\Translator;
use SatelliteWP\Xtractor\Storage\DataStore;
use SatelliteWP\Xtractor\Storage\Index;
use SatelliteWP\Xtractor\Storage\KeyStore;
use SatelliteWP\Xtractor\Storage\UserStore;
use SatelliteWP\Xtractor\Support\ErrorLog;

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

    /** Where every HTTP 500 is written. Same logs/ the front controllers use. */
    public function errorLog(): ErrorLog
    {
        return $this->services[ErrorLog::class] ??= new ErrorLog(ErrorLog::defaultDir());
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
            (int) $this->config->get('max_body_bytes', 10 * 1024 * 1024),
            $this->keyStore(),
            $this->errorLog()
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

            // Null when unconfigured: the probe reports a configuration error
            // instead of the registry blowing up at boot. Both base_url and
            // api_key must be present — base_url alone has a usable default, so
            // gating on it only would send every keyless install through a
            // pointless round-trip that comes back 401.
            $registry->register(new BlogVaultProbe(
                $this->isConfigured('blogvault') ? $this->blogVault() : null
            ));

            // The Wordfence probe reads the local cache and never calls the API,
            // so it only needs the index; the index needs a client for
            // `wordfence:refresh` alone. Unlike BlogVault, "configured but never
            // refreshed" is its own distinct state, which the probe reports.
            $registry->register(new WordfenceProbe(
                $this->isConfigured('wordfence') ? $this->wordfenceIndex() : null
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

    /** Renders neutral findings into a given language. Cached per locale. */
    public function translator(?string $locale = null): Translator
    {
        $locale ??= (string) $this->config->get('lang.default', 'en');

        return $this->services['translator.' . $locale] ??= new Translator(
            $locale,
            (string) $this->config->get('lang.dir', dirname(__DIR__) . '/config/lang'),
            (string) $this->config->get('lang.default', 'en')
        );
    }

    public function blogVault(): BlogVaultClient
    {
        return $this->services[BlogVaultClient::class] ??= BlogVaultClient::fromConfig(
            (array) $this->config->get('blogvault', [])
        );
    }

    public function userStore(): UserStore
    {
        return $this->services[UserStore::class] ??= new UserStore(
            (string) $this->config->get('auth.users_file', (string) $this->config->get('data_dir') . '/users.json')
        );
    }

    public function googleAuth(): GoogleAuth
    {
        return $this->services[GoogleAuth::class] ??= GoogleAuth::fromConfig(
            (array) $this->config->get('auth.google', [])
        );
    }

    public function softwareCatalog(): SoftwareCatalog
    {
        return $this->services[SoftwareCatalog::class] ??= new SoftwareCatalog(
            (string) $this->config->get('data_dir') . '/catalog/software.json'
        );
    }

    public function endOfLife(): EndOfLife
    {
        return $this->services[EndOfLife::class] ??= new EndOfLife(
            (string) $this->config->get('data_dir') . '/reference'
        );
    }

    public function wordfence(): WordfenceClient
    {
        return $this->services[WordfenceClient::class] ??= WordfenceClient::fromConfig(
            (array) $this->config->get('wordfence', [])
        );
    }

    public function wordfenceIndex(): WordfenceIndex
    {
        return $this->services[WordfenceIndex::class] ??= new WordfenceIndex(
            (string) $this->config->get('data_dir') . '/reference/wordfence.json',
            $this->isConfigured('wordfence') ? $this->wordfence() : null
        );
    }

    /** An integration is usable only with both a base URL and a key. */
    private function isConfigured(string $integration): bool
    {
        return (string) $this->config->get("{$integration}.base_url", '') !== ''
            && (string) $this->config->get("{$integration}.api_key", '') !== '';
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
            $this->ruleEngine(),
            $this->referenceData(),
            $this->softwareCatalog()
        );
    }
}
