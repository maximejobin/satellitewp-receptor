<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http\Controller;

use SatelliteWP\Xtractor\Http\PayloadValidator;
use SatelliteWP\Xtractor\Http\Session;

/** The extraction-tracked sites list, a site's page and its API key management. */
final class SiteController extends Controller
{
    /** @param array<string, string> $params */
    public function list(array $params): void
    {
        if (!$this->requireCapability('extraction_view_technical')) {
            return;
        }

        $this->render('sites', [
            'title'  => 'Extractions',
            'nav'    => 'sites',
            'sites'  => $this->app->index()->listSites($_GET['q'] ?? null),
            'search' => (string) ($_GET['q'] ?? ''),
            'notice' => (string) ($_GET['notice'] ?? ''),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(array $params): void
    {
        if (!$this->requireCapability('extraction_view_technical')) {
            return;
        }

        $siteId = $params['site_id'];
        $site   = $this->app->dataStore()->readSiteInfo($siteId);
        $keyRow = $this->app->keyStore()->all()[$siteId] ?? null;

        // site.json is written by the first extraction: a freshly paired site
        // has a key but no site.json yet, and still needs a page for it.
        if ($site === null && $keyRow === null) {
            $this->notFound();

            return;
        }
        $site ??= [];

        Session::start();
        $created = $_SESSION['flash_key'] ?? null;
        if (($created['site_id'] ?? null) === $siteId) {
            unset($_SESSION['flash_key']);
        } else {
            $created = null;
        }

        $this->render('site', [
            'title'       => $site['site_url'] ?? $keyRow['origin'] ?? $siteId,
            'nav'         => 'sites',
            'site'        => $site,
            'siteId'      => $siteId,
            'extractions' => $this->app->index()->listExtractions($siteId),
            'events'      => $this->recentEvents($siteId, 20),
            'keyRow'      => $keyRow,
            'createdKey'  => $created,
            'csrf'        => $this->csrfToken(),
        ]);
    }

    /**
     * POST /keys — create, revoke, rebind, and the per-site probing Basic
     * Auth credentials. Always lands back on /site/{id}.
     *
     * @param array<string, string> $params
     */
    public function keys(array $params): void
    {
        $siteId = (string) ($_POST['site_id'] ?? '');
        $action = (string) ($_POST['action'] ?? '');

        if (!PayloadValidator::isUuid($siteId)) {
            $this->redirect('/?notice=invalid-uuid');

            return;
        }

        $capability = match ($action) {
            'add'                          => 'site_key_add',
            'revoke'                       => 'site_key_revoke',
            'rebind'                       => 'site_key_rebind',
            'http_auth', 'http_auth_clear' => 'site_http_auth_edit',
            default                        => null,
        };
        // An unknown action does nothing but redirect.
        if ($capability !== null && !$this->requireCapability($capability)) {
            return;
        }

        $keys = $this->app->keyStore();
        switch ($action) {
            case 'add':
                $origin = trim((string) ($_POST['origin'] ?? ''));
                $origin = $origin !== '' ? PayloadValidator::normalizeOrigin($origin) : null;
                $key    = $keys->addKey($siteId, null, $origin);

                // Shown exactly once, on the site page right after.
                Session::start();
                $_SESSION['flash_key'] = ['site_id' => $siteId, 'key' => $key, 'origin' => $origin];
                break;
            case 'revoke':
                $keys->revokeKey($siteId);
                break;
            case 'rebind':
                $url = trim((string) ($_POST['url'] ?? ''));
                if ($url !== '') {
                    $keys->setOrigin($siteId, PayloadValidator::normalizeOrigin($url));
                }
                break;
            case 'http_auth':
                $username = trim((string) ($_POST['http_auth_username'] ?? ''));
                $password = (string) ($_POST['http_auth_password'] ?? '');
                if ($username !== '' && $password !== '') {
                    $keys->setHttpAuth($siteId, $username, $password);
                }
                break;
            case 'http_auth_clear':
                $keys->setHttpAuth($siteId, null, null);
                break;
        }

        $this->redirect('/site/' . $siteId);
    }

    /**
     * Newest first. Monthly files are read newest-first and the walk stops at
     * $limit, so the cost is bounded by one month's volume, not the history.
     *
     * @return list<array<string, mixed>>
     */
    private function recentEvents(string $siteId, int $limit): array
    {
        $files = glob($this->app->dataStore()->siteDir($siteId) . '/events/*.jsonl') ?: [];
        rsort($files);

        $events = [];
        foreach ($files as $file) {
            foreach (array_reverse(array_filter(explode("\n", (string) file_get_contents($file)))) as $line) {
                $batch = json_decode($line, true);
                if (!is_array($batch)) {
                    continue;
                }
                foreach (array_reverse((array) ($batch['events'] ?? [])) as $event) {
                    $events[] = (array) $event + ['received_at' => $batch['received_at'] ?? null];
                    if (count($events) >= $limit) {
                        return $events;
                    }
                }
            }
        }

        return $events;
    }
}
