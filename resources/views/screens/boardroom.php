<?php

declare(strict_types=1);

/**
 * Intelligence → AI Boardroom.
 *
 * The dashboard shows the board's one answer. This screen shows the argument
 * behind it: who objected, what was asked, and which decisions are now due for
 * review. The dark card is the table itself, the advisors sit around it, which
 * is why the roster lives inside that card rather than beside it.
 *
 * @var array<string,mixed> $data From BoardroomService::build()
 */

use Germa\Support\Html;

$position = $data['position'];
$roster   = $data['roster'];
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Intelligence</span>
    <h1 class="t-display pagehead__h1">AI Boardroom</h1>
  </div>
  <?= chip_raw(
    $data['mode'] === 'normal' ? 'stable' : 'critical',
    $data['mode_label'],
    $data['mode_reason'] ?? 'The operating mode the board is advising under'
  ) ?>
</div>

<!-- Who is actually talking. On this screen that outranks every other figure. -->
<div class="insight">
  <?= chip_raw($data['speaker']['provenance'], $data['speaker']['label'], 'Which engine produced the position below') ?>
  <p class="insight__text"><?= e($data['speaker']['detail']) ?></p>
</div>

<!-- ── The table ─────────────────────────────────────────────────────────── -->
<section class="card card--dark stack--loose">
  <div class="card__head">
    <span class="t-card-title">The Board's Position</span>
    <span class="chip chip--on-dark">One answer, not ten</span>
  </div>

  <div class="t-note">Asked and answered for today</div>
  <p class="board__quote"><?= e($position['recommendation']) ?></p>

  <?php if ($position['rationale'] !== null): ?>
    <div class="t-note"><?= e($position['rationale']) ?></div>
  <?php endif; ?>

  <?php if ($roster['concerned'] !== []): ?>
    <div class="stack--tight">
      <span class="signal__role">Raising a concern · <?= count($roster['concerned']) ?></span>
      <div class="board__signals">
        <?php foreach ($roster['concerned'] as $advisor): ?>
          <button class="signal" type="button"
            data-ask="<?= e($advisor['ask']) ?>"
            title="<?= e(($advisor['note'] ?? '') . ': ask this advisor directly') ?>">
            <span class="signal__role"><?= e($advisor['role']) ?></span>
            <span class="signal__glyph" style="color:<?= e($advisor['colour']) ?>"><?= e($advisor['glyph']) ?></span>
            <?php if (!empty($advisor['note'])): ?>
              <span class="signal__note"><?= e($advisor['note']) ?></span>
            <?php endif; ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($roster['clear'] !== []): ?>
    <div class="stack--tight">
      <span class="signal__role">No objection · <?= count($roster['clear']) ?></span>
      <div class="board__signals">
        <?php foreach ($roster['clear'] as $advisor): ?>
          <button class="signal" type="button"
            data-ask="<?= e($advisor['ask']) ?>"
            title="<?= e(($advisor['note'] ?? '') . ': ask this advisor directly') ?>">
            <span class="signal__role"><?= e($advisor['role']) ?></span>
            <span class="signal__glyph" style="color:<?= e($advisor['colour']) ?>"><?= e($advisor['glyph']) ?></span>
            <?php if (!empty($advisor['note'])): ?>
              <span class="signal__note"><?= e($advisor['note']) ?></span>
            <?php endif; ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="inline">
    <button class="btn btn--primary" type="button" data-ai-refresh>Reconvene the board</button>
    <span class="t-note">
      <?= e($data['roster_note']) ?>
      <?= $position['model'] !== null ? ' · ' . e((string) $position['model']) : '' ?>
    </span>
  </div>
</section>

