<?php

declare(strict_types=1);

/**
 * System → AI Configuration.
 *
 * The dark card is a four-step ladder from intention to evidence, because this
 * project spent a fortnight looking correctly configured while every single model
 * call was being rejected and answered by arithmetic instead. Three of those steps
 * are settings. Only the last one proves anything.
 *
 * @var array<string,mixed> $data From AiConfigService::build()
 */

$reach    = $data['reach'];
$key      = $data['key'];
$evidence = $data['evidence'];
$prompt   = $data['prompt'];
?>

<div class="pagehead">
  <div class="pagehead__title">
    <span class="t-eyebrow">System</span>
    <h1 class="t-display pagehead__h1">AI Configuration</h1>
  </div>
  <?= chip_raw(
    $reach['working'] ? 'ai' : 'calculated',
    $reach['working'] ? 'Model answering' : ($reach['driver'] === 'claude' ? 'Unproven' : 'Rules only'),
    'Read from the call log, not from the configuration'
  ) ?>
</div>

<div class="insight">
  <?= chip_raw($data['insight']['provenance'], 'System', 'Derived from config, the installed SDK and the call log') ?>
  <p class="insight__text"><?= e($data['insight']['text']) ?></p>
</div>

<!-- ── Configured, versus working ─────────────────────────────────────────── -->
<section class="card card--dark stack--loose">
  <div class="card__head">
    <span class="t-card-title">Configured, Versus Working</span>
    <?= chip_raw('on-dark', $reach['working'] ? 'Proven' : 'Not proven',
      'The first three lines are intentions. Only the fourth is evidence.') ?>
  </div>

  <div class="t-note">Four facts, in ascending order of how much they prove</div>
  <p class="board__quote"><?= e($reach['headline']) ?></p>

  <div class="board__signals">
    <?php foreach ($reach['steps'] as $index => $step): ?>
      <div class="signal" title="<?= e($step['note']) ?>">
        <span class="signal__role"><?= $index + 1 ?> · <?= e($step['label']) ?></span>
        <span class="signal__glyph" style="color:<?= $step['ok'] ? 'var(--signal-good)' : 'var(--coral-500)' ?>">
          <?= $step['ok'] ? '&check;' : '&times;' ?>
        </span>
        <span class="signal__note"><?= e($step['value']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($reach['last_fail'] !== null): ?>
    <div class="donot">
      <span class="donot__label">Last failure</span>
      <span class="donot__text"><?= e($reach['last_fail']['when']) ?>: <?= e($reach['last_fail']['error']) ?></span>
    </div>
  <?php endif; ?>

  <div class="inline">
    <button class="btn btn--primary" type="button" data-ai-test>Test the connection</button>
    <span class="t-note" data-ai-test-status>
      One live call. It is the only thing on this page that turns a setting into a fact.
    </span>
  </div>

  <div class="insight" data-ai-test-result hidden>
    <?= chip_raw('ai', 'Result', 'What came back from the test call') ?>
    <p class="insight__text" data-ai-test-text></p>
  </div>
</section>

<section class="row row--wide">

  <!-- ── The key, described but never shown ──────────────────────────────── -->
  <div class="card">
    <div class="card__head">
      <span class="t-card-title">The Key</span>
      <?= chip_raw('unavailable', 'Never displayed', 'A page that shows a secret is a page that leaks one') ?>
    </div>

    <p class="t-body">
      The key is described here, never printed, not even partially. What matters is
      which file it lives in.
    </p>

    <div class="stack--tight">
      <?php foreach ($key['checks'] as $check): ?>
        <div class="duerow<?= $check['ok'] ? '' : ' duerow--critical' ?>">
          <span class="duerow__dot"></span>
          <span class="duerow__title"><?= e($check['label']) ?></span>
          <span class="t-note project__note"><?= e($check['note']) ?></span>
          <span class="prio prio--<?= $check['ok'] ? 'good' : 'high' ?>"><?= $check['ok'] ? 'PASS' : 'FIX' ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── What the log says ───────────────────────────────────────────────── -->
  <div class="card">
    <div class="card__head">
      <span class="t-card-title">What the Log Says</span>
      <?= chip_raw('entered', 'Recorded', 'Every call is logged with its engine, latency and any error') ?>
    </div>

    <div class="cardstats">
      <div class="cardstats__item">
        <span class="cardstats__label">Calls made</span>
        <span class="cardstats__value"><?= (int) $evidence['total'] === 0 ? 'None' : (int) $evidence['total'] ?></span>
      </div>
      <div class="cardstats__item">
        <span class="cardstats__label">By a model</span>
        <span class="cardstats__value"><?= (int) $evidence['model'] ?></span>
      </div>
      <div class="cardstats__item">
        <span class="cardstats__label">Failed</span>
        <span class="cardstats__value<?= (int) $evidence['failed'] > 0 ? ' cardstats__value--bad' : ' cardstats__value--good' ?>">
          <?= (int) $evidence['failed'] === 0 ? 'None' : (int) $evidence['failed'] ?>
        </span>
      </div>
      <div class="cardstats__item">
        <span class="cardstats__label">Average, model</span>
        <span class="cardstats__value"><?= e($evidence['avg']) ?></span>
      </div>
    </div>

    <?php if ($evidence['failures'] !== []): ?>
      <div class="minutes">
        <?php foreach ($evidence['failures'] as $failure): ?>
          <div class="minute">
            <div class="spread">
              <span class="minute__q"><?= (int) $failure['count'] ?>&times;</span>
              <span class="t-note flex-none"><?= e($failure['last']) ?></span>
            </div>
            <p class="minute__a"><?= e($failure['error']) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="breakdown__total">
      <span><?= e($evidence['verdict']) ?></span>
    </div>
  </div>
</section>

<!-- ── What runs on a model, and what does not ────────────────────────────── -->
<section class="card">
  <div class="card__head">
    <span class="t-card-title">What Runs On a Model</span>
    <?= chip_raw('calculated', 'Four capabilities', 'Every one has a rules implementation, which is why the OS works without a key') ?>
  </div>

  <div class="stack--tight">
    <?php foreach ($data['capabilities'] as $capability): ?>
      <div class="panel">
        <div class="spread">
          <span class="project__title"><?= e($capability['label']) ?></span>
          <span class="prio prio--<?= match ($capability['state']) {
            'model'   => 'good',
            'rules'   => 'warn',
            'unknown' => 'high',
            default   => 'quiet',
          } ?>">
            <?= match ($capability['state']) {
              'model'   => 'MODEL',
              'rules'   => 'RULES',
              'unknown' => 'NOT RECORDED',
              default   => 'NOT RUN TODAY',
            } ?>
          </span>
        </div>

        <div class="deflist">
          <div class="defrow">
            <span class="defrow__key">Produces</span>
            <span class="defrow__value"><?= e($capability['produces']) ?></span>
          </div>
          <div class="defrow">
            <span class="defrow__key">Without a model</span>
            <span class="defrow__value"><?= e($capability['rules']) ?></span>
          </div>
          <div class="defrow">
            <span class="defrow__key">Today</span>
            <span class="defrow__value"><?= e($capability['detail']) ?></span>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ── The request that actually goes out ─────────────────────────────────── -->
