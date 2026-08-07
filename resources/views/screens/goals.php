<?php

declare(strict_types=1);

/**
 * Goals → Goals.
 *
 * The dark card is the mission ladder, because which rung you are standing on is
 * the single fact that decides whether any of the goals below it are worth this
 * week. Goals a rung above it are marked, not hidden: wanting them is fine,
 * spending this week on them is the problem.
 *
 * @var array<string,mixed> $data From GoalBoardService::build()
 */

use Germa\Support\Html;

$stage = $data['stage'];
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Goals</span>
    <h1 class="t-display pagehead__h1">Goals</h1>
  </div>

  <div class="segmented" role="group" aria-label="Filter goals by scope">
    <?php foreach ($data['scopes'] as $option): ?>
      <a class="segmented__option"
        href="<?= e(Html::url('goals')) ?>?scope=<?= e($option['key']) ?>"
        aria-pressed="<?= $option['active'] ? 'true' : 'false' ?>"><?= e($option['label']) ?><?php
          if ($option['count'] > 0): ?> · <?= (int) $option['count'] ?><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'CEO', 'Derived from your cash position, income, debt and recurring revenue') ?>
  <p class="insight__text"><?= e($data['insight']['text']) ?></p>
</div>

<!-- The ladder. The whole mission in one card, with a marker on the rung the
     numbers actually put you on. -->
<section class="card card--dark stack--loose">
  <div class="card__head">
    <span class="t-card-title">The Ladder</span>
    <?= chip_raw('on-dark', 'You are here', 'Read from the bottom up, the first test that fails is the rung you are on') ?>
  </div>

  <div class="t-note">Pressure, then stability, then everything after it</div>
  <p class="board__quote"><?= e($stage['label']) ?>. <?= e($stage['reason']) ?></p>

  <div class="board__signals">
    <?php foreach ($data['ladder'] as $rung): ?>
      <div class="signal" title="<?= e($rung['test']) ?>">
        <span class="signal__role"><?= (int) $rung['number'] ?> · <?= e($rung['label']) ?></span>
        <span class="signal__glyph" style="color:<?= $rung['state'] === 'cleared'
          ? 'var(--signal-good)'
          : ($rung['state'] === 'here' ? 'var(--coral-500)' : 'var(--ink-faint)') ?>">
          <?= $rung['state'] === 'cleared' ? '&check;' : ($rung['state'] === 'here' ? '&bull;' : '&middot;') ?>
        </span>
        <span class="signal__note">
          <?php if ($rung['state'] === 'cleared'): ?>
            Cleared
          <?php elseif ($rung['state'] === 'here'): ?>
            <?= (int) $rung['goals'] ?> goal<?= $rung['goals'] === 1 ? '' : 's' ?> here
          <?php else: ?>
            <?= $rung['goals'] === 0 ? 'Waiting' : (int) $rung['goals'] . ' waiting' ?>
          <?php endif; ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
</section>

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
    <span class="t-card-title"><?= $data['scope'] === 'all' ? 'Every Goal' : ucfirst($data['scope']) . ' Goals' ?></span>
    <span class="t-note"><?= count($data['goals']) ?> shown, ranked by horizon</span>
  </div>

  <?php if ($data['goals'] === []): ?>
    <div class="empty">
      No goals in this scope.<br>
      Start with the one that clears the rung you are standing on. Anything higher
      is a plan for a position you are not in yet.
    </div>
  <?php else: ?>
    <div class="stack--tight">
      <?php foreach ($data['goals'] as $goal): ?>
        <div class="panel">
          <div class="spread">
            <span class="project__title"><?= e($goal['title']) ?></span>
            <div class="inline">
              <?php if ($goal['premature']): ?>
                <span class="prio prio--warn">RUNG <?= (int) $goal['stage'] ?></span>
              <?php endif; ?>
              <span class="prio prio--quiet"><?= e($goal['horizon_label']) ?></span>
              <?php if ($goal['scope'] !== 'business'): ?>
                <span class="prio prio--high"><?= e(strtoupper($goal['scope'])) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($goal['why'] !== null): ?>
            <span class="t-note"><?= e((string) $goal['why']) ?></span>
          <?php endif; ?>

          <div class="meter">
            <div class="meter__head">
              <span class="t-note"><?= e($goal['detail'] ?? 'No target set') ?></span>
              <span class="meter__value"><?= e($goal['progress_label']) ?></span>
            </div>
            <div class="bar">
              <div class="bar__fill<?= $goal['premature'] ? ' bar__fill--quiet' : ' bar__fill--coral' ?>"
                style="width:<?= e(bar($goal['progress_pct'])) ?>"></div>
            </div>
          </div>

          <?php if ($goal['next_step'] !== null): ?>
            <div class="actionrow">
              <span class="actionrow__dot"></span>
              <span class="actionrow__text">
                Next: <?= e($goal['next_step']['title']) ?>
                <?php if ($goal['next_step']['metric'] !== null): ?>
                  <br><span class="t-note">Done when: <?= e((string) $goal['next_step']['metric']) ?></span>
                <?php endif; ?>
              </span>
              <span class="actionrow__meta"><?= e($goal['next_step']['when']) ?></span>
            </div>
          <?php else: ?>
            <div class="actionrow actionrow--quiet">
              <span class="actionrow__dot"></span>
              <span class="actionrow__text">
                No milestone, so no next step. This is a wish with a progress bar
                until it is broken down.
              </span>
              <span class="actionrow__meta">Nothing scheduled</span>
            </div>
          <?php endif; ?>

          <div class="inline">
            <button class="btn btn--sm" type="button" data-quick-add="goal-milestone">+ Milestone</button>
            <button class="btn btn--sm" type="button" data-ask="<?= e($goal['ask']) ?>">Ask the board</button>
            <span class="t-note">
              <?= e($goal['deadline_label']) ?><?php
                if ($goal['milestones'] > 0): ?> · <?= (int) $goal['milestones_done'] ?> of <?= (int) $goal['milestones'] ?> milestones done<?php endif; ?>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="breakdown__total">
    <span>A goal on a rung above the one you are standing on is not ambition, it is sequence</span>
  </div>

  <button class="btn btn--primary card__foot" type="button" data-quick-add="goal">+ Add a goal</button>
</section>