<!-- ── Put it to the board ───────────────────────────────────────────────── -->
<section class="card">
  <div class="card__head">
    <span class="t-card-title">Put It to the Board</span>
    <span class="t-note">Every question is recorded in the minutes</span>
  </div>

  <form class="stack--tight" data-board-ask>
    <label class="field">
      <span class="field__label">What do you want a decision on?</span>
      <textarea class="field__textarea" name="question" rows="3"
        placeholder="Describe the situation. The board answers with a recommendation, the reasoning, and what to watch, not a list of options to pick from."></textarea>
    </label>

    <div class="inline">
      <button class="btn btn--primary" type="submit">Ask the board</button>
      <span class="t-note" data-board-ask-status>Answers are live, so this one takes a few seconds.</span>
    </div>
  </form>

  <div class="insight" data-board-answer hidden>
    <?= chip_raw('ai', 'The board', 'A live answer to the question you just asked') ?>
    <p class="insight__text" data-board-answer-text></p>
  </div>

  <div class="deflist deflist--ruled">
    <span class="t-note">Standing questions, in the order the decision hierarchy asks them</span>
    <?php foreach ($data['prompts'] as $prompt): ?>
      <button class="actionrow actionrow--button" type="button" data-ask="<?= e($prompt['question']) ?>">
        <span class="actionrow__dot"></span>
        <span class="actionrow__text"><?= e($prompt['question']) ?></span>
        <span class="actionrow__meta"><?= e($prompt['label']) ?></span>
      </button>
    <?php endforeach; ?>
  </div>
</section>

<section class="row row--wide">

  <!-- ── Minutes ─────────────────────────────────────────────────────────── -->
  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Minutes</span>
      <span class="t-note"><?= count($data['minutes']) ?> most recent</span>
    </div>

    <?php if ($data['minutes'] === []): ?>
      <div class="empty">
        Nothing asked yet.<br>
        Every question you put to the board is kept here with who answered it and
        how long they took.
      </div>
    <?php else: ?>
      <div class="minutes">
        <?php foreach ($data['minutes'] as $minute): ?>
          <div class="minute">
            <div class="spread">
              <span class="minute__q"><?= e($minute['prompt']) ?></span>
              <span class="t-note flex-none"><?= e($minute['when']) ?></span>
            </div>

            <?php if ($minute['error'] !== null): ?>
              <p class="minute__a">Failed: <?= e($minute['error']) ?></p>
            <?php else: ?>
              <p class="minute__a"><?= e((string) $minute['answer']) ?></p>
            <?php endif; ?>

            <div class="inline">
              <?= chip_raw(
                $minute['provenance'],
                $minute['driver'] === 'claude' ? 'AI' : 'Rules',
                $minute['model'] !== null ? (string) $minute['model'] : 'Answered without a model call'
              ) ?>
              <?php if ($minute['timing'] !== null): ?>
                <span class="t-note"><?= e($minute['timing']) ?></span>
              <?php endif; ?>
              <?php if ($minute['mode'] !== 'normal'): ?>
                <span class="prio prio--warn"><?= e(strtoupper($minute['mode'])) ?></span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ── Decisions due review ────────────────────────────────────────────── -->
  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Due for Review</span>
      <a href="<?= e(Html::url('decisions')) ?>" class="btn--link">All decisions →</a>
    </div>

    <?php if ($data['reviews'] === []): ?>
      <div class="empty">
        No decision is overdue a look back.<br>
        A decision never reviewed is indistinguishable from a guess, so this is
        the list to keep empty.
      </div>
    <?php else: ?>
      <div class="stack--tight">
        <?php foreach ($data['reviews'] as $review): ?>
          <div class="duerow duerow--<?= e($review['row_tone']) ?>"
            title="<?= e($review['metric'] !== null ? 'Watching: ' . $review['metric'] : 'No metric recorded for this decision') ?>">
            <span class="duerow__dot"></span>
            <span class="duerow__title"><?= e($review['title']) ?></span>
            <span class="duerow__when"><?= e($review['due']) ?></span>
            <span class="prio prio--<?= e($review['pill_tone']) ?>">REVIEW</span>
            <button class="btn btn--sm flex-none" type="button" data-ask="<?= e($review['ask']) ?>">Ask</button>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
