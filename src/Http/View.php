<?php

declare(strict_types=1);

namespace Germa\Http;

use Germa\Service\ChromeService;

/**
 * Renders a screen inside the shared application shell.
 *
 * Screens are content fragments, not whole documents. The sidebar, top bar,
 * command dock and quick-add modal are rendered once here, so every page in
 * Germa OS gets the same chrome by construction rather than by copy-paste.
 */
final class View
{
    /**
     * @param string              $view   Fragment under resources/views (no extension).
     * @param array<string,mixed> $vars   Made available to the fragment.
     * @param array<string,mixed> $layout Shell options: see layout/app.php.
     */
    public static function render(string $view, array $vars = [], array $layout = []): void
    {
        $file = GERMA_ROOT . '/resources/views/' . $view . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $view");
        }

        // View helpers must exist before the fragment renders, not just before
        // the shell does, the fragment is what runs first.
        require_once GERMA_ROOT . '/resources/views/helpers.php';

        $chrome = $layout['chrome'] ?? ChromeService::make()->build($layout['route'] ?? '');

        // The fragment renders into a buffer so the shell can place it.
        ob_start();
        (static function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP);
            require $__file;
        })($file, $vars + ['chrome' => $chrome]);
        $content = (string) ob_get_clean();

        $layout = array_replace([
            'title'         => 'Germa OS',
            'route'         => '',
            'topbar_action' => null,   // ['label' => …, 'quick_add' => …] or raw HTML
            'show_dock'     => true,
            'suggestions'   => [],
            'body_attrs'    => '',
        ], $layout);

        require GERMA_ROOT . '/resources/views/layout/app.php';
    }
}
