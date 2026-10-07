<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http\Controller;

use SatelliteWP\Manager\Http\Router;

/** /catalog — the cross-site free/premium plugin & theme classification. */
final class CatalogController extends Controller
{
    /** @param array<string, string> $params */
    public function page(array $params): void
    {
        if (!$this->requireCapability('catalog_view')) {
            return;
        }

        $this->render('catalog', [
            'title'            => 'Software catalogue',
            'nav'              => 'catalog',
            'dataTables'       => true,
            'needsOnly'        => !empty($_GET['needs']),
            'unclassifiedOnly' => !empty($_GET['unclassified']),
        ]);
    }

    /**
     * Datatables server-side endpoint — the catalogue grows into thousands of
     * entries, too many to render at once.
     *
     * @param array<string, string> $params
     */
    public function search(array $params): void
    {
        if (!$this->requireCapability('catalog_view')) {
            return;
        }

        $length = (int) ($_GET['length'] ?? 50);
        $length = $length > 0 ? min($length, 200) : 50;

        $result = $this->app->softwareCatalog()->search(
            null,
            ($_GET['needs'] ?? '') === 'true',
            ($_GET['unclassified'] ?? '') === 'true',
            (string) ($_GET['search']['value'] ?? ''),
            max(0, (int) ($_GET['start'] ?? 0)),
            $length
        );

        $this->response->json([
            'draw'            => (int) ($_GET['draw'] ?? 0),
            'recordsTotal'    => $result['total'],
            'recordsFiltered' => $result['filtered'],
            'data'            => self::tableRows($result['rows'], $this->csrfToken()),
        ]);
    }

    /**
     * DataTables inserts each cell as HTML, and slugs and names come from
     * plugin headers: every text cell is escaped here.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<list<string>>
     */
    public static function tableRows(array $rows, string $csrf): array
    {
        require_once dirname(__DIR__, 2) . '/Web/helpers.php';

        return array_map(static fn (array $e): array => [
            e($e['type'] ?? ''),
            e($e['slug'] ?? ''),
            e($e['name'] ?? ''),
            license_select(
                (string) ($e['type'] ?? ''),
                (string) ($e['slug'] ?? ''),
                (string) ($e['license'] ?? 'unknown'),
                $csrf,
                '/catalog',
                is_string($e['suggested'] ?? null) ? $e['suggested'] : null
            ),
        ], $rows);
    }

    /**
     * POST /catalog. A rejected save answers 400, never a redirect: the
     * licence dropdown's fetch() reads any redirect as "saved".
     *
     * @param array<string, string> $params
     */
    public function save(array $params): void
    {
        if (!$this->requireCapability('catalog_edit')) {
            return;
        }

        $type  = (string) ($_POST['type'] ?? '');
        $slug  = (string) ($_POST['slug'] ?? '');
        $saved = in_array($type, ['plugin', 'theme'], true)
            && $this->app->softwareCatalog()->setLicense($type, $slug, (string) ($_POST['license'] ?? ''));

        if (!$saved) {
            $this->response->text(400, 'Could not save the licence.');

            return;
        }

        // Keep the SQLite cross-reference current without a full reindex.
        $entry = $this->app->softwareCatalog()->get($type, $slug);
        if ($entry !== null) {
            $this->app->catalogIndex()->upsertCatalogEntry($entry);
        }

        $this->redirect(Router::safeReturn($_POST['return'] ?? '/catalog'));
    }
}
