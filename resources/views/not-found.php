<?php

declare(strict_types=1);

/**
 * 404. A standalone page: it is reached only after the auth and setup gates, so
 * the visitor is signed in, but the shell's sidebar assumes a real route and this
 * is not one. The suggestion chips and the dashboard link give a way out.
 *
 * @var string $path the unresolved path
 */

use Germa\Support\Html;

require_once __DIR__ . '/helpers.php';

$suggestions = [
    ['label' => 'Financial Overview', 'href' => 'finance'],
    ['label' => 'Active Projects',    'href' => 'projects'],
    ['label' => 'Sales Pipeline',     'href' => 'pipeline'],
    ['label' => 'Today',              'href' => 'today'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Not found · Germa OS</title>
<link rel="stylesheet" href="<?= e(Html::asset('assets/css/germa.css')) ?>">
</head>
<body>
<div class="errpage">

  <header class="errpage__bar">
    <div class="brandmark">G</div>
    <div class="brandlock__id" style="align-items:flex-start">
      <span class="brandlock__name" style="font-size:17px">Germa OS</span>
      <span class="authcaption">Executive intelligence</span>
    </div>
  </header>

  <main class="errpage__body">
    <div class="errpage__inner">
      <?= chip_raw('critical', 'Error 404', 'The requested path did not match any screen') ?>
      <h1 class="errpage__code">Screen not found</h1>
      <p class="t-body" style="max-width:460px">
        This module is not part of the current build. It may have moved, been
        renamed, or never existed in Germa OS.
      </p>

      <div class="card" style="max-width:360px;width:100%">
        <div class="deflist">
          <div class="defrow">
            <span class="defrow__key">Status</span>
            <span class="defrow__value">404 · Not found</span>
          </div>
          <div class="defrow" style="border-top:1px solid var(--border);padding-top:10px">
            <span class="defrow__key">System</span>
            <span class="defrow__value" style="color:var(--signal-good)">Operational</span>
          </div>
        </div>
      </div>

      <a class="btn btn--primary" href="<?= e(Html::url('')) ?>">Return to dashboard</a>

      <div class="errpage__chips">
        <?php foreach ($suggestions as $s): ?>
          <a class="btn btn--sm" href="<?= e(Html::url($s['href'])) ?>"><?= e($s['label']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </main>

  <footer class="errpage__foot">
    <span>Germa OS / Executive intelligence</span>
    <span>ERR_MODULE_UNRESOLVED</span>
  </footer>
</div>
</body>
</html>
