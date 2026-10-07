<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Security;

use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SatelliteWP\Manager\App;
use SatelliteWP\Manager\Config;
use SatelliteWP\Manager\Http\Controller\CatalogController;
use SatelliteWP\Manager\Http\Router;
use SatelliteWP\Manager\Domain\ProbeResult;
use SatelliteWP\Manager\Probe\DnsProbe;
use SatelliteWP\Manager\Probe\HttpProbe;
use SatelliteWP\Manager\Probe\RdapProbe;
use SatelliteWP\Manager\Probe\TlsProbe;
use SatelliteWP\Manager\Rules\Context;
use SatelliteWP\Manager\Storage\Index;
use SatelliteWP\Manager\Tests\Http\RecordingResponse;
use SatelliteWP\Manager\Tests\TestCase;

/**
 * A hijacked plugin holds the site's key and signs whatever it likes. Every
 * case here goes through the real ingestion path (signature, validation,
 * storage, index), then through what an analyst opens: rules evaluation,
 * catalogue, site page, extraction page, raw file, report.json. A hostile
 * payload is either refused with a 4xx or rendered inert; never a 500,
 * never markup or a script URL reaching the page.
 */
final class HostilePayloadTest extends TestCase
{
    private const string SITE = '3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c';
    private const string KEY  = 'hostile-test-key';

    private const string SCRIPT  = '<script>alert(1)</script>';
    private const string ATTR    = '"><img src=x onerror=alert(1)>';
    private const string JS_URL  = 'javascript:alert(1)';

