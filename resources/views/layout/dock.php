<?php

declare(strict_types=1);

/**
 * The persistent command dock. Shared by every screen so the board is always
 * one keystroke away, wherever Gerald happens to be.
 *
 * @var list<string>       $suggestions
 * @var array<string,mixed> $meta
 */
?>
<div class="dock">
  <div class="dock__answer" hidden data-answer>
    <?= chip_raw($meta['ai_live'] ? 'ai' : 'calculated', $meta['ai_live'] ? 'AI' : 'Rules', 'Answer source') ?>
    <div data-answer-body class="dock__answer-body"></div>
    <button class="alert__dismiss" type="button" data-close-answer aria-label="Close">×</button>
  </div>

  <?php if ($suggestions !== []): ?>
    <div class="dock__suggestions">
      <?php foreach ($suggestions as $suggestion): ?>
        <button class="dock__suggestion" type="button" data-suggestion="<?= e($suggestion) ?>"><?= e($suggestion) ?></button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form class="dock__bar" data-command-form autocomplete="off">
    <!-- Not "ask anything": this is where Gerald thinks out loud and the board
         reacts, so the prompt invites a statement, not just a question. -->
    <input class="dock__input" type="text" name="question" placeholder="Tell Germa OS what you're thinking…"
      aria-label="Tell Germa OS what you're thinking">
    <button class="btn btn--primary" type="submit">Send</button>
  </form>
</div>
