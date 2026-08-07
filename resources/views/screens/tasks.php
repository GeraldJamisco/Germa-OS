<?php

declare(strict_types=1);

/**
 * Execution → Tasks. The Operations Director's outstanding list.
 *
 * @var array<string,mixed> $data From TaskBoardService::build()
 */

use Germa\Support\Html;
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Execution</span>
    <h1 class="t-display pagehead__h1">Tasks</h1>
  </div>

  <div class="segmented" role="group" aria-label="Filter tasks">
    <?php foreach ($data['filters'] as $option): ?>
      <a class="segmented__option"
        href="<?= e(Html::url('tasks')) ?>?filter=<?= e($option['key']) ?>"
        aria-pressed="<?= $option['active'] ? 'true' : 'false' ?>"><?= e($option['label']) ?><?php
          if ($option['count'] !== null && $option['count'] > 0): ?> · <?= (int) $option['count'] ?><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'Operations', 'Derived from your recorded tasks and pipeline') ?>
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

<section class="card">
  <div class="card__head">
    <span class="t-card-title"><?= e($data['filter'] === 'all' ? 'All Tasks' : ucfirst($data['filter'])) ?></span>
    <span class="t-note"><?= count($data['tasks']) ?> shown</span>
  </div>

  <?php if ($data['tasks'] === []): ?>
    <div class="empty">
      <?php if ($data['filter'] === 'overdue'): ?>
        Nothing is overdue. That is the position to hold.
      <?php elseif ($data['filter'] === 'done'): ?>
        Nothing completed in the last seven days.
      <?php else: ?>
        No tasks here.<br>
        Today's three are proposed automatically: anything else is yours to add.
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="stack--tight">
      <?php foreach ($data['tasks'] as $task): ?>
        <div class="priority" data-done="<?= $task['done'] ? '1' : '0' ?>">
          <button class="priority__box" type="button" data-toggle-task="<?= (int) $task['id'] ?>"
            aria-pressed="<?= $task['done'] ? 'true' : 'false' ?>"
            aria-label="<?= e(($task['done'] ? 'Mark incomplete: ' : 'Mark complete: ') . $task['title']) ?>"><?= $task['done'] ? '✓' : '' ?></button>

          <div class="stack--tight">
            <div class="spread">
              <span class="priority__title"><?= e($task['title']) ?></span>
              <span class="t-note flex-none<?= $task['due_state'] === 'overdue' ? ' cardstats__value--bad' : '' ?>"><?= e($task['due_label']) ?></span>
            </div>
            <div class="tag-row">
              <span class="tag"><?= e($task['project_name'] ?? 'Unassigned') ?></span>
              <span class="prio<?= $task['priority'] === 'HIGH' ? ' prio--high' : '' ?>"><?= e($task['priority']) ?></span>
              <?php if ($task['rank_label'] !== null): ?>
                <span class="tag"><?= e($task['rank_label']) ?></span>
              <?php endif; ?>
              <?php if ($task['estimate_label'] !== null): ?>
                <span class="tag"><?= e($task['estimate_label']) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <button class="btn btn--primary card__foot" type="button" data-quick-add="task">+ Add task</button>
</section>