<section class="card">
  <div class="card__head">
    <span class="t-card-title">The Request</span>
    <span class="t-note">Read only. These live in config files, not the database</span>
  </div>

  <div class="stack--tight">
    <?php foreach ($data['request'] as $item): ?>
      <div class="panel">
        <div class="spread">
          <span class="project__title"><?= e($item['label']) ?></span>
          <div class="inline">
            <?php if ($item['where'] !== null): ?>
              <span class="t-label"><?= e($item['where']) ?></span>
            <?php else: ?>
              <span class="prio prio--quiet">FIXED</span>
            <?php endif; ?>
            <span class="project__revenue"><?= e($item['value']) ?></span>
          </div>
        </div>
        <span class="t-note"><?= e($item['note']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="breakdown__total">
    <span>
      Change any of these in <strong>config/config.local.php</strong>. Nothing on this
      screen writes them, because a form that could rewrite config could also strip
      the accountability rules out of the prompt below
    </span>
  </div>
</section>

<!-- ── The prompt, read only and on purpose ───────────────────────────────── -->
<section class="card">
  <div class="card__head">
    <span class="t-card-title">The System Prompt</span>
    <?= chip_raw('entered', number_format($prompt['chars']) . ' characters, cached',
      'Byte-stable so it can carry a cache breakpoint') ?>
  </div>

  <p class="t-body"><?= e($prompt['why_readonly']) ?></p>

  <label class="field">
    <span class="field__label">Exactly what the board is told, every request</span>
    <textarea class="field__textarea prompt__text" rows="14" readonly><?= e($prompt['text']) ?></textarea>
  </label>

  <button class="btn btn--sm self-start" type="button"
    data-ask="Read your own system prompt back to me and tell me which instruction you find hardest to follow given my current position.">
    Ask the board about its own instructions
  </button>
</section>

<!-- ── The commitments that make a chip mean something ────────────────────── -->
<section class="card">
  <div class="card__head">
    <span class="t-card-title">What This Layer Guarantees</span>
    <?= chip_raw('calculated', 'Checkable claims', 'Each one names the mechanism, not the intention') ?>
  </div>

  <div class="deflist deflist--ruled">
    <?php foreach ($data['guarantees'] as $guarantee): ?>
      <div class="actionrow">
        <span class="actionrow__dot"></span>
        <span class="actionrow__text">
          <strong><?= e($guarantee['claim']) ?></strong><br>
          <span class="t-note"><?= e($guarantee['how']) ?></span>
        </span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="breakdown__total">
    <span>A provenance chip is only worth reading because of these. Break one and every chip in the OS becomes decoration</span>
  </div>
</section>
