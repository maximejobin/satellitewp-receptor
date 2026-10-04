<?php

declare(strict_types=1);

namespace SatelliteWP\Manager;

use SatelliteWP\Manager\Catalog\SoftwareCatalog;
use SatelliteWP\Manager\Crm\ClientsDb;
use SatelliteWP\Manager\Crm\ClientsRepository;
use SatelliteWP\Manager\Http\GoogleAuth;
use SatelliteWP\Manager\Http\LoginLockout;
use SatelliteWP\Manager\Http\PayloadValidator;
use SatelliteWP\Manager\Http\Extractor;
use SatelliteWP\Manager\Http\ReplayCache;
use SatelliteWP\Manager\Http\SignatureVerifier;
use SatelliteWP\Manager\Integration\BlogVaultClient;
use SatelliteWP\Manager\Integration\SeRankingClient;
use SatelliteWP\Manager\Integration\WordfenceClient;
use SatelliteWP\Manager\Pipeline\AuditPoller;
use SatelliteWP\Manager\Pipeline\Pipeline;
use SatelliteWP\Manager\Probe\BlogVaultProbe;
use SatelliteWP\Manager\Probe\CrmProbe;
use SatelliteWP\Manager\Probe\DnsProbe;
use SatelliteWP\Manager\Probe\HttpProbe;
use SatelliteWP\Manager\Probe\MailProbe;
use SatelliteWP\Manager\Probe\PageSpeedProbe;
use SatelliteWP\Manager\Probe\ProbeRegistry;
use SatelliteWP\Manager\Probe\RdapProbe;
use SatelliteWP\Manager\Probe\SeRankingProbe;
use SatelliteWP\Manager\Probe\TlsProbe;
use SatelliteWP\Manager\Probe\WordfenceProbe;
use SatelliteWP\Manager\Probe\WporgProbe;
use SatelliteWP\Manager\Reference\CatalogIndex;
use SatelliteWP\Manager\Reference\EndOfLife;
use SatelliteWP\Manager\Reference\WordfenceIndex;
use SatelliteWP\Manager\Reference\WordPressVersions;
use SatelliteWP\Manager\Rules\RuleCatalog;
use SatelliteWP\Manager\Rules\RuleEngine;
use SatelliteWP\Manager\Rules\Translator;
use SatelliteWP\Manager\Rules\VulnerabilityMerge;
use SatelliteWP\Manager\Storage\DataStore;
use SatelliteWP\Manager\Storage\Index;
use SatelliteWP\Manager\Storage\KeyStore;
use SatelliteWP\Manager\Storage\ReportTokenStore;
use SatelliteWP\Manager\Storage\RoleCapabilities;
use SatelliteWP\Manager\Storage\UserStore;
use SatelliteWP\Manager\Support\ErrorLog;

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

    public function reportTokenStore(): ReportTokenStore
    {
        return $this->services[ReportTokenStore::class] ??= new ReportTokenStore(
            (string) $this->config->get('data_dir') . '/report-tokens.json'
        );
    }

    /** Failed-Basic-Auth-attempt tracker for the admin UI (Router::authenticate()). */
    public function loginLockout(): LoginLockout
    {
        return $this->services[LoginLockout::class] ??= new LoginLockout(
            (string) $this->config->get('data_dir') . '/login-lockout.json'
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
            (bool) $this->config->get('allow_unsigned', false),
            new ReplayCache((string) $this->config->get('data_dir') . '/replay-cache.json')
        );
    }

    public function payloadValidator(): PayloadValidator
    {
        return $this->services[PayloadValidator::class] ??= new PayloadValidator();
    }

    public function extractor(): Extractor
    {
        return $this->services[Extractor::class] ??= new Extractor(
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
            $userAgent      = (string) $this->config->get('probes.user_agent', 'SatelliteWP-Manager/1.0');

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

            // Null when unconfigured (base_url has a default, so the key decides):
            // the probe then reports a configuration error instead of a 401.
            $registry->register(new BlogVaultProbe(
                $this->isConfigured('blogvault') ? $this->blogVault() : null
            ));

            // Links the BlogVault site id to its CRM client and maintenance plan (SELECT only).
            $registry->register(new CrmProbe(fn () => $this->crmRepository()));

            // Reads the local cache only; the API client is for wordfence:refresh.
            $registry->register(new WordfenceProbe(
                $this->isConfigured('wordfence') ? $this->wordfenceIndex() : null
            ));

            // Reads the newest test email in the validation mailbox over IMAP.
            $registry->register(new MailProbe((array) $this->config->get('mail', [])));

            // Unauthenticated wp.org lookups (F8, abandoned plugins/themes).
            $registry->register(new WporgProbe($connectTimeout, $timeout, $userAgent));

            // Creates the audit only; AuditPoller fetches the report when it is finished.
            $registry->register($this->seRankingProbe());

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

    /** Null-client probe when unconfigured: it then reports a configuration error. */
    public function seRankingProbe(): SeRankingProbe
    {
        return $this->services[SeRankingProbe::class] ??= new SeRankingProbe(
            $this->isConfigured('seranking') ? SeRankingClient::fromConfig((array) $this->config->get('seranking', [])) : null,
            (array) $this->config->get('seranking.settings', []),
            (int) $this->config->get('seranking.poll_minutes', 5),
            (int) $this->config->get('seranking.give_up_hours', 24),
        );
    }

    public function auditPoller(): AuditPoller
    {
        return $this->services[AuditPoller::class] ??= new AuditPoller($this->seRankingProbe(), $this->dataStore(), $this->index());
    }

    public function userStore(): UserStore
    {
        return $this->services[UserStore::class] ??= new UserStore(
            (string) $this->config->get('auth.users_file', (string) $this->config->get('data_dir') . '/users.json'),
            $this->roleCapabilities()->roles()
        );
    }

    /** Role -> capability lookup (config/roles.php), checked by Router::requireCapability()/currentUserCan(). */
    public function roleCapabilities(): RoleCapabilities
    {
        return $this->services[RoleCapabilities::class] ??= RoleCapabilities::load(
            (string) $this->config->get('roles.catalog', dirname(__DIR__) . '/config/roles.php')
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

    public function wordPressVersions(): WordPressVersions
    {
        return $this->services[WordPressVersions::class] ??= new WordPressVersions(
            (string) $this->config->get('data_dir') . '/reference/wordpress-versions.json'
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

    /** Rebuildable SQLite index over the Wordfence cache and the software catalogue. */
    public function catalogIndex(): CatalogIndex
    {
        return $this->services[CatalogIndex::class] ??= new CatalogIndex(
            (string) $this->config->get('data_dir') . '/reference/catalog-index.sqlite'
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
        return [
            'eol'                     => $this->endOfLife(),
            'wordpress_versions'      => $this->wordPressVersions(),
            'ignored_vulnerabilities' => $this->ignoredVulnerabilities(),
        ];
    }

    /**
     * Vulnerability ids (config: vulnerabilities.ignored) left out of findings,
     * reports and the extraction page.
     *
     * @return list<string>
     */
    public function ignoredVulnerabilities(): array
    {
        return VulnerabilityMerge::normalizeIgnored((array) $this->config->get('vulnerabilities.ignored', []));
    }

    public function crmDb(): ClientsDb
    {
        return $this->services[ClientsDb::class] ??= ClientsDb::fromConfig(
            (array) $this->config->get('crm_db', [])
        );
    }

    /** Null until crm_db is configured. */
    public function crmRepository(): ?ClientsRepository
    {
        if (!$this->crmDb()->isConfigured()) {
            return null;
        }

        return $this->services[ClientsRepository::class] ??= new ClientsRepository($this->crmDb()->pdo());
    }

    public function pipeline(): Pipeline
    {
        return $this->services[Pipeline::class] ??= new Pipeline(
            $this->probeRegistry(),
            $this->dataStore(),
            $this->index(),
            $this->ruleEngine(),
            $this->referenceData(),
            $this->softwareCatalog(),
            $this->keyStore()
        );
    }
}
