<?php

declare(strict_types=1);

/**
 * The application shell. Every screen renders inside this.
 *
 * @var array<string,mixed> $chrome  From ChromeService::build()
 * @var array<string,mixed> $layout  Shell options from View::render()
 * @var string              $content The screen's rendered fragment
 */

use Germa\Http\Csrf;
use Germa\Support\Html;

require_once __DIR__ . '/../helpers.php';

$meta = $chrome['meta'];

// The token belongs to the session, not to any one screen: read it here so a
// page can never ship without one by forgetting to pass it through.
$token = Csrf::token();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <title><?= e($layout['title']) ?> · Germa OS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Sans:wght@500;700&family=Fragment+Mono&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(Html::asset('assets/css/germa.css')) ?>">
</head>

<body data-base="<?= e(Html::url('')) ?>" data-token="<?= e($token) ?>" <?= $layout['body_attrs'] ?>>

  <div class="shell">

    <!-- ── Sidebar ────────────────────────────────────────────────────────── -->
    <aside class="sidebar" id="sidebar" data-open="0">
      <div class="sidebar__brand">
        <div class="sidebar__mark">G</div>
        <div class="sidebar__wordmark">
          <span class="sidebar__name">Germa OS</span>
          <span class="t-eyebrow">Executive intelligence</span>
        </div>
      </div>

      <nav class="sidebar__nav" aria-label="Sections">
        <?php foreach ($chrome['nav'] as $group): ?>
          <div class="navgroup">
            <div class="navgroup__label t-eyebrow"><?= e($group['label']) ?></div>
            <?php foreach ($group['items'] as $item): ?>
              <a class="navlink" href="<?= e($item['route']) ?>" <?= $item['active'] ? ' aria-current="page"' : '' ?>>
                <span class="navlink__dot"></span>
                <span class="navlink__name"><?= e($item['name']) ?></span>
                <?php if ($item['badge'] !== ''): ?>
                  <span class="navlink__badge"><?= e($item['badge']) ?></span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </nav>

      <div class="sidebar__user">
        <div class="userchip">
          <div class="userchip__avatar"><?= e(mb_strtoupper(mb_substr($meta['operator'], 0, 2))) ?></div>
          <div class="userchip__id">
            <span class="userchip__name"><?= e($meta['operator']) ?></span>
            <span class="userchip__role">Operator<?= $meta['location'] === '' ? '' : ' · ' . e($meta['location']) ?></span>
          </div>
          <?php // A POST with the session token, so it cannot be triggered cross-site. ?>
          <form method="post" action="<?= e(Html::url('logout')) ?>" class="userchip__signout">
            <input type="hidden" name="_token" value="<?= e($token) ?>">
            <button class="iconbtn userchip__signout-btn" type="submit" aria-label="Sign out" title="Sign out">
              <svg viewBox="0 0 20 20" class="iconbtn__glyph" aria-hidden="true" focusable="false">
                <path d="M12.5 6.5V5a1.5 1.5 0 0 0-1.5-1.5H5A1.5 1.5 0 0 0 3.5 5v10A1.5 1.5 0 0 0 5 16.5h6a1.5 1.5 0 0 0 1.5-1.5v-1.5"
                  fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M8.5 10h8m0 0-2.5-2.5M16.5 10 14 12.5"
                  fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>
          </form>
        </div>
      </div>
    </aside>

    <!-- ── Main ───────────────────────────────────────────────────────────── -->
    <main class="main">

      <header class="topbar">
        <button class="iconbtn menu-toggle" type="button" data-menu-toggle aria-label="Open navigation">☰</button>

        <div class="search" role="search">
          <span aria-hidden="true">⌕</span>
          <span class="search__text">Search finances, clients, projects…</span>
          <span class="search__kbd">⌘K</span>
        </div>

        <div class="topbar__actions">
          <div class="status-pill<?= $meta['ai_live'] ? '' : ' status-pill--degraded' ?>"
            title="<?= e($meta['mode_label'] . ($meta['mode_reason'] ? ' · ' . $meta['mode_reason'] : '')) ?>">
            <span class="status-pill__dot"></span><?= e($meta['ai_live'] ? 'OS Active' : 'OS Active · rules only') ?>
          </div>

          <?php
          // The old diamond scrolled to an #alerts section that only the
          // dashboard has, so it was a dead control on fifteen screens. It is
          // now a real bell: the alerts are already evaluated for the badge, so
          // showing them costs nothing and a count you cannot open is just
          // something to worry about.
          $notifications = $chrome['alerts'] ?? [];
          ?>
          <div class="notif">
            <button class="iconbtn" type="button" data-notif-toggle
              aria-expanded="false" aria-controls="germa-notifications"
              aria-label="Notifications: <?= (int) $meta['alert_count'] ?> open">
              <svg class="iconbtn__glyph" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                <path d="M10 2.6a4.6 4.6 0 0 0-4.6 4.6c0 3-.85 4.35-1.6 5.1a.7.7 0 0 0 .5 1.2h11.4a.7.7 0 0 0 .5-1.2c-.75-.75-1.6-2.1-1.6-5.1A4.6 4.6 0 0 0 10 2.6Z"
                  fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                <path d="M8.1 16a2 2 0 0 0 3.8 0" fill="none" stroke="currentColor"
                  stroke-width="1.5" stroke-linecap="round"/>
              </svg>
              <?php if ($meta['alert_count'] > 0): ?><span class="iconbtn__dot"></span><?php endif; ?>
            </button>

            <div class="notif__panel" id="germa-notifications" hidden>
              <div class="notif__head">
                <span class="t-card-title">Notifications</span>
                <span class="t-note"><?= (int) $meta['alert_count'] ?> open</span>
              </div>

              <?php if ($notifications === []): ?>
                <div class="empty">
                  Nothing needs your attention.<br>
                  Alerts appear here when a figure crosses a line you set.
                </div>
              <?php else: ?>
                <div class="notif__list">
                  <?php foreach ($notifications as $note): ?>
                    <?php $href = $note['url'] === null ? null : Html::url(ltrim((string) $note['url'], '/')); ?>
                    <<?= $href === null ? 'div' : 'a' ?> class="notif__item notif__item--<?= e($note['tone']) ?>"
                      <?= $href === null ? '' : 'href="' . e($href) . '"' ?>>
                      <span class="notif__dot"></span>
                      <span class="notif__body">
                        <span class="notif__title"><?= e($note['title']) ?></span>
                        <span class="t-note"><?= e($note['body']) ?></span>
                      </span>
                      <?php if ($note['label'] !== null): ?>
                        <span class="t-label notif__cue"><?= e((string) $note['label']) ?></span>
                      <?php endif; ?>
                    </<?= $href === null ? 'div' : 'a' ?>>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <a class="btn btn--quiet notif__foot" href="<?= e(Html::url('insights')) ?>">See all insights</a>
            </div>
          </div>

          <?php
          $action = $layout['topbar_action'] ?? ['label' => '+ Quick Add', 'quick_add' => 'income'];
          ?>
          <?php // A screen can point the button at its own composer instead of a
                // quick-add form. Rendering a quick-add with no type would give
                // the topbar a button that silently does nothing. ?>
          <?php if (!empty($action['href'])): ?>
            <a class="btn btn--primary" href="<?= e(Html::url($action['href'])) ?>"><?= e($action['label']) ?></a>
          <?php else: ?>
            <button class="btn btn--primary" type="button"
              <?php if (!empty($action['focus'])): ?>data-focus="<?= e($action['focus']) ?>"<?php
                else: ?>data-quick-add="<?= e($action['quick_add'] ?? 'income') ?>"<?php endif; ?>>
              <?= e($action['label']) ?>
            </button>
          <?php endif; ?>
        </div>
      </header>

      <div class="page">
        <?php if ($meta['demo_data']): ?>
          <div class="banner">
            <strong>Demo data loaded.</strong>
            These pipeline, project and spending figures are illustrative, not yours.
            Run <code>php bin/migrate.php --fresh</code> to start from your real baseline.
          </div>
        <?php endif; ?>

        <?php if ($meta['mode'] === 'emergency'): ?>
          <div class="banner">
            <strong>Emergency mode.</strong>
            <?= e($meta['mode_reason'] ?? 'Cash is critical.') ?>
            Build work and non-essential spending are deprioritised until runway recovers.
          </div>
        <?php endif; ?>

        <?= $content ?>
      </div>

      <?php if ($layout['show_dock']): ?>
        <?php
        $suggestions = $layout['suggestions'];
        require __DIR__ . '/dock.php';
        ?>
      <?php endif; ?>
    </main>
  </div>

  <div class="toasts" data-toasts aria-live="polite"></div>

  <?php require __DIR__ . '/../partials/quick-add-modal.php'; ?>

  <script type="application/json" data-germa-projects>
    <?= json_encode($chrome['projects'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>
  </script>

  <script type="application/json" data-germa-savings-goals>
    <?= json_encode($chrome['savings_goals'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>
  </script>

  <script type="application/json" data-germa-decisions>
    <?= json_encode($chrome['decisions'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>
  </script>

  <script type="application/json" data-germa-goals>
    <?= json_encode($chrome['goals'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>
  </script>

  <script type="application/json" data-germa-milestones>
    <?= json_encode($chrome['milestones'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>
  </script>

  <script type="application/json" data-germa-commitments>
    <?= json_encode($chrome['commitments'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>
  </script>

  <script src="<?= e(Html::asset('assets/js/germa.js')) ?>" defer></script>
</body>

</html>
