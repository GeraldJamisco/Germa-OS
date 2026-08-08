<?php

declare(strict_types=1);

/**
 * Projects → Active Projects.
 *
 * @var array<string,mixed> $data From ActiveProjectService::build()
 */

use Germa\Support\Html;
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Projects</span>
    <h1 class="t-display pagehead__h1">Active Projects</h1>
  </div>

  <div class="segmented" role="group" aria-label="Reporting period">
    <?php foreach ($data['periods'] as $option): ?>
      <a class="segmented__option"
        href="<?= e(Html::url('projects')) ?>?period=<?= e($option['key']) ?>"
        aria-pressed="<?= $option['active'] ? 'true' : 'false' ?>"><?= e($option['label']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'CTO', 'Derived from logged hours and receipts attributed to each project') ?>
  <p class="insight__text"><?= e($data['insight']['text']) ?></p>
</div>

<section class="card stack--loose">
  <div class="card__head">
    <span class="t-card-title">Status Breakdown</span>
    <span class="t-note"><?= (int) $data['total'] ?> total project<?= $data['total'] === 1 ? '' : 's' ?></span>
  </div>

  <div class="statgrid statgrid--tight">
    <?php foreach ($data['status_cards'] as $card): ?>
      <div class="statcard<?= $card['lead'] ? ' statcard--lead' : '' ?>">
        <div class="statcard__well">
          <div class="statcard__col<?= $card['lead'] ? ' statcard__col--lead' : '' ?>"
            style="height:<?= e(bar($card['pct'], 4)) ?>"></div>
        </div>
        <span class="t-figure t-figure--md"><?= (int) $card['count'] ?></span>
        <span class="t-note"><?= e($card['label']) ?></span>
        <span class="statcard__delta<?= $card['tone'] === 'quiet' ? '' : ' statcard__delta--' . e($card['tone']) ?>">
          <?= e($card['delta']) ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="cardstats">
    <?php foreach ($data['foot'] as $stat): ?>
      <div class="cardstats__item">
        <span class="cardstats__label"><?= e($stat['label']) ?></span>
        <span class="cardstats__value<?= $stat['tone'] === 'bad' ? ' cardstats__value--bad' : ($stat['tone'] === 'good' ? ' cardstats__value--good' : '') ?>">
          <?= e($stat['value']) ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="card">
  <div class="card__head">
    <span class="t-card-title">Project List</span>
    <span class="t-note">Ranked by revenue returned</span>
  </div>

  <?php if ($data['projects'] === []): ?>
    <div class="empty">
      No projects tracked.<br>
      Until the work is here, the hours it takes are invisible.
    </div>
  <?php else: ?>
    <div class="stack--tight">
      <?php foreach ($data['projects'] as $project): ?>
        <div class="project">
          <div class="project__name">
            <span class="project__title"><?= e($project['name']) ?></span>
            <span class="t-note"><?= e($project['sub']) ?></span>
            <?php if ($project['has_description']): ?>
              <span class="t-note project__desc"><?= e((string) $project['description']) ?></span>
            <?php else: ?>
              <button class="btn--link self-start" type="button" data-quick-add="describe-project">
                No description yet, the board cannot judge it
              </button>
            <?php endif; ?>
          </div>

          <span class="prio prio--<?= e($project['status_tone']) ?>"><?= e($project['status']) ?></span>
          <span class="prio prio--quiet"><?= e($project['priority']) ?></span>

          <div class="project__meter">
            <div class="bar">
              <div class="bar__fill<?= $project['earns'] ? ' bar__fill--coral' : ' bar__fill--quiet' ?>"
                style="width:<?= e(bar($project['progress'])) ?>"></div>
            </div>
            <span class="t-note project__pct"><?= e(pct_label($project['progress'])) ?></span>
          </div>

          <span class="t-note flex-none"><?= e($project['hours']) ?></span>

          <span class="project__revenue<?= $project['earns'] ? '' : ' project__revenue--none' ?>">
            <?= e($project['revenue']) ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="inline card__foot">
    <button class="btn btn--primary" type="button" data-quick-add="project">+ Add project</button>
    <button class="btn btn--quiet" type="button" data-quick-add="describe-project">Describe a project</button>
    <button class="btn btn--quiet" type="button" data-quick-add="project-hours">Log hours</button>
  </div>
</section>
