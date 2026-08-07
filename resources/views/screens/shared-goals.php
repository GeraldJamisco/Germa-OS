<?php

declare(strict_types=1);

/**
 * Household → Shared Goals.
 *
 * The distinction this screen refuses to blur: a goal money is actually moving
 * toward, and a goal that shows healthy progress it made a long time ago. The
 * second one is a conversation somebody is still expecting to have gone
 * somewhere, and it should not look the same as the first.
 *
 * @var array<string,mixed> $data From SharedGoalService::build()
 */
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Household</span>
    <h1 class="t-display pagehead__h1">Shared Goals</h1>
  </div>
  <?= chip_raw('entered', 'Someone is counting on these', 'A shared goal that slips quietly costs more than a business one that slips loudly') ?>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'Household Director', 'Read from goal progress and the dates money actually moved') ?>
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

<?php if ($data['unfunded'] !== []): ?>
  <section class="card card--dark">
    <div class="card__head">
      <span class="t-card-title">Promised, Not Funded</span>
      <?= chip_raw('on-dark', 'Say it early', 'No deposit in over six weeks, or none ever') ?>
    </div>
    <p class="board__quote">
      <?= count($data['unfunded']) === 1
        ? 'One shared goal has no money moving toward it.'
        : count($data['unfunded']) . ' shared goals have no money moving toward them.' ?>
      Progress that stopped a while ago still displays as progress, which is how a
      delay becomes a surprise instead of a conversation.
    </p>
    <div class="board__signals">
      <?php foreach ($data['unfunded'] as $goal): ?>
        <div class="signal">
          <span class="signal__role"><?= e($goal['progress_label']) ?> reached</span>
          <span class="signal__note"><?= e($goal['name']) ?></span>
          <span class="signal__note"><?= e($goal['since_label']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<section class="card">
  <div class="card__head">
    <span class="t-card-title">Money Set Aside</span>
    <span class="t-note"><?= count($data['savings']) ?> goal<?= count($data['savings']) === 1 ? '' : 's' ?></span>
  </div>

  <?php if ($data['savings'] === []): ?>
    <div class="empty">
      No savings goals yet.<br>
      A reserve is the household's version of runway. It is also the only part of
      a shared plan that can be checked without asking anyone how it feels.
    </div>
  <?php else: ?>
    <div class="stack--tight">
      <?php foreach ($data['savings'] as $goal): ?>
        <div class="panel">
          <div class="spread">
            <span class="project__title"><?= e($goal['name']) ?></span>
            <span class="prio prio--<?= e($goal['tone']) ?>">
              <?= $goal['funded'] ? 'FUNDED' : ($goal['last_in'] === null ? 'NEVER FUNDED' : 'STALLED') ?>
            </span>
          </div>

          <?php if ($goal['note'] !== null): ?>
            <span class="t-note"><?= e((string) $goal['note']) ?></span>
          <?php endif; ?>

          <div class="meter">
            <div class="meter__head">
              <span class="t-note"><?= e($goal['detail']) ?></span>
              <span class="meter__value"><?= e($goal['progress_label']) ?></span>
            </div>
            <div class="bar">
              <div class="bar__fill<?= $goal['funded'] ? ' bar__fill--coral' : ' bar__fill--quiet' ?>"
                style="width:<?= e(bar($goal['progress_pct'])) ?>"></div>
            </div>
          </div>

          <div class="inline">
            <span class="t-note"><?= e($goal['since_label']) ?></span>
            <?php if (!$goal['funded']): ?>
              <button class="btn btn--sm" type="button" data-ask="<?= e($goal['ask']) ?>">What would fund it?</button>
            <?php endif; ?>
            <button class="btn btn--sm" type="button" data-quick-add="savings">Move money</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="breakdown__total">
    <span>Funded means money moved in the last six weeks, not that the total looks healthy</span>
  </div>
</section>

<section class="card">
  <div class="card__head">
    <span class="t-card-title">Agreed With the Household</span>
    <span class="t-note"><?= count($data['goals']) ?> goal<?= count($data['goals']) === 1 ? '' : 's' ?></span>
  </div>

  <?php if ($data['goals'] === []): ?>
    <div class="empty">
      Nothing recorded as shared.<br>
      A goal the household is counting on belongs somewhere you both see the same
      version of it, rather than in a conversation you each remember differently.
    </div>
  <?php else: ?>
    <div class="stack--tight">
      <?php foreach ($data['goals'] as $goal): ?>
        <div class="panel">
          <div class="spread">
            <span class="project__title"><?= e($goal['title']) ?></span>
            <div class="inline">
              <?php if ($goal['overdue']): ?>
                <span class="prio prio--high">PAST THE DATE</span>
              <?php endif; ?>
              <span class="prio prio--quiet"><?= e($goal['scope_label']) ?></span>
            </div>
          </div>

          <?php if ($goal['why'] !== null): ?>
            <span class="t-note"><?= e((string) $goal['why']) ?></span>
          <?php endif; ?>

          <div class="meter">
            <div class="meter__head">
              <span class="t-note"><?= e($goal['detail'] ?? 'No amount agreed') ?></span>
              <span class="meter__value"><?= e($goal['progress_label']) ?></span>
            </div>
            <div class="bar">
              <div class="bar__fill bar__fill--coral" style="width:<?= e(bar($goal['progress_pct'])) ?>"></div>
            </div>
          </div>

          <?php if ($goal['next_step'] !== null): ?>
            <div class="actionrow">
              <span class="actionrow__dot"></span>
              <span class="actionrow__text">Next: <?= e((string) $goal['next_step']) ?></span>
              <span class="actionrow__meta"><?= e($goal['when']) ?></span>
            </div>
          <?php else: ?>
            <div class="actionrow actionrow--quiet">
              <span class="actionrow__dot"></span>
              <span class="actionrow__text">
                No next step written down. That makes an honest progress update hard
                to give, which is usually why it does not get given.
              </span>
              <span class="actionrow__meta"><?= e($goal['when']) ?></span>
            </div>
          <?php endif; ?>

          <div class="inline">
            <button class="btn btn--sm" type="button" data-quick-add="goal-milestone">+ Milestone</button>
            <button class="btn btn--sm" type="button" data-ask="<?= e($goal['ask']) ?>">Ask the board</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <button class="btn btn--primary card__foot" type="button" data-quick-add="goal">+ Add a shared goal</button>
</section>
