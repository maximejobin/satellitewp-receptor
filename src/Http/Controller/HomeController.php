<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http\Controller;

final class HomeController extends Controller
{
    /** @param array<string, string> $params */
    public function home(array $params): void
    {
        $this->render('home', ['title' => 'SatelliteWP Manager', 'nav' => '']);
    }

    /** @param array<string, string> $params */
    public function styleguide(array $params): void
    {
        $this->render('styleguide', [
            'title'      => 'Style guide',
            'nav'        => 'styleguide',
            'dataTables' => true,
            'select2'    => true,
            'tooltip'    => true,
            'csrf'       => $this->csrfToken(),
        ]);
    }
}
