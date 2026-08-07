<?php

declare(strict_types=1);

/**
 * Household → Household Overview.
 *
 * Not another money screen. The spec says plainly: do not treat Gerald's life
 * purely as a business optimisation problem, the objective is a sustainable life
 * rather than maximum income. So this asks the one question the rest of the OS
 * cannot ask about itself, and puts it in the dark card: is the business being
 * run at the household's expense?
 *
 * @var array<string,mixed> $data From HouseholdService::build()
 */

$cover = $data['cover'];
$load  = $data['load'];
$split = $data['split'];
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Household</span>
    <h1 class="t-display pagehead__h1">Household Overview</h1>
  </div>
  <?= chip_raw('calculated', 'Life & Household', 'Cover, workload and commitments, all read from records you already keep') ?>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'Household Director', 'Derived from cash, savings, logged hours and commitment cadences') ?>
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

<section class="row row--wide">

  <!-- Cover, stated in months. Shillings are the wrong unit for this question. -->
  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Household Cover</span>
      <span class="t-note">Target <?= (int) $cover['target_months'] ?> months</span>
    </div>

    <div class="meter">
      <div class="meter__head">
        <span class="t-note"><?= e($cover['funded']->format()) ?> against <?= e($cover['survival']->format()) ?> a month</span>
        <span class="meter__value"><?= e($cover['months_label']) ?></span>
      </div>
      <div class="bar bar--thick">
        <div class="bar__fill<?= $cover['tone'] === 'good' ? '' : ' bar__fill--coral' ?>"
          style="width:<?= e(bar($cover['pct'])) ?>"></div>
      </div>
    </div>

    <div class="deflist deflist--ruled">
      <div class="defrow">
        <span class="defrow__key">Cash in hand</span>
        <span class="defrow__value"><?= e($cover['cash']->format()) ?></span>
      </div>
      <div class="defrow">
        <span class="defrow__key">Set aside</span>
        <span class="defrow__value"><?= e($cover['reserve']->format()) ?></span>
      </div>
      <div class="defrow">
        <span class="defrow__key">Monthly requirement</span>
        <span class="defrow__value"><?= e($cover['survival']->format()) ?></span>
      </div>
    </div>
  </div>

  <!-- Load. The OS is the only thing positioned to notice a person running
       without maintenance. -->
  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Workload and Rest</span>
      <span class="t-note">Last seven days</span>
    </div>

    <div class="meter">
      <div class="meter__head">
        <span class="t-note"><?= e($load['pace_label']) ?></span>
        <span class="meter__value"><?= e($load['hours_label']) ?></span>
      </div>
      <div class="bar bar--thick">
        <div class="bar__fill<?= $load['heavy'] ? ' bar__fill--coral' : '' ?>"
          style="width:<?= e(bar($load['pct'])) ?>"></div>
      </div>
    </div>

    <div class="deflist deflist--ruled">
      <div class="defrow">
        <span class="defrow__key">Days worked of the last 7</span>
        <span class="defrow__value"><?= (int) $load['days_worked'] ?> of 7</span>
      </div>
      <div class="defrow">
        <span class="defrow__key">Since a full day off</span>
        <span class="defrow__value"><?= e($load['rest_label']) ?></span>
      </div>
      <div class="defrow">
        <span class="defrow__key">Pace considered heavy above</span>
        <span class="defrow__value"><?= (int) $load['cap'] ?>h a week</span>
      </div>
    </div>

    <?php if ($load['rest_overdue']): ?>
      <button class="btn btn--sm self-start" type="button"
        data-ask="I have not taken a full day off. What can I move or drop to take one this week without anything breaking?">
        Ask what can move
      </button>
    <?php endif; ?>
  </div>
</section>

<!-- What is owed to people rather than to creditors. -->
<section class="card">
  <div class="card__head">
    <span class="t-card-title">Commitments to People</span>
    <?= chip_raw('entered', 'Cadence based', 'Overdue means it was meant to happen every so often and it has been longer than that') ?>
  </div>

  <?php if ($data['commitments'] === []): ?>
    <div class="empty">
      Nothing recorded.<br>
      A weekly family day that quietly became fortnightly is the kind of debt no
      financial screen will ever show you. Record it and it becomes visible.
    </div>
  <?php else: ?>
    <div class="stack--tight">
      <?php foreach ($data['commitments'] as $commitment): ?>
        <div class="duerow<?= $commitment['overdue'] ? ' duerow--critical' : '' ?>">
          <span class="duerow__dot"></span>
          <span class="duerow__title"><?= e($commitment['title']) ?></span>
          <span class="t-note flex-none"><?= e($commitment['cadence_label']) ?></span>
          <span class="duerow__when"><?= e($commitment['since_label']) ?></span>
          <span class="prio prio--<?= e($commitment['tone']) ?>"><?= e($commitment['kind_label']) ?></span>

          <?php if ($commitment['overdue']): ?>
            <button class="btn btn--sm flex-none" type="button" data-ask="<?= e($commitment['ask']) ?>">Ask</button>
          <?php endif; ?>
          <button class="btn btn--sm flex-none" type="button" data-quick-add="commitment-done">Honoured</button>
        </div>

        <?php if ($commitment['note'] !== null): ?>
          <div class="minute">
            <p class="minute__a"><?= e((string) $commitment['note']) ?></p>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <button class="btn btn--primary card__foot" type="button" data-quick-add="commitment">+ Add a commitment</button>
</section>

<!-- Where the money goes, split household against business. -->
<section class="card">
  <div class="card__head">
    <span class="t-card-title">Household or Business</span>
    <span class="t-note">This month, base currency only</span>
  </div>

  <?php if (!$split['has_data']): ?>
    <div class="empty">
      Nothing spent this month yet.<br>
      Once expenses are logged, this shows how much of the outflow is the
      household living and how much is the cost of trading.
    </div>
  <?php else: ?>
    <div class="meter">
      <div class="meter__head">
        <span class="t-note">
          Household <?= e($split['household']->compact()) ?> · business <?= e($split['business']->compact()) ?>
        </span>
        <span class="meter__value"><?= (int) $split['household_pct'] ?>% household</span>
      </div>
      <div class="bar bar--thick">
        <div class="bar__fill bar__fill--coral" style="width:<?= e(bar($split['household_pct'])) ?>"></div>
      </div>
    </div>

    <div class="breakdown">
      <?php foreach ($split['rows'] as $row): ?>
        <div class="breakdown__row">
          <div class="breakdown__main">
            <div class="breakdown__head">
              <span class="breakdown__name"><?= e($row['name']) ?></span>
              <span class="breakdown__amount"><?= e($row['amount']->format()) ?></span>
            </div>
            <div class="bar">
              <div class="bar__fill<?= $row['is_household'] ? ' bar__fill--coral' : ' bar__fill--quiet' ?>"
                style="width:<?= e(bar($row['pct'])) ?>"></div>
            </div>
            <span class="breakdown__status"><?= $row['is_household'] ? 'Household' : 'Cost of trading' ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="breakdown__total">
      <span>Total out this month</span>
      <strong><?= e($split['total']->format()) ?></strong>
    </div>
  <?php endif; ?>
</section>
