<?php

declare(strict_types=1);

/**
 * Execution → Calendar.
 *
 * A derived view of commitments, not a diary. Every date here already exists
 * somewhere in the record: bills, debt payments, task deadlines, project
 * milestones and keep-or-kill reviews.
 *
 * @var array<string,mixed> $data From CalendarService::build()
 */

use Germa\Support\Html;

$strip = $data['strip'];
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Execution</span>
    <h1 class="t-display pagehead__h1">Calendar</h1>
  </div>

  <div class="segmented" role="group" aria-label="Calendar range">
    <?php foreach ($data['views'] as $option): ?>
      <a class="segmented__option"
        href="<?= e(Html::url('calendar')) ?>?view=<?= e($option['key']) ?>"
        aria-pressed="<?= $option['active'] ? 'true' : 'false' ?>"><?= e($option['label']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'CFO', 'Derived from your bills, debts and deadlines') ?>
  <p class="insight__text"><?= e($data['insight']['text']) ?></p>
</div>

<!-- The period at a glance -->
<section class="card">
  <div class="card__head">
    <span class="t-card-title"><?= e($strip['label']) ?></span>
    <span class="t-note">A dot marks a day with something on it</span>
  </div>

  <div class="strip" style="grid-template-columns:repeat(<?= count($strip['days']) > 7 ? 7 : count($strip['days']) ?>,minmax(0,1fr))">
    <?php foreach ($strip['days'] as $day): ?>
      <div class="strip__day<?= $day['today'] ? ' strip__day--today' : '' ?><?= $day['past'] ? ' strip__day--past' : '' ?>"
        title="<?= e($day['count'] === 0 ? 'Nothing scheduled' : $day['count'] . ' item' . ($day['count'] === 1 ? '' : 's')) ?>">
        <span class="strip__dow"><?= e($day['dow']) ?></span>
        <span class="strip__num"><?= e($day['num']) ?></span>
        <span class="strip__dot<?= $day['tone'] === 'none' ? '' : ' strip__dot--' . e($day['tone']) ?>"></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- Agenda + recurring -->
<section class="row row--wide">

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Upcoming: <?= (int) $data['horizon'] ?> days</span>
      <span class="t-note"><?= count($data['events']) ?> commitments</span>
    </div>

    <?php if ($data['events'] === []): ?>
      <div class="empty">
        Nothing falls in this window.<br>
        Bills, debt payments, task deadlines and project milestones all appear here automatically.
      </div>
    <?php else: ?>
      <div class="agenda">
        <?php foreach ($data['events'] as $event): ?>
          <div class="agenda__row<?= in_array($event['tone'], ['critical', 'high'], true) ? ' agenda__row--' . e($event['tone']) : '' ?>">
            <div class="datetile">
              <span class="datetile__mon"><?= e($event['month']) ?></span>
              <span class="datetile__day"><?= e($event['day']) ?></span>
            </div>
            <div class="agenda__body">
              <span class="agenda__title"><?= e($event['title']) ?></span>
              <span class="agenda__meta"><?= e($event['meta']) ?></span>
            </div>
            <?php if ($event['amount'] !== null): ?>
              <span class="agenda__amount"><?= e($event['amount']->format()) ?></span>
            <?php endif; ?>
            <span class="agenda__tag"><?= e($event['tag']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <button class="btn card__foot" type="button" data-quick-add="task">+ Add a dated task</button>
  </div>

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Recurring</span>
      <?= chip_raw('entered', 'Entered', 'Standing commitments you recorded') ?>
    </div>

    <?php if ($data['recurring'] === []): ?>
      <div class="empty">
        No recurring commitments recorded.<br>
        Adding them is what lets the runway and today's target account for them.
      </div>
    <?php else: ?>
      <div class="txns">
        <?php foreach ($data['recurring'] as $item): ?>
          <div class="txn">
            <div class="tile">↻</div>
            <div class="txn__body">
              <span class="txn__name"><?= e($item['title']) ?></span>
              <span class="txn__meta"><?= e($item['cadence']) ?></span>
            </div>
            <span class="txn__amount"><?= e($item['amount']->format()) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <button class="btn btn--primary card__foot" type="button" data-quick-add="bill">+ Add a bill</button>
  </div>
</section>
