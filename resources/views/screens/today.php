<?php

declare(strict_types=1);

/**
 * Execution → Today: the spec's DAILY COMMAND BRIEF.
 *
 * The eight items the spec requires, in its order: financial position,
 * income target, top three, highest-value action, sales actions, build
 * actions, risks, and the one thing not to do, then the end-of-day review.
 *
 * @var array<string,mixed> $data From TodayService::build()
 */

use Germa\Support\Html;

$meta   = $data['meta'];
$brief  = $data['brief'];
$review = $data['review'];

$briefIsAi = $brief['generator'] === 'claude';
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Execution</span>
    <h1 class="t-display pagehead__h1">Today</h1>
  </div>
  <span class="t-label"><?= e($meta['stamp']) ?></span>
</div>

<!-- 1–2, 4, 8: the command brief itself -->
<section class="card card--dark">
  <div class="card__head">
    <span class="t-card-title">Daily Command Brief</span>
    <span class="chip chip--on-dark">
      <?= e($briefIsAi ? 'AI generated' : 'Rules generated') ?> · <?= e(substr($brief['generated_at'], 11, 5)) ?>
    </span>
  </div>

  <p class="board__quote"><?= e($brief['brief']) ?></p>

  <?php if (!empty($brief['do_not'])): ?>
    <div class="donot">
      <span class="donot__label">Do not</span>
      <span class="donot__text"><?= e($brief['do_not']) ?></span>
    </div>
  <?php endif; ?>

  <div class="board__signals">
    <?php foreach ($data['brief_stats'] as $stat): ?>
      <div class="signal">
        <span class="signal__role"><?= e($stat['label']) ?></span>
        <span class="signal__glyph<?= $stat['tone'] === 'bad' ? ' signal__glyph--alert' : '' ?>">
          <?= e($stat['value']) ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="inline">
    <button class="btn btn--primary" type="button" data-refresh-ai>Regenerate brief</button>
    <?php if ($data['highest'] !== null): ?>
      <span class="t-note">
        Highest-value action: <?= e($data['highest']['title']) ?>
      </span>
    <?php endif; ?>
  </div>
</section>

<!-- 3: the top three -->
<section class="card">
  <div class="card__head">
    <span class="t-card-title">Top 3 Priorities</span>
    <?= chip_raw('ai', 'AI proposed', 'Generated from your goals, finances and deadlines') ?>
  </div>

  <?php if ($data['priorities'] === []): ?>
    <div class="empty">No priorities set for today.</div>
  <?php else: ?>
    <?php foreach ($data['priorities'] as $task): ?>
      <div class="priority" data-done="<?= $task['done'] ? '1' : '0' ?>">
        <button class="priority__box" type="button" data-toggle-task="<?= (int) $task['id'] ?>"
          aria-pressed="<?= $task['done'] ? 'true' : 'false' ?>"
          aria-label="<?= e(($task['done'] ? 'Mark incomplete: ' : 'Mark complete: ') . $task['title']) ?>"><?= $task['done'] ? '✓' : '' ?></button>
        <div class="stack--tight">
          <div class="priority__title"><?= e($task['title']) ?></div>
          <div class="tag-row">
            <?php if ($task['rank_label'] !== null): ?><span class="tag"><?= e($task['rank_label']) ?></span><?php endif; ?>
            <?php if ($task['estimate_label'] !== null): ?><span class="tag"><?= e($task['estimate_label']) ?></span><?php endif; ?>
            <?php if ($task['expected_outcome'] !== null): ?><span class="tag"><?= e($task['expected_outcome']) ?></span><?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<!-- 5–6: sales and build actions -->
<section class="row row--wide">

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Sales Actions</span>
      <?= chip_raw('calculated', 'Sales Director', 'Derived from the pipeline') ?>
    </div>
    <div class="actions">
      <?php foreach ($data['sales']['rows'] as $action): ?>
        <div class="actionrow">
          <span class="actionrow__dot"></span>
          <span class="actionrow__text"><?= e($action['text']) ?></span>
          <span class="actionrow__meta"><?= e($action['meta']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <span class="t-note"><?= e($data['sales']['note']) ?></span>
    <button class="btn card__foot" type="button" data-quick-add="prospect">+ Add prospect</button>
  </div>

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Build Actions</span>
      <?= chip_raw('calculated', 'CTO', 'Revenue-producing work first; the rest is deferred while cash is short') ?>
    </div>
    <div class="actions">
      <?php foreach ($data['build']['rows'] as $action): ?>
        <div class="actionrow<?= $action['deferred'] ? ' actionrow--quiet' : '' ?>">
          <span class="actionrow__dot"></span>
          <span class="actionrow__text"><?= e($action['text']) ?></span>
          <span class="actionrow__meta"><?= e($action['meta']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <span class="t-note"><?= e($data['build']['note']) ?></span>
    <button class="btn card__foot" type="button" data-quick-add="task">+ Add task</button>
  </div>
</section>

<!-- 7: risks -->
<section class="card" id="alerts">
  <div class="card__head">
    <span class="t-card-title">Risks</span>
    <?= chip_raw('calculated', 'Risk Director', 'Rules evaluated against your recorded position') ?>
  </div>

  <?php if ($data['risks'] === []): ?>
    <div class="empty">Nothing is flagged right now.</div>
  <?php else: ?>
    <div class="stack--tight">
      <?php foreach ($data['risks'] as $risk): ?>
        <div class="alert alert--<?= e(strtolower($risk['severity'])) ?>">
          <div class="alert__head">
            <span class="alert__dot"></span>
            <span class="alert__title"><?= e($risk['title']) ?></span>
            <span class="alert__sev"><?= e($risk['severity']) ?></span>
          </div>
          <div class="alert__body"><?= e($risk['body']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<!-- End-of-day review -->
<section class="card">
  <div class="card__head">
    <span class="t-card-title">End-of-Day Review</span>
    <span class="t-note">
      <?= $review['closed'] ? 'Closed ' . e(substr((string) $review['closed_at'], 11, 5)) : 'Submit before midnight' ?>
    </span>
  </div>

  <form class="stack" data-review-form>
    <div class="reviewgrid">
      <?php foreach ($review['fields'] as $field): ?>
        <label class="field">
          <span class="field__label"><?= e($field['label']) ?></span>
          <input class="field__input" type="<?= e($field['type']) ?>" name="<?= e($field['name']) ?>"
            value="<?= e($field['value']) ?>" placeholder="<?= e($field['placeholder']) ?>"
            <?= $field['type'] === 'number' ? 'step="any"' : '' ?>>
        </label>
      <?php endforeach; ?>
    </div>

    <span class="t-note">
      Money entered here is recorded in the ledger, so the review and the runway
      can never disagree. Leave a figure blank to keep what is already recorded.
    </span>

    <button class="btn btn--primary self-start" type="submit">
      <?= $review['closed'] ? 'Update review' : 'Submit review' ?>
    </button>
  </form>

  <div class="dock__answer" hidden data-review-result>
    <?= chip_raw('calculated', 'Scored', 'Recomputed from what the day actually recorded') ?>
    <div data-review-body class="dock__answer-body"></div>
    <button class="alert__dismiss" type="button" data-close-review aria-label="Close">×</button>
  </div>
</section>
