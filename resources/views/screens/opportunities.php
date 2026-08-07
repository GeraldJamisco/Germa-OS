<?php

declare(strict_types=1);

/**
 * Business → Opportunities: the spec's OPPORTUNITY MODE.
 *
 * @var array<string,mixed> $data From OpportunityBoardService::build()
 */

use Germa\Support\Html;
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">Business</span>
    <h1 class="t-display pagehead__h1">Opportunities</h1>
  </div>
  <?= chip_raw('calculated', 'Opportunity mode', 'DO / CONSIDER / IGNORE, ranked by expected value against time-to-cash and effort') ?>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'Research Director', 'Ranked arithmetically from what you recorded') ?>
  <p class="insight__text"><?= e($data['insight']['text']) ?></p>
</div>

<?php foreach ($data['groups'] as $group): ?>
  <section class="card">
    <div class="card__head">
      <div class="inline">
        <span class="prio prio--<?= e($group['tone']) ?>"><?= e($group['label']) ?></span>
        <span class="t-note"><?= e($group['sub']) ?></span>
      </div>
      <span class="t-note">
        <?= (int) $group['count'] ?><?= $group['value']->isZero() ? '' : ' · ' . e($group['value']->compact()) ?>
      </span>
    </div>

    <?php if ($group['items'] === []): ?>
      <div class="empty">
        <?php if ($group['verdict'] === 'DO'): ?>
          Nothing currently clears the bar for immediate action.<br>
          That is a finding, not a gap. The pipeline is where this week's money comes from.
        <?php elseif ($group['verdict'] === 'IGNORE'): ?>
          Nothing has been ruled out.
        <?php else: ?>
          Nothing in this class.
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="opps">
        <?php foreach ($group['items'] as $opportunity): ?>
          <div class="opp">
            <div class="opp__head">
              <span class="opp__rank"><?= e($opportunity['rank']) ?></span>
              <span class="opp__title"><?= e($opportunity['title']) ?></span>
            </div>

            <?php if (!empty($opportunity['summary'])): ?>
              <span class="t-note"><?= e($opportunity['summary']) ?></span>
            <?php endif; ?>

            <div class="opp__facts">
              <?php foreach ($opportunity['facts'] as $fact): ?>
                <div class="opp__fact">
                  <span class="opp__fact-k"><?= e($fact['k']) ?></span>
                  <span class="opp__fact-v"><?= e($fact['v']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="inline">
              <button class="btn btn--sm" type="button"
                data-ask="Give me the decision framework on the opportunity &quot;<?= e($opportunity['title']) ?>&quot;.">
                Analyse
              </button>
              <span class="t-note">Expected <?= e($opportunity['expected_value']->compact()) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
<?php endforeach; ?>

<section class="card">
  <div class="card__head">
    <span class="t-card-title">Spotted something?</span>
    <span class="t-note"><?= (int) $data['total'] ?> tracked</span>
  </div>
  <p class="t-body">
    A rough estimate ranks better than an unrecorded hunch. Value, effort and a
    guess at probability are enough for the ranking to place it.
  </p>
  <button class="btn btn--primary card__foot" type="button" data-quick-add="opportunity">+ Add opportunity</button>
</section>
