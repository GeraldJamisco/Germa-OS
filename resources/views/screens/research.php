<?php

declare(strict_types=1);

/**
 * Intelligence → Research.
 *
 * The spec asks for two things: label every claim FACT, ESTIMATE or ASSUMPTION,
 * and do not assume old information is still accurate. So the screen leads with
 * what has gone out of date, then groups the rest by how it is known. A guess and
 * a verified price never look the same here.
 *
 * @var array<string,mixed> $data From ResearchService::build()
 */
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Intelligence</span>
    <h1 class="t-display pagehead__h1">Research</h1>
  </div>
  <?= chip_raw('estimated', 'Fact · Estimate · Assumption', 'Every note carries how it is known and when it was last checked') ?>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'Research Director', 'Counted from your own notes and their check dates') ?>
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

<?php if ($data['recheck'] !== []): ?>
  <section class="card">
    <div class="card__head">
      <span class="t-card-title">Needs Re-checking</span>
      <?= chip_raw('unavailable', 'Shelf life reached', 'Old information does not announce itself, so it is listed here instead') ?>
    </div>

    <div class="stack--tight">
      <?php foreach ($data['recheck'] as $note): ?>
        <div class="duerow duerow--<?= $note['state'] === 'expired' ? 'critical' : 'high' ?>">
          <span class="duerow__dot"></span>
          <span class="duerow__title"><?= e($note['question']) ?></span>
          <span class="t-note flex-none"><?= e($note['checked_label']) ?></span>
          <span class="duerow__when"><?= e($note['state_label']) ?></span>
          <span class="prio prio--<?= e($note['tone']) ?>"><?= e($note['kind_label']) ?></span>
          <button class="btn btn--sm flex-none" type="button" data-ask="<?= e($note['ask']) ?>">Re-check</button>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="breakdown__total">
      <span>A note past its shelf life is not wrong, it is unverified. Treat it as an assumption until you look again</span>
    </div>
  </section>
<?php endif; ?>

<?php foreach ($data['groups'] as $group): ?>
  <?php if ($group['count'] === 0) { continue; } ?>
  <section class="card">
    <div class="card__head">
      <div class="inline">
        <span class="prio prio--<?= $group['kind'] === 'fact' ? 'good' : ($group['kind'] === 'estimate' ? 'warn' : 'quiet') ?>">
          <?= e($group['label']) ?>
        </span>
        <span class="t-note"><?= e($group['sub']) ?></span>
      </div>
      <span class="t-note">
        <?= (int) $group['count'] ?><?= $group['stale'] > 0 ? ' · ' . (int) $group['stale'] . ' out of date' : '' ?>
      </span>
    </div>

    <div class="stack--tight">
      <?php foreach ($group['rows'] as $note): ?>
        <div class="panel">
          <div class="spread">
            <span class="project__title"><?= e($note['question']) ?></span>
            <span class="t-label<?= $note['state'] === 'current' ? '' : ' notif__cue' ?>">
              <?= e($note['state_label']) ?>
            </span>
          </div>

          <p class="t-body"><?= e($note['finding']) ?></p>

          <div class="deflist">
            <div class="defrow">
              <span class="defrow__key">How it is known</span>
              <span class="defrow__value"><?= e($note['source'] ?? 'No source recorded') ?></span>
            </div>
            <?php if ($note['applies_to'] !== null): ?>
              <div class="defrow">
                <span class="defrow__key">Bears on</span>
                <span class="defrow__value"><?= e((string) $note['applies_to']) ?></span>
              </div>
            <?php endif; ?>
            <div class="defrow">
              <span class="defrow__key">Last checked</span>
              <span class="defrow__value"><?= e($note['checked_label']) ?></span>
            </div>
          </div>

          <button class="btn btn--sm self-start" type="button" data-ask="<?= e($note['ask']) ?>">Re-check</button>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>

<section class="row row--wide">

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Asked but Not Recorded</span>
      <span class="t-note"><?= count($data['questions']) ?> found</span>
    </div>

    <?php if ($data['questions'] === []): ?>
      <div class="empty">
        Nothing outstanding.<br>
        Research questions you put to the board show up here until the answer is
        written down as a note.
      </div>
    <?php else: ?>
      <p class="t-body">
        You asked these and never recorded the answer. An answer you cannot find
        again is an answer you will pay for twice.
      </p>
      <div class="deflist deflist--ruled">
        <?php foreach ($data['questions'] as $question): ?>
          <button class="actionrow actionrow--button" type="button" data-ask="<?= e($question['ask']) ?>">
            <span class="actionrow__dot"></span>
            <span class="actionrow__text"><?= e($question['prompt']) ?></span>
            <span class="actionrow__meta"><?= e($question['when']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Record What You Learn</span>
      <span class="t-note"><?= (int) $data['total'] ?> on record</span>
    </div>

    <p class="t-body">
      Three labels, and the difference matters. A <strong>fact</strong> has a source
      you could show someone. An <strong>estimate</strong> is reasoned from something
      real. An <strong>assumption</strong> is what you believe without having looked,
      which is fine as long as it is labelled and expires soon.
    </p>

    <div class="deflist deflist--ruled">
      <div class="defrow">
        <span class="defrow__key">Fact</span>
        <span class="defrow__value">Re-check after 180 days</span>
      </div>
      <div class="defrow">
        <span class="defrow__key">Estimate</span>
        <span class="defrow__value">Re-check after 90 days</span>
      </div>
      <div class="defrow">
        <span class="defrow__key">Assumption</span>
        <span class="defrow__value">Re-check after 45 days</span>
      </div>
    </div>

    <button class="btn btn--primary card__foot" type="button" data-quick-add="research">+ Record a finding</button>
  </div>
</section>
