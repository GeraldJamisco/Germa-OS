<?php

declare(strict_types=1);

/**
 * Money → Debt.
 *
 * A content fragment assembled entirely from existing components, this screen
 * introduced none. See docs/design-system.md.
 *
 * @var array<string,mixed> $data From DebtOverviewService::build()
 */

use Germa\Support\Html;

$balance = $data['balance'];
$trend   = $data['trend'];
?>

<!-- Page header + period switch -->
<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Money</span>
    <h1 class="t-display pagehead__h1">Debt</h1>
  </div>

  <div class="segmented" role="group" aria-label="Reporting period">
    <?php foreach ($data['periods'] as $option): ?>
      <a class="segmented__option"
        href="<?= e(Html::url('debt')) ?>?period=<?= e($option['key']) ?>"
        aria-pressed="<?= $option['active'] ? 'true' : 'false' ?>"><?= e($option['label']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Period statement -->
<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'Insight', 'Derived from your recorded debts and payments, no model involved') ?>
  <p class="insight__text"><?= e($data['insight']['text']) ?></p>
</div>

<!-- Balance leads; supporting figures follow -->
<section class="metrics metrics--lead">

  <div class="card card--dark">
    <div class="card__head">
      <span class="t-card-title">Remaining Debt</span>
      <span class="chip chip--on-dark">Entered</span>
    </div>

    <div class="t-figure t-figure--lg"><?= e($balance['metric']->display) ?></div>

    <?php if (!$balance['clear']): ?>
      <div class="stack--tight">
        <div class="spread t-note">
          <span><?= e($balance['repaid']->format()) ?> repaid</span>
          <span><?= e(pct_label($balance['repaid_pct'])) ?></span>
        </div>
        <div class="bar bar--thick">
          <div class="bar__fill bar__fill--coral" style="width:<?= e(bar($balance['repaid_pct'], 0)) ?>"></div>
        </div>
        <span class="t-note">Of <?= e($balance['principal']->format()) ?> originally borrowed.</span>
      </div>
    <?php else: ?>
      <p class="card__body">Nothing outstanding. No future income is claimed by a lender.</p>
    <?php endif; ?>

    <button class="btn btn--primary card__foot push-bottom" type="button" data-quick-add="debt-payment">
      Make a payment
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

<!-- Payoff progress: the balance over time, not payments per month -->
<section class="card stack--loose">
  <div class="card__head">
    <span class="t-card-title">Payoff Progress · 6 months</span>
    <?= chip_raw('calculated', 'Reconstructed', 'Balance wound back from today using recorded payments') ?>
  </div>

  <?php if (!$trend['has_data']): ?>
    <div class="empty">
      No debts recorded.<br>
      Add one and its balance over time appears here.
    </div>
  <?php else: ?>
    <div class="chart chart--single"
      style="grid-template-columns:repeat(<?= count($trend['months']) ?>,minmax(0,1fr))">
      <?php foreach ($trend['months'] as $month): ?>
        <div class="chart__col<?= $month['is_now'] ? ' chart__col--now' : '' ?>">
          <div class="chart__pair">
            <div class="chart__bar" style="height:<?= e(bar($month['pct'], 0)) ?>"
              title="<?= e($month['label'] . ': ' . $month['balance']->format() . ' outstanding'
                  . ($month['paid']->isZero() ? '' : ', ' . $month['paid']->format() . ' repaid that month')) ?>"></div>
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
        <span class="cardstats__value<?= $fact['tone'] === 'bad' ? ' cardstats__value--bad' : '' ?>"><?= e($fact['value']) ?></span>
      </div>
    <?php endforeach; ?>
    <div class="cardstats__item push-right">
      <span class="cardstats__label">A flat bar means</span>
      <span class="cardstats__value">no payment that month</span>
    </div>
  </div>
</section>

<!-- Obligations + history -->
<section class="row row--wide">

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Debt Obligations</span>
      <span class="t-note">Ranked by balance</span>
    </div>

    <?php if ($data['obligations']['rows'] === []): ?>
      <div class="empty">
        No active debts.<br>
        That is the position this screen exists to get you back to.
      </div>
    <?php else: ?>
      <div class="breakdown breakdown--ranked">
        <?php foreach ($data['obligations']['rows'] as $row): ?>
          <div class="breakdown__row">
            <div class="tile tile--rank<?= $row['urgent'] ? ' tile--lead' : '' ?>"><?= e($row['rank']) ?></div>
            <div class="breakdown__main">
              <div class="breakdown__head">
                <span class="breakdown__name"><?= e($row['name']) ?></span>
                <span class="breakdown__amount"><?= e($row['amount']->format()) ?></span>
              </div>
              <div class="bar">
                <div class="bar__fill<?= $row['urgent'] ? ' bar__fill--coral' : ' bar__fill--quiet' ?>"
                  style="width:<?= e(bar($row['pct'])) ?>"></div>
              </div>
              <span class="breakdown__status"><?= e($row['status']) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="breakdown__total">
      <span>Total outstanding</span>
      <strong><?= e($data['obligations']['total']->format()) ?></strong>
    </div>

    <button class="btn card__foot" type="button" data-quick-add="debt">+ Add debt</button>
  </div>

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Payment History</span>
      <a href="<?= e(Html::url('finance')) ?>" class="btn--link">View all →</a>
    </div>

    <?php if ($data['history'] === []): ?>
      <div class="empty">
        No payments recorded yet.<br>
        The payoff projection stays blank until there is a pace to measure.
      </div>
    <?php else: ?>
      <div class="txns">
        <?php foreach ($data['history'] as $payment): ?>
          <div class="txn">
            <div class="tile tile--debt">↓</div>
            <div class="txn__body">
              <span class="txn__name"><?= e($payment['name']) ?></span>
              <span class="txn__meta"><?= e($payment['meta']) ?></span>
            </div>
            <span class="txn__amount"><?= e($payment['amount']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <button class="btn btn--primary card__foot" type="button" data-quick-add="debt-payment">+ Log payment</button>
  </div>
</section>
