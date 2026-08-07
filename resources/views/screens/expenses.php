<?php

declare(strict_types=1);

/**
 * Money → Expenses.
 *
 * A content fragment. The only component this screen introduced is `.duerow`;
 * everything else is already in docs/design-system.md.
 *
 * @var array<string,mixed> $data From ExpenseService::build()
 */

use Germa\Support\Html;

$budget = $data['budget'];
$trend  = $data['trend'];
$bills  = $data['bills'];
?>

<!-- Page header + period switch -->
<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Money</span>
    <h1 class="t-display pagehead__h1">Expenses</h1>
  </div>

  <div class="segmented" role="group" aria-label="Reporting period">
    <?php foreach ($data['periods'] as $option): ?>
      <a class="segmented__option"
        href="<?= e(Html::url('expenses')) ?>?period=<?= e($option['key']) ?>"
        aria-pressed="<?= $option['active'] ? 'true' : 'false' ?>"><?= e($option['label']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Period statement -->
<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'Insight', 'Derived from your recorded ledger, no model involved') ?>
  <p class="insight__text"><?= e($data['insight']['text']) ?></p>
</div>

<!-- Budget leads; supporting figures follow -->
<section class="metrics metrics--lead">

  <div class="card card--dark">
    <div class="card__head">
      <span class="t-card-title">Monthly Survival Budget</span>
      <?= chip_raw($budget['metric']->isUnavailable() ? 'unavailable' : 'entered', $budget['metric']->isUnavailable() ? 'Not set' : 'Entered', (string) $budget['metric']->note) ?>
    </div>

    <div class="t-figure t-figure--lg"><?= e($budget['metric']->display) ?></div>

    <div class="stack--tight">
      <div class="spread t-note">
        <span><?= e($budget['spent']->format()) ?> spent</span>
        <span><?= e(pct_label($budget['pct'])) ?></span>
      </div>
      <div class="bar bar--thick">
        <div class="bar__fill bar__fill--coral" style="width:<?= e(bar($budget['pct'], 0)) ?>"></div>
      </div>
      <span class="t-note">
        <?php if ($budget['over']): ?>
          <?= e($budget['remaining']->format()) ?> over budget for the month.
        <?php else: ?>
          <?= e($budget['remaining']->format()) ?> left for the rest of the month.
        <?php endif; ?>
      </span>
    </div>

    <button class="btn btn--primary card__foot push-bottom" type="button" data-quick-add="bill">
      Add a bill
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
    <span class="t-card-title">Expense Trend · 6 months</span>
    <?= chip_raw('entered', 'Entered', 'Summed from recorded spending') ?>
  </div>

  <?php if (!$trend['has_data']): ?>
    <div class="empty">
      No spending recorded in the last six months.<br>
      Log an expense and the trend appears here.
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
      <span class="cardstats__label">Average monthly spend</span>
      <span class="cardstats__value"><?= e($trend['average']->format()) ?></span>
    </div>
    <div class="cardstats__item">
      <span class="cardstats__label">Highest month</span>
      <span class="cardstats__value"><?= e($trend['highest']->format()) ?></span>
    </div>
    <div class="cardstats__item">
      <span class="cardstats__label">Essential vs discretionary</span>
      <span class="cardstats__value">
        <?= (int) $trend['essential_pct'] ?>% / <?= (int) $trend['discretionary_pct'] ?>%
      </span>
    </div>
    <div class="cardstats__item push-right">
      <span class="cardstats__label">Measured on</span>
      <span class="cardstats__value">completed months</span>
    </div>
  </div>
</section>

<!-- Categories + recent -->
<section class="row row--wide">

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Expense Categories</span>
      <span class="t-note">Ranked by share</span>
    </div>

    <?php if ($data['categories']['rows'] === []): ?>
      <div class="empty">
        No spending recorded for <?= e($data['range_label']) ?>.<br>
        Categories appear as you log expenses against them.
      </div>
    <?php else: ?>
      <div class="breakdown breakdown--ranked">
        <?php foreach ($data['categories']['rows'] as $category): ?>
          <div class="breakdown__row">
            <div class="tile tile--rank<?= $category['is_essential'] ? '' : ' tile--lead' ?>"><?= e($category['rank']) ?></div>
            <div class="breakdown__main">
              <div class="breakdown__head">
                <span class="breakdown__name"><?= e($category['name']) ?></span>
                <span class="breakdown__amount"><?= e($category['amount']->format()) ?></span>
              </div>
              <div class="bar">
                <div class="bar__fill<?= $category['fill'] === 'coral' ? ' bar__fill--coral' : ($category['fill'] === 'quiet' ? ' bar__fill--quiet' : '') ?>"
                  style="width:<?= e(bar($category['pct'])) ?>"></div>
              </div>
              <span class="breakdown__status"><?= e($category['status']) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="breakdown__total">
      <span>Total for <?= e($data['range_label']) ?></span>
      <strong><?= e($data['categories']['total']->format()) ?></strong>
    </div>

    <button class="btn card__foot" type="button" data-quick-add="expense">+ Log an expense</button>
  </div>

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Recent Expenses</span>
      <a href="<?= e(Html::url('finance')) ?>" class="btn--link">View all →</a>
    </div>

    <?php if ($data['recent'] === []): ?>
      <div class="empty">
        Nothing recorded yet.<br>
        Every figure on this screen is read from what you log here.
      </div>
    <?php else: ?>
      <div class="txns">
        <?php foreach ($data['recent'] as $expense): ?>
          <div class="txn">
            <div class="tile tile--<?= e($expense['variant']) ?>">↓</div>
            <div class="txn__body">
              <span class="txn__name"><?= e($expense['name']) ?></span>
              <span class="txn__meta"><?= e($expense['meta']) ?></span>
            </div>
            <span class="txn__amount"><?= e($expense['amount']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <button class="btn btn--primary card__foot" type="button" data-quick-add="expense">+ Log expense</button>
  </div>
</section>

<!-- Upcoming bills -->
<section class="card">
  <div class="card__head">
    <span class="t-card-title">Upcoming Bills</span>
    <span class="t-note"><?= (int) $bills['count'] ?> due within 14 days</span>
  </div>

  <?php if ($bills['rows'] === []): ?>
    <div class="empty">
      Nothing falls due in the next 14 days.<br>
      Add your recurring bills so the runway and today's target account for them.
    </div>
  <?php else: ?>
    <div class="stack--tight">
      <?php foreach ($bills['rows'] as $bill): ?>
        <div class="duerow duerow--<?= e($bill['tone']) ?>">
          <span class="duerow__dot"></span>
          <span class="duerow__title"><?= e($bill['title']) ?></span>
          <span class="duerow__when"><?= e($bill['when']) ?></span>
          <span class="duerow__amount"><?= e($bill['amount']->format()) ?></span>
          <?php if ($bill['id'] !== null): ?>
            <button class="btn btn--sm" type="button"
              data-pay-bill="<?= (int) $bill['id'] ?>"
              data-bill-title="<?= e($bill['title']) ?>">Mark paid</button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="breakdown__total">
      <span>Committed within 14 days<?= $bills['unfunded'] ? ': more than the cash in hand' : '' ?></span>
      <strong><?= e($bills['total']->format()) ?></strong>
    </div>
  <?php endif; ?>

  <button class="btn card__foot" type="button" data-quick-add="bill">+ Add a bill</button>
</section>
