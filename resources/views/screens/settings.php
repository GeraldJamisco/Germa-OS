<?php

declare(strict_types=1);

/**
 * System → Settings.
 *
 * Every row carries what it decides and what it is deciding right now, because a
 * threshold you can see acting is a threshold you can argue with. The screen also
 * names what is deliberately not configurable: someone hunting for a knob deserves
 * to be told it does not exist and why.
 *
 * @var array<string,mixed> $data From SettingsService::build()
 */

$rate = $data['rate'];
$dataState = $data['data'];
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">System</span>
    <h1 class="t-display pagehead__h1">Settings</h1>
  </div>
  <?= chip_raw('entered', 'Thresholds, not preferences', 'Each value here changes what the other screens conclude') ?>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'Chief of Staff', 'Read from the current position against each threshold you set') ?>
  <p class="insight__text"><?= e($data['insight']['text']) ?></p>
</div>

<?php if ($data['conflicts'] !== []): ?>
  <!-- Configurations that cannot do what they look like they do. -->
  <section class="card card--dark">
    <div class="card__head">
      <span class="t-card-title">These Settings Contradict Themselves</span>
      <?= chip_raw('on-dark', 'Check this', 'A setting that can never act is worse than one set badly') ?>
    </div>
    <div class="stack--tight">
      <?php foreach ($data['conflicts'] as $conflict): ?>
        <div class="donot">
          <span class="donot__label">Note</span>
          <span class="donot__text">
            <strong><?= e($conflict['title']) ?></strong> <?= e($conflict['detail']) ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php foreach ($data['groups'] as $group): ?>
  <section class="card">
    <div class="card__head">
      <span class="t-card-title"><?= e($group['label']) ?></span>
      <span class="t-note"><?= e($group['note']) ?></span>
    </div>

    <div class="stack--tight">
      <?php foreach ($group['fields'] as $field): ?>
        <div class="panel">
          <div class="spread">
            <span class="project__title"><?= e($field['label']) ?></span>
            <div class="inline">
              <?php if ($field['tone'] !== 'quiet'): ?>
                <span class="prio prio--<?= e($field['tone']) ?>">Active</span>
              <?php endif; ?>
              <span class="project__revenue"><?= e($field['display']) ?></span>
            </div>
          </div>

          <div class="deflist">
            <div class="defrow">
              <span class="defrow__key">Decides</span>
              <span class="defrow__value"><?= e($field['decides']) ?></span>
            </div>
            <div class="defrow">
              <span class="defrow__key">Right now</span>
              <span class="defrow__value"><?= e($field['effect']) ?></span>
            </div>
          </div>

          <?php if ($field['help'] !== null): ?>
            <span class="t-note"><?= e((string) $field['help']) ?></span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>

<!-- The exchange rate is an assumption with an age, so it is treated like one. -->
<section class="card">
  <div class="card__head">
    <span class="t-card-title">Exchange Rate</span>
    <?= chip_raw(
      $rate['state'] === 'current' ? 'entered' : 'estimated',
      $rate['state'] === 'current' ? 'Confirmed' : 'Unverified',
      'A manually maintained assumption, not a live rate'
    ) ?>
  </div>

  <div class="duerow duerow--<?= $rate['tone'] === 'high' ? 'critical' : ($rate['tone'] === 'warn' ? 'high' : '') ?>">
    <span class="duerow__dot"></span>
    <span class="duerow__title"><?= e($rate['display']) ?></span>
    <span class="duerow__when"><?= e($rate['when']) ?></span>
    <button class="btn btn--sm flex-none" type="button" data-quick-add="setting">Confirm the rate</button>
  </div>

  <div class="deflist deflist--ruled">
    <div class="defrow">
      <span class="defrow__key">Standing</span>
      <span class="defrow__value"><?= e($rate['verdict']) ?></span>
    </div>
    <div class="defrow">
      <span class="defrow__key">What depends on it</span>
      <span class="defrow__value"><?= e($rate['affects']) ?></span>
    </div>
  </div>

  <div class="breakdown__total">
    <span>Confirming the rate records the date, which is the only reason the OS can ever tell you it has gone stale</span>
  </div>
</section>

<section class="row row--wide">

  <!-- What is deliberately not a setting. -->
  <div class="card">
    <div class="card__head">
      <span class="t-card-title">Not Configurable, On Purpose</span>
      <?= chip_raw('calculated', 'Derived', 'These are judgements the OS holds, not preferences it takes') ?>
    </div>

    <p class="t-body">
      Each of these is a number someone might reasonably look for a field for.
      There is not one, and the reason matters more than the value.
    </p>

    <div class="stack--tight">
      <?php foreach ($data['fixed'] as $item): ?>
        <div class="panel">
          <div class="spread">
            <span class="project__title"><?= e($item['label']) ?></span>
            <span class="t-label"><?= e($item['value']) ?></span>
          </div>
          <span class="t-note"><?= e($item['why']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- What is actually in the database. -->
  <div class="card">
    <div class="card__head">
      <span class="t-card-title">What Is In the Database</span>
      <?php if ($dataState['demo']): ?>
        <?= chip_raw('estimated', 'Demo data loaded', 'Illustrative rows, not your own records') ?>
      <?php else: ?>
        <?= chip_raw('entered', 'Your data', 'Everything here was entered by you') ?>
      <?php endif; ?>
    </div>

    <?php if ($dataState['demo']): ?>
      <p class="t-body">
        This database carries the demo dataset. The figures on every screen are
        real arithmetic over illustrative rows, which is useful for reading the
        system and misleading for making a decision.
      </p>
    <?php endif; ?>

    <div class="deflist deflist--ruled">
      <?php foreach ($dataState['counts'] as $count): ?>
        <div class="defrow">
          <span class="defrow__key"><?= e($count['label']) ?></span>
          <span class="defrow__value<?= $count['value'] === 0 ? ' notif__cue' : '' ?>">
            <?= $count['value'] === 0 ? 'None' : (int) $count['value'] ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="breakdown__total">
      <span>
        Rebuilding the database is destructive and irreversible, so it is a command
        rather than a button: <strong><?= e($dataState['reset']) ?></strong>
      </span>
    </div>
  </div>
</section>

<section class="card">
  <div class="card__head">
    <span class="t-card-title">Change a Threshold</span>
    <span class="t-note">Takes effect everywhere immediately</span>
  </div>

  <p class="t-body">
    Nothing here is validated only for its own sake. The tight runway threshold has
    to sit above the critical one, and the household requirement cannot be zero,
    because either would leave the OS unable to warn you about the thing the
    setting exists to warn you about.
  </p>

  <button class="btn btn--primary card__foot" type="button" data-quick-add="setting">Change a setting</button>
</section>
