<?php

declare(strict_types=1);

/**
 * Money → Savings.
 *
 * A content fragment assembled entirely from existing components, this screen
 * introduced none. See docs/design-system.md.
 *
 * @var array<string,mixed> $data From SavingsService::build()
 */

use Germa\Support\Html;

$balance = $data['balance'];
$trend   = $data['trend'];
?>

<!-- Page header + period switch -->
<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Money</span>
    <h1 class="t-display pagehead__h1">Savings</h1>
  </div>

  <div class="segmented" role="group" aria-label="Reporting period">
    <?php foreach ($data['periods'] as $option): ?>
      <a class="segmented__option"
        href="<?= e(Html::url('savings')) ?>?period=<?= e($option['key']) ?>"
        aria-pressed="<?= $option['active'] ? 'true' : 'false' ?>"><?= e($option['label']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Period statement -->
<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'Insight', 'Derived from your recorded deposits and survival cost, no model involved') ?>
  <p class="insight__text"><?= e($data['insight']['text']) ?></p>
</div>

<!-- Total saved leads; supporting figures follow -->
<section class="metrics metrics--lead">

  <div class="card card--dark">
    <div class="card__head">
      <span class="t-card-title">Total Saved</span>
      <span class="chip chip--on-dark">Entered</span>
    </div>

    <div class="t-figure t-figure--lg"><?= e($balance['metric']->display) ?></div>

    <div class="stack--tight">
      <?php if ($balance['has_target']): ?>
        <div class="spread t-note">
          <span>of <?= e($balance['target']->format()) ?> across all goals</span>
          <span><?= e(pct_label($balance['progress_pct'])) ?></span>
        </div>
        <div class="bar bar--thick">
          <div class="bar__fill bar__fill--coral" style="width:<?= e(bar($balance['progress_pct'], 0)) ?>"></div>
        </div>
      <?php endif; ?>
      <span class="t-note">
        Held apart from available cash, a deposit moves money you already
        counted into a pot you don't spend from.
      </span>
    </div>

    <button class="btn btn--primary card__foot push-bottom" type="button" data-quick-add="savings">
      Deposit now
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

<!-- Six-month growth -->
<section class="card stack--loose">
  <div class="card__head">
    <span class="t-card-title">Savings Growth · 6 months</span>
    <?= chip_raw('entered', 'Entered', 'Balance at the end of each month') ?>
  </div>

  <?php if (!$trend['has_data']): ?>
    <div class="empty">
      Nothing saved in the last six months.<br>
      The reserve is step five of the hierarchy: survival, income and debt come first.
    </div>
  <?php else: ?>
    <div class="chart chart--single"
      style="grid-template-columns:repeat(<?= count($trend['months']) ?>,minmax(0,1fr))">
      <?php foreach ($trend['months'] as $month): ?>
        <div class="chart__col<?= $month['is_now'] ? ' chart__col--now' : '' ?>">
          <div class="chart__pair">
            <div class="chart__bar" style="height:<?= e(bar($month['pct'], 0)) ?>"
              title="<?= e($month['label'] . ': ' . $month['balance']->format() . ' saved'
                  . ($month['net']->isZero() ? ', no movement' : ', ' . $month['net']->format() . ' that month')) ?>"></div>
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
      <span class="cardstats__label">A flat bar means</span>
      <span class="cardstats__value">no deposit that month</span>
    </div>
  </div>
</section>

<!-- Goals + activity -->
<section class="row row--wide">

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Savings Goals</span>
      <span class="t-note">Ranked by priority</span>
    </div>

    <?php if ($data['goals']['rows'] === []): ?>
      <div class="empty">
        No savings goals yet.<br>
        The emergency fund is the one the spec asks for first.
      </div>
    <?php else: ?>
      <div class="breakdown breakdown--ranked">
        <?php foreach ($data['goals']['rows'] as $goal): ?>
          <div class="breakdown__row">
            <div class="tile tile--rank<?= $goal['is_priority'] ? ' tile--lead' : '' ?>"><?= e($goal['rank']) ?></div>
            <div class="breakdown__main">
              <div class="breakdown__head">
                <span class="breakdown__name"><?= e($goal['name']) ?></span>
                <span class="breakdown__amount"><?= e($goal['amount_label']) ?></span>
              </div>
              <div class="bar">
                <div class="bar__fill<?= $goal['is_priority'] ? ' bar__fill--coral' : ' bar__fill--quiet' ?>"
                  style="width:<?= e(bar($goal['pct'])) ?>"></div>
              </div>
              <span class="breakdown__status"><?= e($goal['status']) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="breakdown__total">
      <span>Combined target</span>
      <strong><?= e($data['goals']['total_target']->format()) ?></strong>
    </div>

    <button class="btn card__foot" type="button" data-quick-add="savings-goal">+ Add savings goal</button>
  </div>

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Recent Activity</span>
      <a href="<?= e(Html::url('finance')) ?>" class="btn--link">View all →</a>
    </div>

    <?php if ($data['activity'] === []): ?>
      <div class="empty">
        No deposits recorded.<br>
        Start funding once the income plan is covering the month.
      </div>
    <?php else: ?>
      <div class="txns">
        <?php foreach ($data['activity'] as $entry): ?>
          <div class="txn">
            <div class="tile tile--<?= e($entry['variant']) ?>"><?= $entry['is_in'] ? '↑' : '↓' ?></div>
            <div class="txn__body">
              <span class="txn__name"><?= e($entry['name']) ?></span>
              <span class="txn__meta"><?= e($entry['meta']) ?></span>
            </div>
            <span class="txn__amount<?= $entry['is_in'] ? ' txn__amount--in' : '' ?>"><?= e($entry['amount']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <button class="btn btn--primary card__foot" type="button" data-quick-add="savings">+ Add to savings</button>
  </div>
</section>
