<?php

declare(strict_types=1);

/**
 * Money → Financial Overview.
 *
 * A content fragment. Every element on this screen is a component that already
 * exists in germa.css: .card, .chip, .bar, .metric, .breakdown, .txn, so it
 * inherits the dashboard's language instead of inventing a second one.
 *
 * @var array<string,mixed> $data From FinanceOverviewService::build()
 */

use Germa\Support\Html;

$debt  = $data['debt'];
$flow  = $data['cashflow'];
$runway = $data['runway'];
?>

<!-- Page header + period switch -->
<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Money</span>
    <h1 class="t-display pagehead__h1">Financial Overview</h1>
  </div>

  <div class="segmented" role="group" aria-label="Reporting period">
    <?php foreach ($data['periods'] as $option): ?>
      <a class="segmented__option"
        href="<?= e(Html::url('finance')) ?>?period=<?= e($option['key']) ?>"
        aria-pressed="<?= $option['active'] ? 'true' : 'false' ?>"><?= e($option['label']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Period statement -->
<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'Insight', 'Derived from your recorded ledger, no model involved') ?>
  <p class="insight__text"><?= e($data['insight']['text']) ?></p>
</div>

<!-- Summary row -->
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

<!-- Cash flow + runway -->
<section class="row row--wide">

  <div class="card stack--loose">
    <div class="card__head">
      <span class="t-card-title">Cash Flow · 6 months</span>
      <?= chip_raw('entered', 'Entered', 'Summed from recorded ledger entries') ?>
    </div>

    <?php if ($flow['max']->isZero()): ?>
      <div class="empty">
        No income or spending recorded in the last six months.<br>
        Record a few transactions and the shape of the month appears here.
      </div>
    <?php else: ?>
      <div class="chart" style="grid-template-columns:repeat(<?= count($flow['months']) ?>,minmax(0,1fr))">
        <?php foreach ($flow['months'] as $month): ?>
          <div class="chart__col<?= $month['is_now'] ? ' chart__col--now' : '' ?>">
            <div class="chart__pair">
              <div class="chart__bar chart__bar--in" style="height:<?= e(bar($month['in_pct'], 0)) ?>"
                title="<?= e($month['label']) ?> in: <?= e($month['in']->format()) ?>"></div>
              <div class="chart__bar chart__bar--out" style="height:<?= e(bar($month['out_pct'], 0)) ?>"
                title="<?= e($month['label']) ?> out: <?= e($month['out']->format()) ?>"></div>
            </div>
            <span class="chart__label"><?= e($month['label']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="legend">
        <span class="legend__item"><span class="legend__swatch legend__swatch--in"></span>Income</span>
        <span class="legend__item"><span class="legend__swatch legend__swatch--out"></span>Expenses</span>
        <span class="legend__item push-right t-note">Tallest bar <?= e($flow['max']->compact()) ?></span>
      </div>
    <?php endif; ?>
  </div>

  <!-- Runway: the one figure that decides everything else, so it gets the dark card -->
  <div class="card card--dark">
    <div class="card__head">
      <span class="t-card-title">Financial Runway</span>
      <span class="chip chip--on-dark">Calculated</span>
    </div>

    <div class="t-figure t-figure--hero"><?= e($runway['metric']->display) ?></div>
    <p class="card__body"><?= e($runway['explanation']) ?></p>

    <div class="bar bar--thick push-bottom"
      title="<?= e('Against a ' . $runway['goal_days'] . '-day comfort target') ?>">
      <div class="bar__fill bar__fill--coral" style="width:<?= e(bar($runway['pct_of_goal'])) ?>"></div>
    </div>

    <button class="btn btn--primary card__foot" type="button"
      data-ask="Build me an income plan that gets runway above <?= (int) $runway['goal_days'] ?> days.">
      Create Income Plan
    </button>
  </div>
</section>

<!-- Income sources + expense categories -->
<section class="row row--wide">

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Income Sources</span>
      <span class="t-note"><?= e($data['range_label']) ?></span>
    </div>

    <?php if ($data['income']['rows'] === []): ?>
      <div class="empty">No income recorded in this period.</div>
    <?php else: ?>
      <div class="breakdown">
        <?php foreach ($data['income']['rows'] as $row): ?>
          <div class="breakdown__row">
            <div class="breakdown__main">
              <div class="breakdown__head">
                <span class="breakdown__name"><?= e($row['name']) ?></span>
                <span class="breakdown__amount"><?= e($row['amount']->format()) ?></span>
              </div>
              <div class="bar">
                <div class="bar__fill" style="width:<?= e(bar($row['pct'])) ?>"></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="breakdown__total">
      <span>Total entered income</span>
      <strong><?= e($data['income']['total']->format()) ?></strong>
    </div>
  </div>

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Expense Categories</span>
      <span class="t-note"><?= e($data['range_label']) ?></span>
    </div>

    <?php if ($data['expenses']['rows'] === []): ?>
      <div class="empty">No spending recorded in this period.</div>
    <?php else: ?>
      <div class="breakdown">
        <?php foreach ($data['expenses']['rows'] as $row): ?>
          <div class="breakdown__row">
            <div class="breakdown__main">
              <div class="breakdown__head">
                <span class="breakdown__name"><?= e($row['name']) ?></span>
                <span class="breakdown__amount"><?= e($row['amount']->format()) ?></span>
              </div>
              <div class="bar">
                <div class="bar__fill<?= $row['fill'] === 'coral' ? ' bar__fill--coral' : ($row['fill'] === 'quiet' ? ' bar__fill--quiet' : '') ?>"
                  style="width:<?= e(bar($row['pct'])) ?>"></div>
              </div>
            </div>
            <?php if ($row['tag'] !== null): ?>
              <span class="tag tag--class tag--<?= e($row['tag_class']) ?>"><?= e($row['tag']) ?></span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="breakdown__total">
      <span>Total recorded spending</span>
      <strong><?= e($data['expenses']['total']->format()) ?></strong>
    </div>
  </div>
</section>

<!-- Transactions + debt -->
<section class="row row--wide">

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Recent Transactions</span>
      <a href="<?= e(Html::url('expenses')) ?>" class="btn--link">View all →</a>
    </div>

    <?php if ($data['transactions'] === []): ?>
      <div class="empty">Nothing recorded yet.</div>
    <?php else: ?>
      <div class="txns">
        <?php foreach ($data['transactions'] as $txn): ?>
          <div class="txn">
            <div class="tile tile--<?= e($txn['variant']) ?>"><?= e($txn['icon']) ?></div>
            <div class="txn__body">
              <span class="txn__name"><?= e($txn['name']) ?></span>
              <span class="txn__meta"><?= e($txn['meta']) ?></span>
            </div>
            <span class="txn__amount<?= $txn['is_in'] ? ' txn__amount--in' : '' ?>"><?= e($txn['amount']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <button class="btn card__foot" type="button" data-quick-add="expense">+ Add transaction</button>
  </div>

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Debt Summary</span>
      <?= chip($debt['total_metric']) ?>
    </div>

    <div>
      <div class="t-figure t-figure--lg"><?= e($debt['total_metric']->display) ?></div>
      <div class="t-label">
        Repaid <?= e($debt['summary']['repaid']->format()) ?> ·
        remaining <?= e($debt['summary']['remaining']->format()) ?>
      </div>
    </div>

    <div class="bar bar--thick">
      <div class="bar__fill bar__fill--quiet" style="width:<?= e(bar($debt['summary']['repaid_pct'])) ?>"></div>
    </div>

    <div class="deflist deflist--ruled">
      <div class="defrow">
        <span class="defrow__key">Next payment</span>
        <span class="defrow__value"><?= e($debt['next_metric']->display) ?></span>
      </div>
      <div class="defrow">
        <span class="defrow__key">Target payoff</span>
        <span class="defrow__value"><?= e($debt['payoff_label'] ?? 'None') ?></span>
      </div>
    </div>

    <button class="btn card__foot" type="button" data-quick-add="debt-payment">Manage Debt →</button>
  </div>
</section>
