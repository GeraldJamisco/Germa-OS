<?php

declare(strict_types=1);

/**
 * The sign-in gate. A standalone page rather than a screen inside the shell, so
 * a logged-out visitor never sees the navigation, the operator's name, or any
 * figure. It shares only the stylesheet.
 *
 * @var string      $token       CSRF token for the form
 * @var string|null $error       message to show, or null
 * @var bool        $configured  whether a password has been set at all
 * @var bool        $loopback    whether the request came from 127.0.0.1
 */

use Germa\Support\Html;

require_once __DIR__ . '/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sign in · Germa OS</title>
<link rel="stylesheet" href="<?= e(Html::asset('assets/css/germa.css')) ?>">
</head>
<body>
<div class="page" style="max-width:400px;margin:0 auto;padding-top:12vh">
  <div class="card stack--loose">
    <div class="pagehead__title">
      <span class="t-eyebrow">Germa OS</span>
      <h1 class="t-display" style="font-size:26px">Sign in</h1>
    </div>

    <?php if (!$configured): ?>
      <div class="insight">
        <?= chip_raw('unavailable', 'No password set', 'The app is locked until a password exists') ?>
        <p class="insight__text">
          No password has been configured yet. Generate one from the project
          directory with <code style="font-family:var(--font-mono)">php bin/set-password.php</code>,
          paste the line it prints into <code style="font-family:var(--font-mono)">config/config.local.php</code>,
          then reload.
          <?php if ($loopback): ?>
            Until then you are seeing the app only because this request came from
            the same machine.
          <?php endif; ?>
        </p>
      </div>
    <?php else: ?>
      <?php if ($error !== null): ?>
        <div class="insight">
          <?= chip_raw('critical', 'Denied', 'The credentials did not match') ?>
          <p class="insight__text"><?= e($error) ?></p>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= e(Html::url('login')) ?>" class="stack--tight">
        <input type="hidden" name="_token" value="<?= e($token) ?>">

        <label class="field">
          <span class="field__label">Password</span>
          <input class="field__input" type="password" name="password"
            autocomplete="current-password" autofocus required>
        </label>

        <button class="btn btn--primary" type="submit">Sign in</button>
      </form>
    <?php endif; ?>

    <p class="t-note">
      This is the only lock on the system. Everything past it can move money and
      change the record of it.
    </p>
  </div>
</div>
</body>
</html>
