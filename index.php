<?php
require_once __DIR__ . '/lib/auth.php';

if (($_GET['logout'] ?? '') === '1') { auth_logout(); header('Location: /crm/'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (auth_login((string)$_POST['password'])) { header('Location: /crm/'); exit; }
    $err = 'Incorrect password.';
}

$authed = auth_user();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>Weyney CRM</title>
<link rel="stylesheet" href="/crm/assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
</head>
<body class="<?= $authed ? 'app' : 'gate' ?>">

<?php if (!$authed): ?>
<form class="login" method="post" autocomplete="off">
  <img class="mark" src="/crm/assets/weyney-logo.svg" alt="Weyney Media">
  <p class="sub">Sales CRM</p>
  <input type="password" name="password" placeholder="Password" autofocus required>
  <button type="submit">Sign in</button>
  <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
</form>

<?php else: ?>
<div id="app" data-csrf="<?= htmlspecialchars(csrf_token()) ?>">
  <div class="boot">Loading…</div>
</div>
<script src="/crm/assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
<?php endif; ?>

</body>
</html>
