<?php

declare(strict_types=1);

/**
 * Business → Revenue.
 *
 * @var array<string,mixed> $data From RevenueService::build()
 */

use Germa\Support\Html;

$trend = $data['trend'];
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Business</span>
    <h1 class="t-display pagehead__h1">Revenue</h1>
  </div>

  <div class="segmented" role="group" aria-label="Reporting period">
    <?php foreach ($data['periods'] as $option): ?>
      <a class="segmented__option"
        href="<?= e(Html::url('revenue')) ?>?period=<?= e($option['key']) ?>"
        aria-pressed="<?= $option['active'] ? 'true' : 'false' ?>"><?= e($option['label']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'CFO', 'Derived from recorded receipts and their sources') ?>
  <p class="insight__text"><?= e($data['insight']['text']) ?></p>
</div>

<section class="metrics">
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

<section class="card stack--loose">
  <div class="card__head">
    <span class="t-card-title">Revenue Trend · 6 months</span>
    <?= chip_raw('entered', 'Entered', 'Summed from recorded receipts') ?>
  </div>

  <?php if (!$trend['has_data']): ?>
    <div class="empty">
      No revenue recorded in the last six months.<br>
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
    <?php foreach ($data['facts'] as $fact): ?>
      <div class="cardstats__item">
        <span class="cardstats__label"><?= e($fact['label']) ?></span>
        <span class="cardstats__value<?= $fact['tone'] === 'bad' ? ' cardstats__value--bad' : ($fact['tone'] === 'good' ? ' cardstats__value--good' : '') ?>">
          <?= e($fact['value']) ?>
        </span>
      </div>
    <?php endforeach; ?>
    <div class="cardstats__item push-right">
      <span class="cardstats__label">Recurring means</span>
      <span class="cardstats__value">paid 3 months running</span>
    </div>
  </div>
</section>

<section class="row row--wide">

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Revenue by Source</span>
      <span class="t-note"><?= e($data['range_label']) ?></span>
    </div>

    <?php if ($data['sources']['rows'] === []): ?>
      <div class="empty">No sources tracked yet.</div>
    <?php else: ?>
      <div class="breakdown">
        <?php foreach ($data['sources']['rows'] as $source): ?>
          <div class="breakdown__row">
            <div class="breakdown__main">
              <div class="breakdown__head">
                <span class="breakdown__name"><?= e($source['name']) ?></span>
                <span class="breakdown__amount"><?= e($source['amount']->format()) ?></span>
              </div>
              <div class="bar">
                <div class="bar__fill<?= $source['amount']->isZero() ? ' bar__fill--quiet' : ' bar__fill--coral' ?>"
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
  </div>

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Recent Revenue Events</span>
      <a href="<?= e(Html::url('income')) ?>" class="btn--link">View all →</a>
    </div>

    <?php if ($data['recent'] === []): ?>
      <div class="empty">
        Nothing received yet.<br>
        Every figure on this screen is read from what you log.
      </div>
    <?php else: ?>
      <div class="txns">
        <?php foreach ($data['recent'] as $event): ?>
          <div class="txn">
            <div class="tile tile--in">↑</div>
            <div class="txn__body">
              <span class="txn__name"><?= e($event['name']) ?></span>
              <span class="txn__meta"><?= e($event['meta']) ?></span>
            </div>
            <span class="txn__amount txn__amount--in"><?= e($event['amount']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <button class="btn btn--primary card__foot" type="button" data-quick-add="income">+ Log revenue</button>
  </div>
</section>
