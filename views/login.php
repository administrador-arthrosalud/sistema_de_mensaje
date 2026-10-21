<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#10233f">
    <title>Iniciar sesión | Chatbot</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&family=Montserrat:wght@500;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= (int) filemtime(__DIR__ . '/../public/assets/css/app.css') ?>">
</head>
<body class="login-page">
<main class="login-card">
    <div class="login-brand">
        <img class="brand-logo" src="assets/img/logo-chat.png" alt="Arthrosalud" width="144" height="153">
        <span class="login-wordmark"><span class="wm-top">CLÍNICA DE TRAUMATOLOGÍA</span><span class="wm-name">ARTHROSALUD</span><span class="wm-tag">MOVIMIENTO SIN DOLOR</span></span>
    </div>
    <h1>Iniciar sesión</h1>
    <p class="login-hint">Ingresa con tu usuario para continuar.</p>
    <?php if ($error !== ''): ?>
        <div class="login-error" role="alert"><?= $escape($error) ?></div>
    <?php endif; ?>
    <form method="post" action="login.php" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="next" value="<?= $escape($next) ?>">
        <label for="username">Usuario</label>
        <input id="username" name="username" type="text" value="<?= $escape($username) ?>" autocomplete="username" autocapitalize="none" required autofocus>
        <label for="password">Contraseña</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit" class="login-submit">Entrar</button>
    </form>
</main>
</body>
</html>
