<?php

declare(strict_types=1);

/**
 * Projects → Project Portfolio.
 *
 * @var array<string,mixed> $data From PortfolioService::build()
 */

use Germa\Support\Html;
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Projects</span>
    <h1 class="t-display pagehead__h1">Project Portfolio</h1>
  </div>

  <div class="segmented" role="group" aria-label="Reporting period">
    <?php foreach ($data['periods'] as $option): ?>
      <a class="segmented__option"
        href="<?= e(Html::url('portfolio')) ?>?period=<?= e($option['key']) ?>"
        aria-pressed="<?= $option['active'] ? 'true' : 'false' ?>"><?= e($option['label']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'CFO', 'Derived from logged hours and receipts attributed to each project') ?>
  <p class="insight__text"><?= e($data['insight']['text']) ?></p>
</div>

<?php if ($data['do_not'] !== null): ?>
  <section class="card card--dark">
    <div class="card__head">
      <span class="t-card-title">Do Not Do</span>
      <?= chip_raw('on-dark', 'Board directive', 'Derived from which projects have produced money and which have not') ?>
    </div>
    <p class="board__quote"><?= e($data['do_not']) ?></p>
  </section>
<?php endif; ?>

<section class="card stack--loose">
  <div class="card__head">
    <span class="t-card-title">Portfolio Allocation</span>
    <span class="t-note">By classification · <?= e($data['range_label']) ?></span>
  </div>

  <?php if ($data['allocation']['total_hours'] === 0.0): ?>
    <div class="empty">
      No hours logged <?= e($data['phrase']) ?>.<br>
      Allocation is measured from the clock, not from intention: log time against
      a project and the split appears here.
    </div>
  <?php else: ?>
    <div class="statgrid">
      <?php foreach ($data['allocation']['rows'] as $class): ?>
        <div class="statcard<?= $class['is_lead'] ? ' statcard--lead' : '' ?>">
          <span class="t-note"><?= e($class['label']) ?></span>
          <span class="t-figure t-figure--md"><?= e(pct_label($class['pct'])) ?></span>
          <div class="bar">
            <div class="bar__fill<?= $class['is_lead'] ? ' bar__fill--coral' : ' bar__fill--quiet' ?>"
              style="width:<?= e(bar($class['pct'])) ?>"></div>
          </div>
          <span class="statcard__delta"><?= e(hours_label($class['hours'])) ?> · <?= (int) $class['projects'] ?> project<?= $class['projects'] === 1 ? '' : 's' ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="cardstats">
    <div class="cardstats__item">
      <span class="cardstats__label">Hours <?= e($data['phrase']) ?></span>
      <span class="cardstats__value"><?= e(hours_label($data['allocation']['total_hours'])) ?></span>
    </div>
    <div class="cardstats__item">
      <span class="cardstats__label">Hours invested all time</span>
      <span class="cardstats__value"><?= e(hours_label($data['invested'])) ?></span>
    </div>
    <div class="cardstats__item">
      <span class="cardstats__label">Verdicts out of date</span>
      <span class="cardstats__value<?= $data['stale_count'] > 0 ? ' cardstats__value--bad' : ' cardstats__value--good' ?>">
        <?= $data['stale_count'] === 0 ? 'None' : (int) $data['stale_count'] ?>
      </span>
    </div>
  </div>
</section>

<section class="card">
  <div class="card__head">
    <span class="t-card-title">Portfolio Review</span>
    <span class="t-note">Keep · hold · pivot · kill</span>
  </div>

  <?php if ($data['review'] === []): ?>
    <div class="empty">
      Nothing to review.<br>
      A project gets a keep-or-kill date the moment you add it.
    </div>
  <?php else: ?>
    <div class="stack--tight">
      <?php foreach ($data['review'] as $project): ?>
        <div class="project">
          <div class="project__name">
            <span class="project__title"><?= e($project['name']) ?></span>
            <span class="t-note"><?= e($project['classification']) ?> · <?= e($project['hours_label']) ?></span>
          </div>

          <span class="project__revenue<?= $project['earns'] ? '' : ' project__revenue--none' ?>">
            <?= e($project['revenue_label']) ?>
          </span>

          <span class="prio prio--<?= e($project['verdict_tone']) ?>"><?= e($project['verdict']) ?></span>

          <span class="t-note project__note"><?= e($project['recommendation']) ?></span>

          <button class="btn btn--sm flex-none" type="button" data-quick-add="project-decision">Decide</button>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="breakdown__total">
    <span>Revenue shown is money received and attributed to the project, not the value of work done</span>
  </div>
</section>
