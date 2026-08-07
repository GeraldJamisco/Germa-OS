<?php

declare(strict_types=1);

/**
 * System → Reports.
 *
 * Trust comes first, in the dark card, above the reports themselves. That
 * ordering is the argument of the whole screen: a report read off stale inputs is
 * worse than no report, because it carries the same confidence and none of the
 * accuracy. Everything below it is only worth reading if the card above says so.
 *
 * @var array<string,mixed> $data From ReportService::build()
 */

use Germa\Support\Html;

$trust = $data['trust'];
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">System</span>
    <h1 class="t-display pagehead__h1">Reports</h1>
  </div>

  <div class="segmented" role="group" aria-label="Reporting cycle">
    <?php foreach ($data['periods'] as $option): ?>
      <a class="segmented__option"
        href="<?= e(Html::url('reports')) ?>?period=<?= e($option['key']) ?>"
        aria-pressed="<?= $option['active'] ? 'true' : 'false' ?>"><?= e($option['label']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'Chief of Staff', 'Every figure below is counted from your own records') ?>
  <p class="insight__text"><?= e($data['insight']['text']) ?></p>
</div>

<!-- ── Can you trust these numbers ──────────────────────────────────────────
     First, and in the dark card, because nothing below it means anything if the
     inputs are stale. -->
