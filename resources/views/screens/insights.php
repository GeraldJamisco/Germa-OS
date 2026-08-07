<?php

declare(strict_types=1);

/**
 * Intelligence → Insights.
 *
 * Alerts are what is wrong now. Patterns are what keeps being wrong. Every
 * pattern shows the sample it was drawn from, and anything below the threshold
 * appears in "Not yet knowable" rather than being dressed up as a finding.
 *
 * @var array<string,mixed> $data From InsightService::build()
 */

$trend = $data['trend'];
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Intelligence</span>
    <h1 class="t-display pagehead__h1">Insights</h1>
  </div>
  <?= chip_raw('calculated', 'From your records', 'Every observation here is counted from what you logged, not inferred') ?>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'Analyst', 'Derived from your ledger, pipeline, time logs and daily scores') ?>
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
    <span class="t-card-title">Patterns</span>
    <span class="t-note">Established from <?= (int) $data['min_sample'] ?> observations or more</span>
  </div>

  <?php if ($data['patterns'] === []): ?>
    <div class="empty">
      Nothing established yet.<br>
      Patterns need history. Keep logging and they appear here on their own.
    </div>
  <?php else: ?>
    <div class="stack--tight">
      <?php foreach ($data['patterns'] as $pattern): ?>
        <div class="panel">
          <div class="spread">
            <span class="project__title"><?= e($pattern['title']) ?></span>
            <span class="prio prio--<?= e($pattern['tone']) ?>"><?= e($pattern['sample_note']) ?></span>
          </div>
          <p class="t-body"><?= e($pattern['body']) ?></p>
          <button class="btn btn--sm self-start" type="button"
            data-ask="Challenge this pattern in my records: <?= e($pattern['title']) ?>. What would change it?">
            Challenge it
          </button>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="row row--wide">

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Open Alerts</span>
      <span class="t-note"><?= count($data['alerts']) ?> open</span>
    </div>

    <?php if ($data['alerts'] === []): ?>
      <div class="empty">
        Nothing is crossing a line.<br>
        Alerts are raised by rules against your own thresholds, so an empty list
        here means the numbers are inside them.
      </div>
    <?php else: ?>
      <div class="stack--tight">
        <?php foreach ($data['alerts'] as $alert): ?>
          <div class="alert alert--<?= e($alert['tone']) ?>">
            <span class="alert__dot"></span>
            <div class="alert__body">
              <span class="alert__title"><?= e($alert['title']) ?></span>
              <span class="t-note"><?= e($alert['body']) ?></span>
            </div>
            <span class="chip chip--status"><?= e($alert['severity']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Not Yet Knowable</span>
      <span class="t-note"><?= count($data['pending']) ?> waiting on data</span>
    </div>

    <?php if ($data['pending'] === []): ?>
      <div class="empty">
        Every pattern this screen looks for has enough history behind it.
      </div>
    <?php else: ?>
      <p class="t-body">
        These are questions the OS is set up to answer but cannot yet. Naming them
        is more useful than guessing at them.
      </p>
      <div class="deflist deflist--ruled">
        <?php foreach ($data['pending'] as $pattern): ?>
          <div class="actionrow actionrow--quiet">
            <span class="actionrow__dot"></span>
            <span class="actionrow__text">
              <?= e($pattern['title']) ?><br>
              <span class="t-note"><?= e($pattern['body']) ?></span>
            </span>
            <span class="actionrow__meta"><?= e($pattern['sample_note']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="card">
  <div class="card__head">
    <span class="t-card-title">Daily Score</span>
    <span class="t-note">
      <?= $trend['average'] === null ? 'Not scored yet' : 'Averaging ' . (int) $trend['average'] . ' of 100' ?>
    </span>
  </div>

  <?php if (!$trend['has_data']): ?>
    <div class="empty">
      No days scored yet.<br>
      Close out a day on Today and the trend starts here.
    </div>
  <?php else: ?>
    <div class="chart chart--single"
      style="grid-template-columns:repeat(<?= count($trend['days']) ?>,minmax(0,1fr))">
      <?php foreach ($trend['days'] as $day): ?>
        <div class="chart__col<?= $day['is_now'] ? ' chart__col--now' : '' ?>">
          <div class="chart__pair">
            <div class="chart__bar" style="height:<?= e(bar($day['pct'], 2)) ?>"
              title="<?= e($day['date'] . ': ' . $day['score'] . ' of 100') ?>"></div>
          </div>
          <span class="chart__label"><?= e($day['label']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="cardstats">
      <div class="cardstats__item">
        <span class="cardstats__label">Days scored</span>
        <span class="cardstats__value"><?= count($trend['days']) ?></span>
      </div>
      <div class="cardstats__item">
        <span class="cardstats__label">Average</span>
        <span class="cardstats__value"><?= (int) $trend['average'] ?> / 100</span>
      </div>
      <div class="cardstats__item push-right">
        <span class="cardstats__label">Scored on</span>
        <span class="cardstats__value">Discipline, sales, execution, focus</span>
      </div>
    </div>
  <?php endif; ?>
</section>
