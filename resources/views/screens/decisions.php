<?php

declare(strict_types=1);

/**
 * Intelligence → Decisions.
 *
 * Built around the two fields the spec's DECISION FRAMEWORK ends with and most
 * decision logs leave out: the next action, and the metric that will show
 * whether it worked. A decision with neither cannot be graded, so the screen
 * counts those and says so rather than letting the list look healthy.
 *
 * @var array<string,mixed> $data From DecisionService::build()
 */

use Germa\Support\Html;
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Intelligence</span>
    <h1 class="t-display pagehead__h1">Decisions</h1>
  </div>

  <div class="segmented" role="group" aria-label="Filter decisions">
    <?php foreach ($data['filters'] as $option): ?>
      <a class="segmented__option"
        href="<?= e(Html::url('decisions')) ?>?filter=<?= e($option['key']) ?>"
        aria-pressed="<?= $option['active'] ? 'true' : 'false' ?>"><?= e($option['label']) ?><?php
          if ($option['count'] !== null && $option['count'] > 0): ?> · <?= (int) $option['count'] ?><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'Chief of Staff', 'Counted from your own decision record') ?>
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

<?php if ($data['unfalsifiable'] !== []): ?>
  <section class="card card--dark">
    <div class="card__head">
      <span class="t-card-title">Cannot be graded</span>
      <?= chip_raw('on-dark', 'Accountability', 'Live decisions with no metric to watch or no date to look again') ?>
    </div>
    <p class="board__quote">
      <?= count($data['unfalsifiable']) === 1 ? 'One live decision has' : count($data['unfalsifiable']) . ' live decisions have' ?>
      no metric or no review date. Without both, there is no future moment at which
      <?= count($data['unfalsifiable']) === 1 ? 'it' : 'they' ?> can be shown to have been wrong.
    </p>
    <div class="stack--tight">
      <?php foreach ($data['unfalsifiable'] as $decision): ?>
        <div class="signal">
          <span class="signal__role"><?= e($decision['has_metric'] ? 'No review date' : 'No metric') ?></span>
          <span class="signal__note"><?= e($decision['title']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($data['open'] !== []): ?>
  <section class="card">
    <div class="card__head">
      <span class="t-card-title">Awaiting Your Call</span>
      <span class="t-note"><?= count($data['open']) ?> open</span>
    </div>

    <?php foreach ($data['open'] as $decision): ?>
      <div class="panel">
        <div class="spread">
          <span class="project__title"><?= e($decision['title']) ?></span>
          <span class="prio prio--<?= e($decision['status_tone']) ?>"><?= e($decision['status_label']) ?></span>
        </div>

        <?php if ($decision['situation'] !== null): ?>
          <p class="t-body"><?= e((string) $decision['situation']) ?></p>
        <?php endif; ?>

        <?php if ($decision['options'] !== []): ?>
          <div class="deflist">
            <span class="t-note">Options on the table</span>
            <?php foreach ($decision['options'] as $option): ?>
              <div class="actionrow actionrow--quiet">
                <span class="actionrow__dot"></span>
                <span class="actionrow__text"><?= e($option['label']) ?></span>
                <?php if ($option['note'] !== null): ?>
                  <span class="actionrow__meta"><?= e((string) $option['note']) ?></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($decision['recommendation'] !== null): ?>
          <div class="insight">
            <?= chip_raw('calculated', 'Recommendation', 'What was recommended when this was logged') ?>
            <p class="insight__text"><?= e((string) $decision['recommendation']) ?></p>
          </div>
        <?php endif; ?>

        <div class="inline">
          <button class="btn btn--primary btn--sm" type="button" data-quick-add="decision-review">Record the call</button>
          <button class="btn btn--sm" type="button" data-ask="<?= e($decision['ask']) ?>">Ask the board</button>
          <span class="t-note"><?= e($decision['age_label']) ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<section class="card">
  <div class="card__head">
    <span class="t-card-title"><?= e($data['filters'][array_search($data['filter'], array_column($data['filters'], 'key'), true)]['label'] ?? 'Decisions') ?></span>
    <span class="t-note"><?= count($data['rows']) ?> shown</span>
  </div>

  <?php if ($data['rows'] === []): ?>
    <div class="empty">
      <?php if ($data['filter'] === 'closed'): ?>
        Nothing closed yet.<br>
        A decision closes when you write down what actually happened.
      <?php elseif ($data['filter'] === 'open'): ?>
        Nothing waiting on you. That is the position to hold.
      <?php else: ?>
        No live decisions.<br>
        Log the next real one with a metric and a review date, and it becomes checkable.
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="stack--tight">
      <?php foreach ($data['rows'] as $decision): ?>
        <div class="duerow<?= $decision['review_due'] ? ' duerow--critical' : ($decision['unfalsifiable'] ? ' duerow--high' : '') ?>">
          <span class="duerow__dot"></span>
          <span class="duerow__title"><?= e($decision['title']) ?></span>

          <span class="t-note flex-none">
            <?php if ($decision['has_metric']): ?>
              Watching: <?= e((string) $decision['metric']) ?>
            <?php else: ?>
              No metric recorded
            <?php endif; ?>
          </span>

          <span class="duerow__when"><?= e($decision['review_label']) ?></span>
          <span class="prio prio--<?= e($decision['status_tone']) ?>"><?= e($decision['status_label']) ?></span>

          <?php if ($decision['status'] === 'decided'): ?>
            <button class="btn btn--sm flex-none" type="button" data-quick-add="decision-review">Review</button>
          <?php else: ?>
            <button class="btn btn--sm flex-none" type="button" data-ask="<?= e($decision['ask']) ?>">Ask</button>
          <?php endif; ?>
        </div>

        <?php if ($decision['outcome'] !== null): ?>
          <div class="minute">
            <span class="minute__q">What happened</span>
            <p class="minute__a"><?= e((string) $decision['outcome']) ?></p>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="breakdown__total">
    <span>A decision is checkable only when it carries both a metric and a review date</span>
  </div>

  <button class="btn btn--primary card__foot" type="button" data-quick-add="decision">+ Log a decision</button>
</section>
