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
<div class="authpage">

  <div class="brandlock">
    <div class="brandmark brandmark--lg">G</div>
    <div class="brandlock__id">
      <span class="brandlock__name">Germa OS</span>
      <span class="authcaption">Executive intelligence</span>
    </div>
  </div>

  <?php if (!$configured): ?>
    <div class="authpage__card">
      <div class="stack--tight" style="text-align:center">
        <h1 class="t-display" style="font-size:22px">Set a password first</h1>
        <p class="t-note">The app is locked until one exists.</p>
      </div>
      <div class="insight">
        <?= chip_raw('unavailable', 'No password set', 'The app is locked until a password exists') ?>
        <p class="insight__text">
          Generate one from the project directory with
          <code class="authcaption" style="text-transform:none">php bin/set-password.php</code>,
          paste the line it prints into
          <code class="authcaption" style="text-transform:none">config/config.local.php</code>,
          then reload.
          <?php if ($loopback): ?>
            Until then you are seeing the app only because this request came from
            the same machine.
          <?php endif; ?>
        </p>
      </div>
    </div>

  <?php else: ?>
    <form class="authpage__card" method="post" action="<?= e(Html::url('login')) ?>">
      <input type="hidden" name="_token" value="<?= e($token) ?>">

      <div class="stack--tight" style="text-align:center">
        <h1 class="t-display" style="font-size:22px">Welcome back</h1>
        <p class="t-note">Enter your access password to continue</p>
      </div>

      <label class="field">
        <span class="field__label">Password</span>
        <input class="field__input" type="password" name="password"
          placeholder="••••••••••" autocomplete="current-password" autofocus required>
      </label>

      <?php if ($error !== null): ?>
        <span class="field__label" style="color:var(--coral-700)"><?= e($error) ?></span>
      <?php endif; ?>

      <button class="btn btn--primary" type="submit">Unlock Germa OS</button>

      <span class="statusline" style="justify-content:center">
        <span class="statusline__dot"></span>System operational
      </span>
    </form>
  <?php endif; ?>

  <span class="authcaption">Germa OS · Private access</span>
</div>
</body>
</html>