    private App $app;
    private int $clock = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $_GET = $_POST = $_COOKIE = $_SESSION = [];
        $this->app = $this->makeApp();
        $this->app->keyStore()->addKey(self::SITE, self::KEY, 'example.com');
    }

    protected function tearDown(): void
    {
        $_GET = $_POST = $_COOKIE = $_SESSION = [];
        parent::tearDown();
    }

    // --- hostile strings ----------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function hostileStrings(): array
    {
        return [
            'script tag'       => [self::SCRIPT],
            'attribute break'  => [self::ATTR],
            'single quote'     => ["' onmouseover='alert(1)"],
            'javascript url'   => [self::JS_URL],
            'report token'     => ['{{client}} {{site_url:red:short}}'],
            'markup image'     => ['[img url=https://attacker.example.net/x.png]'],
            'markup link'      => ['[click](https://attacker.example.net/)'],
            'markup bold'      => ['**x** _y_ \\* \\['],
            'crlf'             => ["a\r\nSet-Cookie: x=1\r\nX-Injected: 1"],
            'unicode controls' => ["\u{202E}gpj.exe\u{200B}\u{FEFF}"],
            'percent encoded'  => ['%3Cscript%3E%00%0d%0a'],
            'long but allowed' => [str_repeat('A', 200)],
        ];
    }

    #[Test]
    #[DataProvider('hostileStrings')]
    public function hostileStringsInEveryFieldRenderInert(string $hostile): void
    {
        $payload = $this->fixture_();
        array_walk_recursive($payload, function (mixed &$value, int|string $key) use ($hostile): void {
            if (is_string($value) && !ctype_digit($value) && !in_array($key, ['site_id', 'schema_version', 'site_url', 'home_url', 'admin_url', 'slug'], true)) {
                $value = substr($hostile, 0, $key === 'mode' ? 8 : 200);
            }
        });
        // Hostile names as keys too, where a key is a name rather than a path.
        $payload['plugins'] = $this->renameKey($payload['plugins'], 'akismet/akismet.php', 'akismet/' . $this->fileSafe($hostile) . '.php');
        $payload['themes']  = $this->renameKey($payload['themes'], 'storefront', $this->fileSafe($hostile));
        $payload['mu_plugins'] = [$this->fileSafe($hostile) . '.php' => ['Name' => $hostile]];
        $payload['constants']['WP_HOSTILE_' . md5($hostile)] = $hostile;
        $payload['post_types'][$this->fileSafe($hostile)] = $hostile;
        $payload['active_plugins'] = [$hostile];
        $payload['active_theme']   = substr($hostile, 0, 200);
        $payload['connectors']['wpml'] = [
            'provider' => 'wpml', 'label' => $hostile, 'version' => '1',
            'active_languages' => [$hostile], 'locales' => ['fr' => $hostile],
            'language_urls' => ['fr' => 'https://example.com/?q=' . rawurlencode($hostile)],
            'details' => ['addons' => [$hostile => $hostile]],
        ];

        $id = $this->accept($payload);
        $this->analyse($id);
        $this->assertEverythingRendersInert($id);
        if ($hostile === self::SCRIPT) {
            // The hostile text did reach the page, escaped.
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $this->get($this->extractionPath($id)));
        }

        $this->pushEvents([[
            'event' => 'option_changed', 'event_id' => 'x', 'actor_user_id' => 1,
            'actor_login' => substr($hostile, 0, 200), 'timestamp_gmt' => substr($hostile, 0, 30),
            'option' => $hostile, 'old' => $hostile, 'new' => ['nested' => $hostile],
        ]], 200);
        $this->assertInert($this->get('/site/' . self::SITE), 'site page after events');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusedStrings(): array
    {
        return [
            'nul byte'         => ['site_title', "Site\0name"],
            '1 MB string'      => ['site_title', str_repeat('A', 1024 * 1024)],
            '1 MB unknown key' => ['future_field', str_repeat('A', 1024 * 1024)],
            'long version'     => ['wp_version', str_repeat('9', 300)],
        ];
    }

    #[Test]
    #[DataProvider('refusedStrings')]
    public function oversizedOrBinaryStringsAreRefused(string $field, string $value): void
    {
        $payload         = $this->fixture_();
        $payload[$field] = $value;

        $this->assertSame(422, $this->push($payload)['status']);
    }

    #[Test]
    public function invalidUtf8AndLoneSurrogatesAreRefused(): void
    {
        $body = str_replace('"Site de démonstration"', "\"Site \xC3\x28 \xFF\"", $this->fixture('extraction-valid.json'));
        $this->assertSame(422, $this->pushRaw($body)['status']);

        $body = str_replace('"Site de démonstration"', '"\ud800"', $this->fixture('extraction-valid.json'));
        $this->assertSame(422, $this->pushRaw($body)['status']);
    }

    #[Test]
    public function crlfNeverReachesAHeaderOrALogLine(): void
    {
        $payload               = $this->fixture_();
        $payload['site_title'] = "x\r\nX-Injected: 1";
        $id = $this->accept($payload);
        $this->analyse($id);

        foreach (['/site/' . self::SITE, $this->extractionPath($id), $this->extractionPath($id) . '/raw/payload.json'] as $path) {
            $response = $this->request($path);
            foreach ($response->headers as $line) {
                $this->assertStringNotContainsString('X-Injected', $line, $path);
            }
        }
        foreach (glob($this->tmpDir . '/logs/*') ?: [] as $log) {
            $this->assertStringNotContainsString("\nX-Injected", (string) file_get_contents($log));
        }
    }

    // --- addresses ----------------------------------------------------------

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function badAddresses(): array
    {
        return [
            'empty home_url'     => ['home_url', ''],
            'missing home_url'   => ['home_url', null],
            'javascript scheme'  => ['home_url', 'javascript:alert(1)//example.com'],
            'file scheme'        => ['home_url', 'file:///etc/passwd'],
            'gopher scheme'      => ['home_url', 'gopher://example.com:25/'],
            'credentials'        => ['home_url', 'https://user:pw@example.com/'],
            'space in host'      => ['home_url', 'https://exa mple.com/'],
            'crlf'               => ['home_url', "https://example.com/\r\nHost: evil"],
            'markup in host'     => ['home_url', 'https://"><script>.example.com/'],
            'array'              => ['home_url', ['https://example.com']],
            'site_url js'        => ['site_url', 'javascript:alert(1)'],
            'admin_url js'       => ['admin_url', self::JS_URL],
            'language url js'    => ['connectors', ['wpml' => ['language_urls' => ['fr' => self::JS_URL]]]],
            'huge url'           => ['home_url', 'https://example.com/' . str_repeat('a', 5000)],
        ];
    }

    #[Test]
    #[DataProvider('badAddresses')]
    public function siteAddressesMustBePlainHttpUrls(string $field, mixed $value): void
    {
        $payload = $this->fixture_();
        if ($value === null) {
            unset($payload[$field]);
        } else {
            $payload[$field] = $value;
        }

        $this->assertSame(422, $this->push($payload)['status']);
    }

    #[Test]
    public function anEmptyHomeUrlCannotSlipPastTheOriginBinding(): void
    {
        // Bound to example.com: an empty home_url used to skip the check and
        // let site_url point every probe at another host.
        $payload             = $this->fixture_();
        $payload['home_url'] = '';
        $payload['site_url'] = 'https://intranet.example.net/';
        $this->assertSame(422, $this->push($payload)['status']);

        $payload['home_url'] = 'https://intranet.example.net/';
        $this->assertSame(409, $this->push($payload)['status']);
    }

    // --- path traversal -------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function traversalKeys(): array
    {
        return [
            'plugin passwd'      => ['plugins', '../../etc/passwd'],
            'plugin dotdot php'  => ['plugins', '../x.php'],
            'plugin deep'        => ['plugins', 'a/b/c.php'],
            'plugin absolute'    => ['plugins', '/etc/x.php'],
            'plugin backslash'   => ['plugins', '..\\..\\x.php'],
            'plugin dot dir'     => ['plugins', './x.php'],
            'theme dotdot'       => ['themes', '../x'],
            'theme passwd'       => ['themes', '../../etc/passwd'],
            'theme dot'          => ['themes', '.'],
            'theme absolute'     => ['themes', '/etc'],
            'mu dotdot'          => ['mu_plugins', '../x.php'],
            'mu subdir'          => ['mu_plugins', 'dir/x.php'],
            'dropin dotdot'      => ['dropin_plugins', '../advanced-cache.php'],
            'dropin not php'     => ['dropin_plugins', 'passwd'],
        ];
    }

    #[Test]
    #[DataProvider('traversalKeys')]
    public function pathLikeKeysAreRefused(string $map, string $key): void
    {
        $payload = $this->fixture_();
        $entry   = match ($map) {
            'plugins' => ['name' => 'x', 'slug' => $key, 'version' => '1', 'active' => true],
            'themes'  => ['name' => 'x', 'slug' => $key, 'version' => '1', 'active' => false],
            default   => ['Name' => 'x'],
        };
        $payload[$map][$key] = $entry;

        $this->assertSame(422, $this->push($payload)['status']);
    }

    #[Test]
    public function aPluginSlugMustMatchItsKey(): void
    {
        $payload = $this->fixture_();
        $payload['plugins']['akismet/akismet.php']['slug'] = '../../etc/passwd';

        $this->assertSame(422, $this->push($payload)['status']);
    }

    #[Test]
    public function aTraversalSiteIdIsRefusedBeforeAnythingIsWritten(): void
    {
        $body   = $this->fixture('extraction-valid.json');
        $result = $this->app->extractor()->handle([
            'site' => '../../tmp/x', 'type' => 'extraction', 'timestamp' => (string) time(), 'signature' => 'x',
        ], $body);

        $this->assertSame(400, $result['status']);
        $this->assertSame([], glob($this->tmpDir . '/sites/*') ?: []);
    }

    // --- wrong types ------------------------------------------------------------

    /** @return array<string, array{0: mixed}> */
    public static function wrongValues(): array
    {
        $deep = 'x';
        for ($i = 0; $i < 12; $i++) {
            $deep = [$deep];
        }

        return [
            'array'        => [['a', 'b']],
            'object'       => [['k' => 'v']],
            'string'       => ['string'],
            'empty string' => [''],
            'int'          => [7],
            'zero'         => [0],
            'float'        => [1.5],
            'true'         => [true],
            'false'        => [false],
            'null'         => [null],
            'nested deep'  => [$deep],
        ];
    }

    /**
     * Every known path of the fixture, swapped for every wrong type: either
     * refused, or accepted and then evaluated and rendered without an error.
     */
    #[Test]
    #[DataProvider('wrongValues')]
    public function wrongTypesAnywhereAreRefusedOrHandled(mixed $wrong): void
    {
        $paths    = $this->paths($this->fixture_(), '', 3);
        $accepted = 0;

        foreach ($paths as $path) {
            $payload = $this->fixture_();
            $this->setPath($payload, $path, $wrong);
            $result = $this->push($payload);

            $this->assertContains($result['status'], [200, 422], "{$path}: HTTP {$result['status']}");
            if ($result['status'] !== 200) {
                continue;
            }
            $accepted++;
            $id = (string) $result['body']['id'];
            try {
                $this->analyse($id);
                $this->assertRenders($this->extractionPath($id), $path);
                $this->assertRenders($this->extractionPath($id) . '/report.json?token=' . $this->token($id), $path);
            } finally {
                // Keeps the hourly quota out of the way: one site, many pushes.
                $this->removeExtraction($id);
            }
        }

        $this->assertGreaterThan(0, count($paths));
        $this->addToAssertionCount($accepted);
    }

    #[Test]
    public function deepNestingIsRefusedAtDecode(): void
    {
        $body = str_replace('"site_title"', '"deep": ' . str_repeat('[', 1000) . str_repeat(']', 1000) . ', "site_title"', $this->fixture('extraction-valid.json'));

        $this->assertSame(422, $this->pushRaw($body)['status']);
    }

    #[Test]
    public function numericKeysAreRefusedWhereNamesAreExpected(): void
    {
        foreach (['themes' => ['123' => ['slug' => '123', 'name' => 'x']], 'post_types' => ['0' => 'x'], 'constants' => ['1' => true]] as $map => $value) {
            $payload       = $this->fixture_();
            $payload[$map] = $value + (array) $payload[$map];
            $this->assertSame(422, $this->push($payload)['status'], $map);
        }

        // A numeric key outside known maps is harmless and passes.
        $payload       = $this->fixture_();
        $payload['42'] = 'x';
        $id = $this->accept($payload);
        $this->analyse($id);
        $this->assertEverythingRendersInert($id);
    }

    #[Test]
    public function duplicateKeysResolveToTheLastOneEverywhere(): void
    {
        $body = str_replace(
            '"home_url": "https://www.example.com",',
            '"home_url": "https://www.example.com", "home_url": "https://intranet.example.net",',
            $this->fixture('extraction-valid.json')
        );

        // The decoded (last) value is the one checked against the binding.
        $this->assertSame(409, $this->pushRaw($body)['status']);

        $body = str_replace(
            '"active_theme": "storefront-child",',
            '"active_theme": "storefront-child", "active_theme": "' . addslashes(self::ATTR) . '",',
            $this->fixture('extraction-valid.json')
        );
        $id = (string) $this->pushRaw($body)['body']['id'];
        $this->analyse($id);
        $this->assertEverythingRendersInert($id);
    }

    // --- volume -----------------------------------------------------------------

    #[Test]
    public function oneHundredThousandPluginsAreRefusedWithinMemory(): void
    {
        $payload = $this->fixture_();
        for ($i = 0; $i < 100_000; $i++) {
            $payload['plugins']["p{$i}/p{$i}.php"] = ['name' => "P{$i}", 'slug' => "p{$i}/p{$i}.php", 'version' => '1.0.0', 'active' => false];
        }
        $body = (string) json_encode($payload);
        unset($payload);
        $this->assertGreaterThan(8 * 1024 * 1024, strlen($body));

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $app    = $this->makeApp(['max_body_bytes' => 16 * 1024 * 1024]);
        $app->keyStore()->addKey(self::SITE, self::KEY, 'example.com');
        $result = $this->pushRaw($body, 'extraction', $app);

        $this->assertSame(422, $result['status']);
        $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    #[Test]
    public function aNodeBombIsRefusedBeforeDecoding(): void
    {
        // ~5 MB of one-element arrays decodes to ~300 MB of zvals.
        $body = substr($this->fixture('extraction-valid.json'), 0, -2) . ', "bomb": [' . str_repeat('[0],', 1_250_000) . '[0]]}';

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $this->assertSame(422, $this->pushRaw($body)['status']);
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    #[Test]
    public function mapsAndListsAreCapped(): void
    {
        $cases = [
            'plugins' => fn () => array_combine(
                array_map(static fn ($i) => "p{$i}.php", range(1, 2001)),
                array_map(static fn ($i) => ['slug' => "p{$i}.php", 'name' => 'x'], range(1, 2001))
            ),
            'active_plugins' => fn () => array_fill(0, 2001, 'x.php'),
            'administrators' => fn () => array_fill(0, 10_001, ['id' => 1, 'login' => 'a', 'email' => 'a@example.com']),
        ];
        foreach ($cases as $field => $make) {
            $payload         = $this->fixture_();
            $payload[$field] = $make();
            $this->assertSame(422, $this->push($payload)['status'], $field);
        }
    }

    #[Test]
    public function extractionsPerSiteAreRateLimited(): void
    {
        $body = $this->fixture('extraction-valid.json');
        for ($i = 0; $i < 30; $i++) {
            $this->assertSame(200, $this->pushRaw($body)['status'], "push {$i}");
        }
        $this->assertSame(429, $this->pushRaw($body)['status']);
    }

    // --- events -----------------------------------------------------------------

    #[Test]
    public function hostileEventsAreRefusedOrRenderedInert(): void
    {
        $this->accept($this->fixture_());

        $this->pushEvents([['event' => self::SCRIPT]], 422);
        $this->pushEvents([['event' => 'x', 'actor_login' => ['array']]], 422);
        $this->pushEvents([['event' => 'x', 'timestamp_gmt' => 12]], 422);
        $this->pushEvents(['not an object'], 422);
        $this->pushEvents([['event' => 'x', 'deep' => [[[['x']]]]]], 422);
        $this->pushEvents([['event' => 'x', 'old' => str_repeat('A', 20_000)]], 422);
        $this->pushEvents(array_fill(0, 501, ['event' => 'x']), 422);
        $this->pushEventsRaw('{"schema_version":"1.0","site_id":"' . self::SITE . '","events":{"a":{"event":"x"}}}', 422);
        $this->pushEventsRaw('{"schema_version":"1.0","site_id":"' . self::SITE . '","events":[{"event":"x","pad":"' . str_repeat('A', 1_100_000) . '"}]}', 413);

        $this->pushEvents([[
            'event' => 'plugin_activated', 'actor_login' => self::ATTR, 'timestamp_gmt' => self::SCRIPT,
            'plugin_slug' => '../../etc/passwd', 'plugin_name' => self::SCRIPT, 'version' => "1\r\nX: y",
        ]], 200);
        $this->assertInert($this->get('/site/' . self::SITE), 'site page');
    }

    #[Test]
    public function theMonthlyEventLogIsCapped(): void
    {
        $dir = $this->tmpDir . '/sites/' . self::SITE . '/events';
        mkdir($dir, 0775, true);
        $file = $dir . '/' . gmdate('Y-m') . '.jsonl';
        // A full month: many lines, the newest last.
        $line = json_encode(['received_at' => 'x', 'events' => [['event' => 'old']]]) . "\n";
        file_put_contents($file, str_repeat($line, (int) (21 * 1024 * 1024 / strlen($line))));
        file_put_contents($file, json_encode(['received_at' => 'y', 'events' => [['event' => 'newest_one']]]) . "\n", FILE_APPEND);

        $this->pushEvents([['event' => 'x']], 429);

        // The site page reads the tail only, and still shows the newest event.
        $this->accept($this->fixture_());
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $page   = $this->get('/site/' . self::SITE);
        $this->assertStringContainsString('newest_one', $page);
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    #[Test]
    public function hostileIntegrityReportsAreRefused(): void
    {
        foreach ([
            ['modified' => 'not a list'],
            ['modified' => [['nested']]],
            ['checked' => '12'],
            ['unexpected' => array_fill(0, 50_001, 'x')],
        ] as $integrity) {
            $body = (string) json_encode(['schema_version' => '1.0', 'site_id' => self::SITE, 'integrity' => $integrity]);
            $this->assertSame(422, $this->pushRaw($body, 'integrity')['status'], (string) json_encode(array_keys($integrity)));
        }
    }

    // --- hostile probe answers -------------------------------------------------

    /**
     * The site under attack answers our probes: headers, page bodies, its
     * certificate, DNS and WHOIS all carry its strings. Parsed by the probes'
     * own parsers, stored, then rendered.
     */
    #[Test]
    public function hostileProbeAnswersRenderInert(): void
    {
        $id = $this->accept($this->fixture_());
        $x  = self::SCRIPT . self::ATTR;

        $http = HttpProbe::parseMainResponse(200, [
            'server' => $x, 'x-powered-by' => $x, 'content-security-policy' => $x, 'strict-transport-security' => $x,
            'x-frame-options' => $x, 'set-cookie' => $x, 'location' => self::JS_URL, 'alt-svc' => $x,
        ], ['http_version' => 0, 'url' => self::JS_URL], [$x . '; secure']);
        $http['robots']         = HttpProbe::parseRobots("User-agent: *\nDisallow: {$x}\nSitemap: " . self::JS_URL);
        $http['usernames']      = HttpProbe::extractUsernames((string) json_encode([['slug' => $x, 'name' => $x]]));
        $http['redirect_chain'] = [['url' => self::JS_URL, 'status' => 301, 'location' => self::JS_URL]];

        $tls = TlsProbe::parseCertificate([
            'subject'          => ['CN' => $x, 'O' => $x],
            'issuer'           => ['CN' => $x, 'O' => self::JS_URL],
            'extensions'       => ['subjectAltName' => 'DNS:' . $x . ', DNS:' . self::JS_URL],
            'validFrom_time_t' => 0,
            'validTo_time_t'   => 4102444800,
            'serialNumberHex'  => $x,
            'signatureTypeSN'  => $x,
        ], 'example.com', true);

        $dns = DnsProbe::parseRecords([
            'txt'   => [['txt' => 'v=spf1 ' . $x]],
            'mx'    => [['target' => $x, 'pri' => 10]],
            'ns'    => [['target' => $x]],
            'a'     => [['ip' => $x]],
            'caa'   => [['value' => $x, 'tag' => $x]],
            'dmarc' => [['txt' => 'v=DMARC1; rua=' . self::JS_URL]],
        ]);

        $rdap = RdapProbe::parseWhoisText("Registrar: {$x}\nRegistrar URL: " . self::JS_URL . "\nCreation Date: {$x}\nRegistry Expiry Date: {$x}\nName Server: {$x}");

        foreach (['http' => $http, 'tls' => $tls, 'dns' => $dns, 'rdap' => $rdap] as $probe => $data) {
            $this->app->dataStore()->writeProbeResult(self::SITE, $id, $probe, (new ProbeResult(
                $probe, '1', self::SITE, $x, '2026-01-01T00:00:00Z', 1, ProbeResult::STATUS_WARN, $data, [$x, self::JS_URL]
            ))->toArray());
        }

        $this->analyse($id);
        $this->assertEverythingRendersInert($id);
    }

    // --- catalogue ----------------------------------------------------------------

    #[Test]
    public function catalogueGrowthIsCapped(): void
    {
        $catalog = $this->app->softwareCatalog();
        $plugins = [];
        for ($i = 0; $i < 2_000; $i++) {
            $plugins["p{$i}/p{$i}.php"] = ['slug' => "p{$i}/p{$i}.php", 'name' => 'x'];
        }
        for ($round = 0; $round < 12; $round++) {
            $catalog->recordExtraction(['plugins' => array_combine(
                array_map(static fn ($k) => "r{$round}{$k}", array_keys($plugins)),
                array_map(static fn ($p) => ['slug' => "r{$round}" . $p['slug']] + $p, $plugins)
            )]);
        }

        $this->assertLessThanOrEqual(\SatelliteWP\Manager\Catalog\SoftwareCatalog::MAX_ENTRIES, count($catalog->all()));
    }

    #[Test]
    public function catalogueSlugsNeverCarryPathSegments(): void
    {
        $catalog = \SatelliteWP\Manager\Catalog\SoftwareCatalog::class;

        $this->assertSame('', $catalog::normalizeSlug('plugin', '../x.php'));
        $this->assertSame('', $catalog::normalizeSlug('theme', '../x'));
        $this->assertSame('', $catalog::normalizeSlug('theme', '..'));
        $this->assertSame('', $catalog::normalizeSlug('plugin', "a\0b.php"));
        $this->assertSame('woocommerce', $catalog::normalizeSlug('plugin', 'woocommerce/woocommerce.php'));
        $this->assertSame('hello', $catalog::normalizeSlug('plugin', 'hello.php'));
        $this->assertSame('storefront-child', $catalog::normalizeSlug('theme', 'storefront-child'));
    }

    // --- harness ------------------------------------------------------------------

    private function assertEverythingRendersInert(string $id): void
    {
        $this->assertInert($this->get('/site/' . self::SITE), 'site page');
        $this->assertInert($this->get($this->extractionPath($id)), 'extraction page');
        $this->assertInert($this->get('/'), 'sites list');

        $_GET = ['draw' => '1', 'start' => '0', 'length' => '200'];
        $catalog = $this->request('/catalog/search');
        $_GET = [];
        $this->assertSame(200, $catalog->status);
        $decoded = json_decode($catalog->body, true);
        $this->assertIsArray($decoded);
        foreach ((array) $decoded['data'] as $row) {
            $this->assertInert(implode(' ', array_map('strval', (array) $row)), 'catalogue row');
        }

        $raw = $this->request($this->extractionPath($id) . '/raw/payload.json');
        $this->assertContains('X-Content-Type-Options: nosniff', $raw->headers);
        $this->assertContains('Content-Type: application/json; charset=utf-8', $raw->headers);

        $report = $this->request($this->extractionPath($id) . '/report.json?token=' . $this->token($id));
        $this->assertSame(200, $report->status, $report->body);
        $this->assertIsArray(json_decode($report->body, true));
        $this->assertReportInert((array) json_decode($report->body, true));
    }

    /**
     * report.json feeds a Google Doc: values and cells are plain text there,
     * so only the observation title/message — rendered as markup — matter.
     *
     * @param array<array-key, mixed> $report
     */
    private function assertReportInert(array $report): void
    {
        $this->assertNotEmpty($report['fields'] ?? []);
        foreach ($this->leaves($report, '') as $path => $value) {
            // Only fields are rendered by the Doc engine; 'findings' is plain data.
            if (preg_match('/^\.fields\..*\.(title|message)$/', $path) === 1) {
                // Unescaped markup from the payload would become a link in the client's Doc.
                $this->assertStringNotContainsString('[click](https://attacker', $value, $path);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, string>
     */
    private function leaves(array $data, string $prefix): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix . '.' . $key;
            if (is_array($value)) {
                $out += $this->leaves($value, $path);
            } elseif (is_string($value)) {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    private function assertInert(string $html, string $where): void
    {
        $this->assertStringNotContainsString(self::SCRIPT, $html, $where);
        $this->assertStringNotContainsString('<img src=x', $html, $where);
        $this->assertStringNotContainsString("' onmouseover='", $html, $where);
        $this->assertDoesNotMatchRegularExpression('/(href|src|action)\s*=\s*["\']?\s*javascript:/i', $html, $where);
    }

    private function assertRenders(string $path, string $context): void
    {
        $response = $this->request($path);
        $this->assertContains($response->status, [200], "{$context} → {$path}: HTTP {$response->status} " . substr($response->body, 0, 300));
    }

    private function get(string $path): string
    {
        $response = $this->request($path);
        $this->assertSame(200, $response->status, $path);

        return $response->body;
    }

    /** Warnings ("Array to string conversion") count as failures: they mean broken output. */
    private function request(string $path): RecordingResponse
    {
        $query = parse_url($path, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $params);
            $_GET = $params + $_GET;
            $path = (string) parse_url($path, PHP_URL_PATH);
        }

        $response = new RecordingResponse();
        set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            throw new ErrorException($message, 0, $no, $file, $line);
        });
        ob_start();
        try {
            (new Router($this->app, $response))->dispatch($path);
        } finally {
            $response->body .= (string) ob_get_clean();
            restore_error_handler();
            $_GET = [];
        }

        return $response;
    }

    /** Rules over the stored payload, as the pipeline does after its probes; then "done". */
    private function analyse(string $id): void
    {
        set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            throw new ErrorException($message, 0, $no, $file, $line);
        });
        try {
            $store   = $this->app->dataStore();
            $payload = (array) $store->readExtractionPayload(self::SITE, $id);
            $this->app->softwareCatalog()->recordExtraction($payload);
            $findings = $this->app->ruleEngine()->evaluate(
                new Context($payload, $store->readAllProbeResults(self::SITE, $id), $this->app->referenceData())
            );
            $findings['site_id']       = self::SITE;
            $findings['extraction_id'] = $id;
            $store->writeFindings(self::SITE, $id, $findings);
        } finally {
            restore_error_handler();
        }
        $this->app->index()->setExtractionStatus(self::SITE, $id, Index::STATUS_DONE);
    }

    private function token(string $id): string
    {
        return rawurlencode($this->app->reportTokenStore()->issue(self::SITE, $id));
    }

    private function extractionPath(string $id): string
    {
        return '/site/' . self::SITE . '/extraction/' . $id;
    }

    /** @param array<string, mixed> $payload */
    private function accept(array $payload): string
    {
        $result = $this->push($payload);
        $this->assertSame(200, $result['status'], (string) json_encode($result['body']));

        return (string) $result['body']['id'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function push(array $payload, string $type = 'extraction'): array
    {
        return $this->pushRaw((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE), $type);
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function pushRaw(string $body, string $type = 'extraction', ?App $app = null): array
    {
        // A distinct body per push (trailing JSON whitespace): the replay cache refuses a repeat.
        $timestamp = (string) time();
        $body     .= str_repeat(' ', ++$this->clock);

        return ($app ?? $this->app)->extractor()->handle([
            'site'      => self::SITE,
            'type'      => $type,
            'timestamp' => $timestamp,
            'signature' => hash_hmac('sha256', $timestamp . '.' . $body, self::KEY),
        ], $body, '192.0.2.1');
    }

    /** @param list<mixed> $events */
    private function pushEvents(array $events, int $expected): void
    {
        $this->pushEventsRaw((string) json_encode(['schema_version' => '1.0', 'site_id' => self::SITE, 'events' => $events]), $expected);
    }

    private function pushEventsRaw(string $body, int $expected): void
    {
        $result = $this->pushRaw($body, 'event');
        $this->assertSame($expected, $result['status'], (string) json_encode($result['body']));
    }

    private function removeExtraction(string $id): void
    {
        $dir   = $this->app->dataStore()->extractionDir(self::SITE, $id);
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /** @return array<string, mixed> */
    private function fixture_(): array
    {
        return $this->fixtureArray('extraction-valid.json');
    }

    /** A hostile string reduced to what a file-name key may hold (no slash, dot segment or control). */
    private function fileSafe(string $value): string
    {
        $clean = (string) preg_replace('/[\/\\\\\x00-\x1f\x7f]/', '_', substr($value, 0, 120));

        return trim($clean, '.') === '' ? 'x' : $clean;
    }

    /**
     * @param array<string, mixed> $map
     * @return array<string, mixed>
     */
    private function renameKey(array $map, string $from, string $to): array
    {
        $entry = $map[$from];
        unset($map[$from]);
        $entry['slug'] = $to;
        $map[$to]      = $entry;

        return $map;
    }

    /**
     * Dot paths of every node down to $depth (maps only, plus the first list item).
     *
     * @param array<array-key, mixed> $data
     * @return list<string>
     */
    private function paths(array $data, string $prefix, int $depth): array
    {
        $paths = [];
        foreach ($data as $key => $value) {
            if (array_is_list($data) && $key !== 0) {
                break;
            }
            $path    = $prefix === '' ? (string) $key : $prefix . "\x1f" . $key;
            $paths[] = $path;
            if (is_array($value) && $depth > 1) {
                array_push($paths, ...$this->paths($value, $path, $depth - 1));
            }
        }

        return $paths;
    }

    /** @param array<array-key, mixed> $data */
    private function setPath(array &$data, string $path, mixed $value): void
    {
        $ref = &$data;
        foreach (explode("\x1f", $path) as $segment) {
            if (!is_array($ref)) {
                return;
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    /** @param array<string, mixed> $overrides */
    private function makeApp(array $overrides = []): App
    {
        $defaults = require dirname(__DIR__, 2) . '/config/config.php';

        return new App(new Config(array_replace_recursive($defaults, [
            'data_dir' => $this->tmpDir,
            'auth'     => ['users_file' => $this->tmpDir . '/users.json', 'open_mode' => true],
        ], $overrides)));
    }
}
