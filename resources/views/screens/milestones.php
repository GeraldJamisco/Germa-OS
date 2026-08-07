<?php

declare(strict_types=1);

/**
 * Goals → Milestones.
 *
 * One timeline, not one list per owner. A project milestone and a goal deadline
 * falling in the same week are the same problem, and grouping them by what they
 * belong to would hide that.
 *
 * @var array<string,mixed> $data From MilestoneService::build()
 */

use Germa\Support\Html;
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Goals</span>
    <h1 class="t-display pagehead__h1">Milestones</h1>
  </div>

  <div class="segmented" role="group" aria-label="How far ahead to look">
    <?php foreach ($data['windows'] as $option): ?>
      <a class="segmented__option"
        href="<?= e(Html::url('milestones')) ?>?window=<?= e($option['key']) ?>"
        aria-pressed="<?= $option['active'] ? 'true' : 'false' ?>"><?= e($option['label']) ?><?php
          if ($option['count'] !== null && $option['count'] > 0): ?> · <?= (int) $option['count'] ?><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'Operations Director', 'Gathered from goal milestones, goal deadlines and project milestones') ?>
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

<?php if ($data['groups'] === [] && $data['undated'] === []): ?>
  <section class="card">
    <div class="empty">
      Nothing scheduled in this window.<br>
      Goals and projects both accept milestones. Until one has a date, there is
      nothing that can be late, which means nothing that can be tracked.
    </div>
  </section>
<?php endif; ?>

<?php foreach ($data['groups'] as $group): ?>
  <section class="card">
    <div class="card__head">
      <div class="inline">
        <span class="prio prio--<?= $group['key'] === 'overdue' ? 'high' : ($group['key'] === 'week' ? 'warn' : 'quiet') ?>">
          <?= e(strtoupper($group['label'])) ?>
        </span>
        <span class="t-note"><?= e($group['sub']) ?></span>
      </div>
      <span class="t-note"><?= count($group['rows']) ?></span>
    </div>

    <div class="stack--tight">
      <?php foreach ($group['rows'] as $item): ?>
        <div class="duerow<?= $item['state'] === 'overdue' ? ' duerow--critical' : ($item['state'] === 'soon' ? ' duerow--high' : '') ?>">
          <span class="duerow__dot"></span>
          <span class="duerow__title"><?= e($item['title']) ?></span>

          <span class="t-note flex-none">
            <?= e($item['belongs_label']) ?>: <?= e($item['belongs_to']) ?>
          </span>

          <?php if ($item['metric'] !== null): ?>
            <span class="t-note flex-none">Done when: <?= e((string) $item['metric']) ?></span>
          <?php endif; ?>

          <span class="duerow__when"><?= e($item['when']) ?></span>

          <?php if ($item['kind'] === 'milestone'): ?>
            <button class="btn btn--sm flex-none" type="button" data-quick-add="milestone-done">Mark done</button>
          <?php else: ?>
            <button class="btn btn--sm flex-none" type="button" data-ask="<?= e($item['ask']) ?>">Ask</button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>

<?php if ($data['undated'] !== []): ?>
  <section class="card">
    <div class="card__head">
      <span class="t-card-title">No Date At All</span>
      <?= chip_raw('unavailable', 'Not scheduled', 'Work with no date is remembered rather than scheduled, and it slips silently') ?>
    </div>

    <p class="t-body">
      These exist but are not on any timeline. Give each one a date and it becomes
      something that can be late, which is the only way it can be managed.
    </p>

    <div class="stack--tight">
      <?php foreach ($data['undated'] as $item): ?>
        <div class="duerow">
          <span class="duerow__dot"></span>
          <span class="duerow__title"><?= e($item['title']) ?></span>
          <span class="t-note flex-none"><?= e($item['belongs_label']) ?>: <?= e($item['belongs_to']) ?></span>
          <span class="duerow__when">No date</span>
          <button class="btn btn--sm flex-none" type="button" data-ask="<?= e($item['ask']) ?>">Ask</button>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<section class="card">
  <div class="card__head">
    <span class="t-card-title">Add a Milestone</span>
    <span class="t-note"><?= (int) $data['total'] ?> tracked in total</span>
  </div>
  <p class="t-body">
    A milestone needs a date and a line saying what "done" means. Without the
    second one, finishing becomes a matter of opinion.
  </p>
  <button class="btn btn--primary card__foot" type="button" data-quick-add="goal-milestone">+ Add a milestone</button>
</section>
