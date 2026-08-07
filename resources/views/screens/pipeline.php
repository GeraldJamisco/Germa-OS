<?php

declare(strict_types=1);

/**
 * Business → Sales Pipeline.
 *
 * @var array<string,mixed> $data From SalesPipelineService::build()
 */

use Germa\Support\Html;

$funnel = $data['funnel'];
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Business</span>
    <h1 class="t-display pagehead__h1">Sales Pipeline</h1>
  </div>

  <div class="segmented" role="group" aria-label="Reporting period">
    <?php foreach ($data['periods'] as $option): ?>
      <a class="segmented__option"
        href="<?= e(Html::url('pipeline')) ?>?period=<?= e($option['key']) ?>"
        aria-pressed="<?= $option['active'] ? 'true' : 'false' ?>"><?= e($option['label']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'Sales Director', 'Derived from your recorded deals and stage history') ?>
  <p class="insight__text"><?= e($data['insight']['text']) ?></p>
</div>

<!-- The funnel -->
<section class="card stack--loose">
  <div class="card__head">
    <span class="t-card-title">Pipeline Stages</span>
    <span class="t-note"><?= e($data['range_label']) ?></span>
  </div>

  <?php if (!$data['has_deals']): ?>
    <div class="empty">
      No deals recorded yet.<br>
      Every prospect you add enters here, and the weekly movement is read from that history.
    </div>
  <?php else: ?>
    <div class="funnel">
      <?php foreach ($funnel as $stage): ?>
        <div class="funnel__cell<?= $stage['is_won'] ? ' funnel__cell--won' : '' ?>">
          <div class="funnel__plot">
            <div class="funnel__bar" style="height:<?= e(bar($stage['height_pct'])) ?>"></div>
          </div>
          <div class="stack--tight">
            <span class="t-figure t-figure--md"><?= (int) $stage['count'] ?></span>
            <span class="t-note"><?= e($stage['label']) ?></span>
            <span class="funnel__delta funnel__delta--<?= e($stage['delta_direction']) ?>"><?= e($stage['delta_label']) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="cardstats">
    <div class="cardstats__item">
      <span class="cardstats__label">Pipeline value</span>
      <span class="cardstats__value"><?= e($data['open_value']->display) ?></span>
    </div>
    <div class="cardstats__item">
      <span class="cardstats__label">Expected · estimated</span>
      <span class="cardstats__value"><?= e($data['expected_value']->display) ?></span>
    </div>
    <div class="cardstats__item">
      <span class="cardstats__label">Conversion rate</span>
      <span class="cardstats__value<?= $data['conversion']['rate'] < 5 ? ' cardstats__value--bad' : '' ?>">
        <?= e(pct_label($data['conversion']['rate'])) ?>
      </span>
    </div>
    <div class="cardstats__item push-right">
      <span class="cardstats__label">Won of all deals</span>
      <span class="cardstats__value"><?= (int) $data['conversion']['won'] ?> / <?= (int) $data['conversion']['total'] ?></span>
    </div>
  </div>
</section>

<!-- Deals, ranked by how soon they could pay -->
<section class="card">
  <div class="card__head">
    <span class="t-card-title">Active Deals</span>
    <span class="t-note">Ranked by time-to-cash</span>
  </div>

  <?php if ($data['deals'] === []): ?>
    <div class="empty">No live deals.</div>
  <?php else: ?>
    <div class="stack--tight">
      <?php foreach ($data['deals'] as $deal): ?>
        <div class="project">
          <div class="project__name">
            <span class="project__title"><?= e($deal['title']) ?></span>
            <span class="t-note"><?= e($deal['contact']) ?></span>
          </div>
          <span class="prio prio--<?= e($deal['stage_tone']) ?>"><?= e($deal['stage_label']) ?></span>
          <span class="t-label project__meter"><?= e($deal['time_to_cash']) ?></span>
          <span class="project__revenue<?= $deal['value']->isZero() ? ' project__revenue--none' : '' ?>">
            <?= e($deal['value_label']) ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <button class="btn btn--primary card__foot" type="button" data-quick-add="deal">+ Add deal</button>
</section>
