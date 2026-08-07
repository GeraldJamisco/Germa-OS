<?php

declare(strict_types=1);

/**
 * Business → Clients.
 *
 * @var array<string,mixed> $data From ClientService::build()
 */

use Germa\Support\Html;
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Business</span>
    <h1 class="t-display pagehead__h1">Clients</h1>
  </div>

  <div class="segmented" role="group" aria-label="Filter clients">
    <?php foreach ($data['filters'] as $option): ?>
      <a class="segmented__option"
        href="<?= e(Html::url('clients')) ?>?filter=<?= e($option['key']) ?>"
        aria-pressed="<?= $option['active'] ? 'true' : 'false' ?>"><?= e($option['label']) ?><?php
          if ($option['count'] !== null && $option['count'] > 0): ?> · <?= (int) $option['count'] ?><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'Sales Director', 'Derived from your recorded deals and contact dates') ?>
  <p class="insight__text"><?= e($data['insight']['text']) ?></p>
</div>

<section class="metrics">
  <?php foreach ($data['summary'] as $item): ?>
    <div class="metric">
      <span class="metric__label"><?= e($item['label']) ?></span>
      <div class="metric__value<?= $item['tone'] === 'bad' ? ' metric__delta--bad' : ($item['tone'] === 'good' ? ' metric__delta--good' : '') ?>">
        <?= e($item['value']) ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>

<section class="card">
  <div class="card__head">
    <span class="t-card-title"><?= e($data['filter'] === 'all' ? 'All Clients' : ucfirst($data['filter'])) ?></span>
    <span class="t-note"><?= count($data['clients']) ?> shown</span>
  </div>

  <?php if ($data['clients'] === []): ?>
    <div class="empty">
      <?php if ($data['filter'] === 'quiet'): ?>
        No relationships have gone cold. That is the position to hold.
      <?php else: ?>
        No clients here.<br>
        Every prospect you add to the pipeline becomes one automatically.
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="stack--tight">
      <?php foreach ($data['clients'] as $client): ?>
        <div class="project">
          <div class="tile<?= $client['has_paid'] ? ' tile--lead' : '' ?>"><?= e($client['initials']) ?></div>
          <div class="project__name">
            <span class="project__title"><?= e($client['name']) ?></span>
            <span class="t-note"><?= e($client['sub']) ?></span>
          </div>
          <span class="prio prio--<?= e($client['status_tone']) ?>"><?= e($client['status']) ?></span>
          <span class="t-label project__meter"><?= e($client['last_contact']) ?></span>
          <span class="project__revenue<?= $client['won']->isZero() ? ' project__revenue--none' : '' ?>">
            <?= e($client['won_label']) ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="breakdown__total">
    <span>Revenue shown is the value of deals marked won, not money received</span>
  </div>

  <button class="btn btn--primary card__foot" type="button" data-quick-add="prospect">+ Add client</button>
</section>
