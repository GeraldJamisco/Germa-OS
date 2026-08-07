<?php

declare(strict_types=1);

/**
 * First-run setup. A standalone, one-step-at-a-time flow, shown before the app
 * chrome exists, because half the chrome depends on the very figures this
 * collects. It shares only the stylesheet with the rest of the OS.
 *
 * @var int                 $step     current step (1..LAST_STEP)
 * @var array<int,mixed>    $steps    OnboardingService::STEPS
 * @var int                 $last     OnboardingService::LAST_STEP
 * @var array<string,string> $values  what is already entered
 * @var string              $token    CSRF token
 * @var string|null         $error    message to show, or null
 * @var string              $operator greeting name, or ''
 * @var string              $base     base currency code
 */

use Germa\Support\Html;

require_once __DIR__ . '/helpers.php';

$meta = $steps[$step];
$pct  = (int) round(($step / $last) * 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Set up · Germa OS</title>
<link rel="stylesheet" href="<?= e(Html::asset('assets/css/germa.css')) ?>">
</head>
<body>
<div class="page" style="max-width:440px;margin:0 auto;padding-top:8vh">
  <div class="card stack--loose">

    <div class="pagehead__title">
      <span class="t-eyebrow">Germa OS · Setup</span>
      <h1 class="t-display" style="font-size:24px"><?= e($meta['title']) ?></h1>
    </div>

    <div class="meter">
      <div class="meter__head">
        <span class="t-note">Step <?= (int) $step ?> of <?= (int) $last ?></span>
        <span class="meter__value"><?= (int) $pct ?>%</span>
      </div>
      <div class="bar"><div class="bar__fill bar__fill--coral" style="width:<?= (int) $pct ?>%"></div></div>
    </div>

    <p class="t-body"><?= e($meta['blurb']) ?></p>

    <?php if ($error !== null): ?>
      <div class="insight">
        <?= chip_raw('critical', 'Check this', 'The step was not saved') ?>
        <p class="insight__text"><?= e($error) ?></p>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= e(Html::url('welcome')) ?>" class="stack--tight">
      <input type="hidden" name="_token" value="<?= e($token) ?>">
      <input type="hidden" name="step" value="<?= (int) $step ?>">

      <?php if ($meta['key'] === 'you'): ?>
        <label class="field">
          <span class="field__label">Your name *</span>
          <input class="field__input" type="text" name="operator_name"
            value="<?= e($values['operator_name']) ?>" autofocus required>
        </label>
        <label class="field">
          <span class="field__label">Where you operate</span>
          <input class="field__input" type="text" name="operator_location"
            value="<?= e($values['operator_location']) ?>" placeholder="City or region">
        </label>

      <?php elseif ($meta['key'] === 'survival'): ?>
        <label class="field">
          <span class="field__label">Monthly household requirement (<?= e($base) ?>) *</span>
          <input class="field__input" type="number" name="survival" step="any" min="1"
            value="<?= e($values['survival']) ?>" placeholder="580000" autofocus required>
        </label>

      <?php elseif ($meta['key'] === 'cash'): ?>
        <label class="field">
          <span class="field__label">Cash available now (<?= e($base) ?>) *</span>
          <input class="field__input" type="number" name="amount" step="any" min="0"
            value="<?= e($values['cash']) ?>" placeholder="0" autofocus required>
        </label>

      <?php elseif ($meta['key'] === 'debt'): ?>
        <label class="field">
          <span class="field__label">What is it?</span>
          <input class="field__input" type="text" name="name"
            value="<?= e($values['debt_name']) ?>" placeholder="Personal loan" autofocus>
        </label>
        <div class="row" style="grid-template-columns:2fr 1fr;gap:10px">
          <label class="field">
            <span class="field__label">Amount owed</span>
            <input class="field__input" type="number" name="amount" step="any" min="0"
              value="<?= e($values['debt_amount']) ?>" placeholder="535">
          </label>
          <label class="field">
            <span class="field__label">Currency</span>
            <select class="field__select" name="currency">
              <?php foreach (['USD', 'UGX'] as $cur): ?>
                <option value="<?= e($cur) ?>"<?= $values['debt_currency'] === $cur ? ' selected' : '' ?>><?= e($cur) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

      <?php elseif ($meta['key'] === 'projects'): ?>
        <?php if ($projects !== []): ?>
          <div class="stack--tight">
            <span class="t-note"><?= count($projects) ?> added</span>
            <?php foreach ($projects as $project): ?>
              <div class="duerow">
                <span class="duerow__dot"></span>
                <span class="duerow__title"><?= e($project['name']) ?></span>
                <span class="t-note flex-none"><?= e(ucfirst($project['status'])) ?></span>
                <span class="prio prio--quiet"><?= e($project['priority']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <label class="field">
          <span class="field__label"><?= $projects === [] ? 'Your first project' : 'Add another' ?></span>
          <input class="field__input" type="text" name="name" placeholder="Client Websites"
            <?= $projects === [] ? 'autofocus' : '' ?>>
        </label>
        <div class="row" style="grid-template-columns:1fr 1fr;gap:10px">
          <label class="field">
            <span class="field__label">Status</span>
            <select class="field__select" name="status">
              <?php foreach ([
                'development' => 'Development',
                'validation'  => 'Validation',
                'strategic'   => 'Strategic',
                'revenue'     => 'Revenue-generating',
                'paused'      => 'Paused',
              ] as $val => $label): ?>
                <option value="<?= e($val) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field">
            <span class="field__label">Priority</span>
            <select class="field__select" name="priority">
              <?php foreach (['LOW', 'MEDIUM', 'HIGH'] as $pri): ?>
                <option value="<?= e($pri) ?>"><?= e($pri) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
      <?php endif; ?>

      <?php if ($meta['key'] === 'debt'): ?>
        <div class="inline">
          <button class="btn btn--primary" type="submit" name="skip" value="0">Add it and continue</button>
          <button class="btn" type="submit" name="skip" value="1">Nothing owed, continue</button>
        </div>
      <?php elseif ($meta['key'] === 'projects'): ?>
        <div class="inline">
          <button class="btn" type="submit" name="action" value="add">Add and enter another</button>
          <button class="btn btn--primary" type="submit" name="action" value="finish">
            <?= $projects === [] ? 'Add and finish' : 'Finish setup' ?>
          </button>
        </div>
        <?php if ($projects === []): ?>
          <span class="t-note">At least one project is required. The rest are added inside the app later.</span>
        <?php endif; ?>
      <?php else: ?>
        <button class="btn btn--primary" type="submit">Continue</button>
      <?php endif; ?>
    </form>

    <?php if ($step > 1): ?>
      <a class="btn--link" href="<?= e(Html::url('welcome')) ?>?step=<?= (int) $step - 1 ?>">← Back a step</a>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
