<?php

declare(strict_types=1);

/**
 * Placeholder for a routed section that has no screen yet.
 *
 * @var string $section
 */

use Germa\Support\Html;

$title = ucwords(str_replace('-', ' ', $section));
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Germa OS</span>
    <h1 class="t-display pagehead__h1"><?= e($title) ?></h1>
  </div>
</div>

<div class="card">
  <p class="t-body">
    This section has a route and its data already flows through the backend, it
    just has no interface yet.
  </p>
  <a class="btn btn--primary card__foot" href="<?= e(Html::url('')) ?>">← Back to the command center</a>
</div>
