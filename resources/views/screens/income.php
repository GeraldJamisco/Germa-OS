<?php

declare(strict_types=1);

/**
 * Money → Income.
 *
 * A content fragment built entirely from components in docs/design-system.md.
 * The only thing it adds is nothing, every element here already existed after
 * the Financial Overview, apart from the single-series chart variant.
 *
 * @var array<string,mixed> $data From IncomeService::build()
 */

use Germa\Support\Html;

$target = $data['target'];
$trend  = $data['trend'];
?>

<!-- Page header + period switch -->
<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Money</span>
    <h1 class="t-display pagehead__h1">Income</h1>
  </div>

  <div class="segmented" role="group" aria-label="Reporting period">
    <?php foreach ($data['periods'] as $option): ?>
      <a class="segmented__option"
        href="<?= e(Html::url('income')) ?>?period=<?= e($option['key']) ?>"
        aria-pressed="<?= $option['active'] ? 'true' : 'false' ?>"><?= e($option['label']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Period statement -->
<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'Insight', 'Derived from your recorded ledger, no model involved') ?>
  <p class="insight__text"><?= e($data['insight']['text']) ?></p>
</div>

<!-- Today's target leads; supporting figures follow -->
<section class="metrics metrics--lead">

  <div class="card card--dark">
    <div class="card__head">
      <span class="t-card-title">Today's Income Target</span>
      <span class="chip chip--on-dark">Calculated</span>
    </div>

    <div class="t-figure t-figure--lg"><?= e($target['metric']->display) ?></div>

    <div class="stack--tight">
      <div class="spread t-note">
        <span><?= e($target['earned']->format()) ?> earned</span>
        <span><?= e(pct_label($target['progress_pct'])) ?></span>
      </div>
      <div class="bar bar--thick">
        <div class="bar__fill bar__fill--coral" style="width:<?= e(bar($target['progress_pct'], 0)) ?>"></div>
      </div>
      <span class="t-note"><?= e($target['basis']) ?></span>
    </div>

    <button class="btn btn--primary card__foot push-bottom" type="button"
      data-ask="Recalculate my income plan for today and tell me exactly where the money should come from.">
      Recalculate Plan
    </button>
  </div>

  <?php foreach ($data['summary'] as $item): ?>
    <div class="metric">
      <div class="metric__head">
        <span class="metric__label"><?= e($item['label']) ?></span>
        <?= chip($item['metric']) ?>
      </div>
      <div class="metric__value"><?= e($item['metric']->display) ?></div>
      <div class="metric__delta metric__delta--<?= e($item['tone']) ?>"><?= e($item['delta']) ?></div>
    </div>
  <?php endforeach; ?>
</section>

<!-- Six-month trend -->
<section class="card stack--loose">
  <div class="card__head">
    <span class="t-card-title">Income Trend · 6 months</span>
    <?= chip_raw('entered', 'Entered', 'Summed from recorded receipts') ?>
  </div>

  <?php if ($trend['max']->isZero()): ?>
    <div class="empty">
      No income recorded in the last six months.<br>
      Log a payment and the trend appears here.
    </div>
  <?php else: ?>
    <div class="chart chart--single"
      style="grid-template-columns:repeat(<?= count($trend['months']) ?>,minmax(0,1fr))">
      <?php foreach ($trend['months'] as $month): ?>
        <div class="chart__col<?= $month['is_now'] ? ' chart__col--now' : '' ?>">
          <div class="chart__pair">
            <div class="chart__bar" style="height:<?= e(bar($month['pct'], 0)) ?>"
              title="<?= e($month['label'] . ': ' . $month['amount']->format() . ($month['is_now'] ? ' (month in progress)' : '')) ?>"></div>
          </div>
          <span class="chart__label"><?= e($month['label']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="cardstats">
    <div class="cardstats__item">
      <span class="cardstats__label">Average monthly income</span>
      <span class="cardstats__value"><?= e($trend['average']->format()) ?></span>
    </div>
    <div class="cardstats__item">
      <span class="cardstats__label">Best month</span>
      <span class="cardstats__value"><?= e($trend['best']->format()) ?></span>
    </div>
    <div class="cardstats__item">
      <span class="cardstats__label">Gap to survival target</span>
      <span class="cardstats__value<?= $trend['gap']->isNegative() ? ' cardstats__value--bad' : ' cardstats__value--good' ?>">
        <?= e(($trend['gap']->isNegative() ? '' : '+') . $trend['gap']->format()) ?>
      </span>
    </div>
    <div class="cardstats__item push-right">
      <span class="cardstats__label">Measured on</span>
      <span class="cardstats__value">completed months</span>
    </div>
  </div>
</section>

<!-- Sources + payments -->
<section class="row row--wide">

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Income Sources</span>
      <span class="t-note">Ranked by contribution</span>
    </div>

    <?php if ($data['sources']['rows'] === []): ?>
      <div class="empty">
        No income sources yet.<br>
        Add a project, or log a payment against one, and it appears here.
      </div>
    <?php else: ?>
      <div class="breakdown breakdown--ranked">
        <?php foreach ($data['sources']['rows'] as $source): ?>
          <div class="breakdown__row">
            <div class="tile tile--rank<?= $source['is_active'] ? ' tile--lead' : '' ?>"><?= e($source['rank']) ?></div>
            <div class="breakdown__main">
              <div class="breakdown__head">
                <span class="breakdown__name"><?= e($source['name']) ?></span>
                <span class="breakdown__amount"><?= e($source['amount']->format()) ?></span>
              </div>
              <div class="bar">
                <div class="bar__fill<?= $source['is_active'] ? '' : ' bar__fill--quiet' ?>"
                  style="width:<?= e(bar($source['pct'])) ?>"></div>
              </div>
              <span class="breakdown__status"><?= e($source['status']) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="breakdown__total">
      <span>Total for <?= e($data['range_label']) ?></span>
      <strong><?= e($data['sources']['total']->format()) ?></strong>
    </div>

    <button class="btn card__foot" type="button" data-quick-add="project">+ Add income source</button>
  </div>

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Recent Payments</span>
      <a href="<?= e(Html::url('finance')) ?>" class="btn--link">View all →</a>
    </div>

    <?php if ($data['payments'] === []): ?>
      <div class="empty">
        No payments recorded.<br>
        Every figure on this screen is read from what you log here.
      </div>
    <?php else: ?>
      <div class="txns">
        <?php foreach ($data['payments'] as $payment): ?>
          <div class="txn">
            <div class="tile tile--in">↑</div>
            <div class="txn__body">
              <span class="txn__name"><?= e($payment['name']) ?></span>
              <span class="txn__meta"><?= e($payment['meta']) ?></span>
            </div>
            <span class="txn__amount txn__amount--in"><?= e($payment['amount']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <button class="btn btn--primary card__foot" type="button" data-quick-add="income">+ Log income</button>
  </div>
</section>