<section class="card card--dark stack--loose">
  <div class="card__head">
    <span class="t-card-title">Can You Trust These Numbers</span>
    <?= chip_raw('on-dark', $trust['tone'] === 'good' ? 'Inputs current' : 'Inputs behind',
      'A figure over stale input does not look like missing data, it looks like an answer') ?>
  </div>

  <div class="t-note">Graded on when each input was last fed, stale after <?= (int) $trust['stale_after'] ?> days</div>
  <p class="board__quote"><?= e($trust['headline']) ?></p>

  <div class="board__signals">
    <?php foreach ($trust['rows'] as $row): ?>
      <div class="signal" title="<?= e('Feeds: ' . $row['blocks']) ?>">
        <span class="signal__role"><?= e($row['label']) ?></span>
        <span class="signal__glyph" style="color:<?= $row['state'] === 'current'
          ? 'var(--signal-good)'
          : ($row['state'] === 'stale' ? 'var(--signal-warn)' : 'var(--coral-500)') ?>">
          <?= $row['state'] === 'current' ? '&check;' : ($row['state'] === 'stale' ? '&excl;' : '&times;') ?>
        </span>
        <span class="signal__note"><?= e($row['label_when']) ?></span>
        <span class="signal__note"><?= e($row['verdict']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="inline">
    <button class="btn btn--primary" type="button"
      data-ask="Which of my figures should I not trust right now, and what do I need to log to fix that?">
      What should I log first?
    </button>
    <span class="t-note">Each input feeds the screens named in its tooltip</span>
  </div>
</section>

<?php if ($data['board'] !== null): ?>
  <!-- ── Weekly board meeting ───────────────────────────────────────────── -->
  <section class="card">
    <div class="card__head">
      <span class="t-card-title">Weekly Board Meeting</span>
      <span class="t-note"><?= e($data['range_label']) ?> · nine reports</span>
    </div>

    <div class="stack--tight">
      <?php foreach ($data['board'] as $report): ?>
        <div class="panel">
          <div class="spread">
            <div class="inline">
              <span class="prio prio--<?= e($report['tone']) ?>"><?= e($report['role']) ?></span>
              <span class="t-note"><?= e($report['remit']) ?></span>
            </div>
            <span class="project__revenue"><?= e($report['headline']) ?></span>
          </div>

          <p class="t-body"><?= e($report['body']) ?></p>

          <button class="btn btn--sm self-start" type="button" data-ask="<?= e($report['ask']) ?>">
            Press <?= e($report['role']) ?>
          </button>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card card--dark">
    <div class="card__head">
      <span class="t-card-title">Next Week, Three Priorities</span>
      <?= chip_raw('on-dark', 'Only three', 'Ranked from what the record says is unresolved, not typed in on a Sunday') ?>
    </div>

    <?php if ($data['priorities'] === []): ?>
      <p class="board__quote">Nothing in the record is unresolved enough to rank. Set the three yourself.</p>
    <?php else: ?>
      <div class="stack--tight">
        <?php foreach ($data['priorities'] as $index => $priority): ?>
          <div class="opp">
            <div class="opp__head">
              <span class="opp__rank"><?= $index + 1 ?></span>
              <span class="opp__title"><?= e($priority['title']) ?></span>
            </div>
            <span class="t-note"><?= e($priority['reason']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($data['review'] !== null): ?>
  <!-- ── Monthly review ─────────────────────────────────────────────────── -->
  <section class="card stack--loose">
    <div class="card__head">
      <span class="t-card-title">Monthly Review</span>
      <span class="t-note"><?= e($data['range_label']) ?> · thirteen measures</span>
    </div>

    <div class="statgrid">
      <?php foreach ($data['review']['measures'] as $measure): ?>
        <div class="statcard<?= $measure['tone'] === 'bad' ? ' statcard--lead' : '' ?>">
          <span class="t-note"><?= e($measure['label']) ?></span>
          <span class="t-figure t-figure--sm"><?= e($measure['value']) ?></span>
          <span class="statcard__delta<?= $measure['tone'] === 'bad' ? ' statcard__delta--lead' : ($measure['tone'] === 'warn' ? ' statcard__delta--warn' : '') ?>">
            <?= e($measure['note']) ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="breakdown__total">
      <span>Each measure states the window it was taken over, because they are not all the same window</span>
    </div>
  </section>

  <section class="card">
    <div class="card__head">
      <span class="t-card-title">The Seven Verdicts</span>
      <?= chip_raw('calculated', 'Derived', 'Read from the measures above, so they cannot contradict the table') ?>
    </div>

    <div class="stack--tight">
      <?php foreach ($data['review']['sections'] as $section): ?>
        <div class="panel">
          <span class="signal__role"><?= e(strtoupper($section['label'])) ?></span>
          <p class="t-body"><?= e($section['body']) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<section class="row row--wide">

  <!-- ── Said versus did ─────────────────────────────────────────────────── -->
  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Said Versus Did</span>
      <span class="t-note">
        <?= $data['said_did']['pct'] === null
          ? 'Nothing committed'
          : (int) $data['said_did']['delivered'] . ' of ' . (int) $data['said_did']['promised'] . ' delivered' ?>
      </span>
    </div>

    <?php foreach ($data['said_did']['rows'] as $row): ?>
      <div class="meter">
        <div class="meter__head">
          <span class="t-note"><?= e($row['label']) ?></span>
          <span class="meter__value">
            <?= $row['total'] === 0 ? 'None set' : (int) $row['done'] . ' of ' . (int) $row['total'] ?>
          </span>
        </div>
        <div class="bar">
          <div class="bar__fill<?= $row['tone'] === 'good' ? '' : ' bar__fill--coral' ?>"
            style="width:<?= e(bar($row['pct'] ?? 0)) ?>"></div>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="breakdown__total">
      <span><?= e($data['said_did']['verdict']) ?></span>
    </div>
  </div>

  <!-- ── The OS, audited ─────────────────────────────────────────────────── -->
  <div class="card">
    <div class="card__head">
      <span class="t-card-title">The OS, Audited</span>
      <?= chip_raw('calculated', 'Self report', 'What this system did, and where it answered without a model') ?>
    </div>

    <div class="deflist deflist--ruled">
      <?php foreach ($data['self_audit']['rows'] as $row): ?>
        <div class="defrow">
          <span class="defrow__key" title="<?= e($row['note']) ?>"><?= e($row['label']) ?></span>
          <span class="defrow__value<?= $row['tone'] === 'warn' ? ' notif__cue' : '' ?>"><?= e($row['value']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="breakdown__total">
      <span><?= e($data['self_audit']['verdict']) ?></span>
    </div>
  </div>
</section>

<!-- ── The cost of waiting ─────────────────────────────────────────────────
     Every other screen shows what is happening. This prices what nobody
     decided. -->
<section class="card">
  <div class="card__head">
    <span class="t-card-title">The Cost of Waiting</span>
    <?= chip_raw('estimated', 'Inaction, priced', 'What has already been spent or lost without anyone deciding to spend or lose it') ?>
  </div>

  <?php if ($data['waiting']['clear']): ?>
    <div class="empty">
      Nothing is waiting on a decision.<br>
      That is rarer than it sounds. It also means every cost in this system is one
      somebody chose.
    </div>
  <?php else: ?>
    <div class="stack--tight">
      <?php foreach ($data['waiting']['rows'] as $row): ?>
        <div class="duerow duerow--<?= $row['tone'] === 'high' ? 'critical' : 'high' ?>">
          <span class="duerow__dot"></span>
          <span class="duerow__title"><?= e($row['label']) ?></span>
          <span class="t-note project__note"><?= e($row['detail']) ?></span>
          <span class="duerow__amount"><?= e($row['cost']) ?></span>
          <button class="btn btn--sm flex-none" type="button" data-ask="<?= e($row['ask']) ?>">Ask</button>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="breakdown__total">
      <span>None of these is a loss anyone chose. That is what makes them the expensive kind</span>
    </div>
  <?php endif; ?>
</section>
