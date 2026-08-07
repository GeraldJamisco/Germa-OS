<?php

declare(strict_types=1);

/**
 * @var string $message
 * @var string $trace
 */

use Germa\Support\Html;

require_once __DIR__ . '/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Error · Germa OS</title>
<link rel="stylesheet" href="<?= e(Html::asset('assets/css/germa.css')) ?>">
</head>
<body>
<div class="page" style="max-width:680px;margin:0 auto;padding-top:80px">
  <div class="card">
    <span class="chip chip--status chip--critical">ERROR</span>
    <h1 class="t-display" style="font-size:26px">Germa OS could not load</h1>
    <p style="font-size:14px;line-height:1.6"><?= e($message) ?></p>
    <?php if ($trace !== ''): ?>
      <code class="t-note" style="font-family:var(--font-mono);word-break:break-all"><?= e($trace) ?></code>
    <?php endif; ?>
    <p class="t-note">
      If this is the first run, the database may not exist yet. From the project directory:
      <code style="font-family:var(--font-mono)">php bin/migrate.php --demo</code>
    </p>
    <a class="btn" href="<?= e(Html::url('')) ?>" style="align-self:flex-start">Try again</a>
  </div>
</div>
</body>
</html>
