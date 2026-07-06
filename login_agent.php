<?php
session_start();
include 'db.php';

if (isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['AGENT', 'IT', 'Supervisor'], true)) {
    header('Location: agent_dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username !== '' && $password !== '') {
        $stmt = $pdo->prepare("SELECT id, username, full_name, role, password, is_active FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['password'] === $password) {
            // Check if user is active
            $isActive = isset($user['is_active']) ? (int)$user['is_active'] : 1;
            if ($isActive === 0) {
                $error = 'Tu cuenta ha sido desactivada. Contacta al administrador.';
            } elseif (in_array($user['role'], ['AGENT', 'IT', 'Supervisor'], true)) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];

                header('Location: agent_dashboard.php');
                exit;
            } else {
                $error = 'No tienes permisos para acceder.';
            }
        } else {
            $error = 'Credenciales invalidas.';
        }
    } else {
        $error = 'Por favor completa todos los campos.';
    }
}

$prefillUser = htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <title>Acceso de Agentes · Evallish BPO</title>
    <style>
        :root{
            --brand:#244886; --brand-600:#1E3D73; --brand-800:#16305C; --blue:#4896FE;
            --teal:#16C8C7; --ink:#1B2A4A; --text:#243858; --muted:#5C6B8A; --faint:#8492AC;
            --line:#E5EAF3; --soft:#F1F4FA; --card:#FFFFFF;
            --shadow:0 30px 70px -25px rgba(20,40,80,.35);
            --ease:cubic-bezier(.4,0,.2,1);
        }
        *{margin:0;padding:0;box-sizing:border-box;}
        body{
            font-family:'Plus Jakarta Sans',system-ui,-apple-system,sans-serif;
            min-height:100vh; display:grid; place-items:center; padding:24px;
            color:var(--text);
            background:
                radial-gradient(1100px 520px at 88% -10%, rgba(72,150,254,.14), transparent 60%),
                radial-gradient(900px 500px at -5% 110%, rgba(22,200,199,.10), transparent 55%),
                #EEF2F9;
            -webkit-font-smoothing:antialiased;
        }

        .login-card{
            width:100%; max-width:940px; background:var(--card); border-radius:24px; overflow:hidden;
            display:grid; grid-template-columns:1.05fr 1fr; box-shadow:var(--shadow);
            border:1px solid rgba(255,255,255,.6);
            animation:rise .5s var(--ease) both;
        }
        @keyframes rise{from{opacity:0; transform:translateY(14px) scale(.99);}to{opacity:1; transform:none;}}

        /* ---------- panel de marca ---------- */
        .brand-side{
            position:relative; padding:52px 46px; color:#fff; overflow:hidden;
            background:
                radial-gradient(700px 360px at 78% 6%, rgba(72,150,254,.55), transparent 60%),
                linear-gradient(158deg, #2A5199 0%, var(--brand) 44%, var(--brand-800) 100%);
            display:flex; flex-direction:column;
        }
        .brand-side::before{ /* patrón sutil de puntos */
            content:''; position:absolute; inset:0; opacity:.5;
            background-image:radial-gradient(rgba(255,255,255,.12) 1px, transparent 1.4px);
            background-size:22px 22px; -webkit-mask-image:linear-gradient(160deg,#000,transparent 75%); mask-image:linear-gradient(160deg,#000,transparent 75%);
        }
        .brand-side .blob{position:absolute; border-radius:50%; filter:blur(6px); opacity:.35;}
        .brand-side .blob.a{width:150px;height:150px; right:-40px; top:-30px; background:radial-gradient(circle,var(--teal),transparent 70%);}
        .brand-side .blob.b{width:220px;height:220px; left:-70px; bottom:-70px; background:radial-gradient(circle,var(--blue),transparent 70%);}
        .brand-inner{position:relative; z-index:1; display:flex; flex-direction:column; height:100%;}
        .brand-logo{display:flex; align-items:center; gap:12px; margin-bottom:auto;}
        .brand-logo img{height:40px; width:auto; filter:brightness(0) invert(1);}
        .brand-logo .wm{font-weight:800; font-size:20px; letter-spacing:-.3px;}
        .brand-logo .wm span{opacity:.75; font-weight:600;}
        .brand-title{font-size:34px; line-height:1.1; font-weight:800; letter-spacing:-.8px; margin:34px 0 12px;}
        .brand-desc{font-size:14px; line-height:1.6; color:rgba(255,255,255,.82); max-width:330px;}
        .feat{margin-top:30px; display:flex; flex-direction:column; gap:14px;}
        .feat .row{display:flex; align-items:center; gap:13px; font-size:13.5px; font-weight:500; color:rgba(255,255,255,.92);}
        .feat .row i{width:38px; height:38px; flex-shrink:0; border-radius:11px; display:grid; place-items:center;
            background:rgba(255,255,255,.13); border:1px solid rgba(255,255,255,.18); font-size:15px; backdrop-filter:blur(4px);}

        /* ---------- panel del formulario ---------- */
        .form-side{padding:50px 46px; display:flex; flex-direction:column; justify-content:center;}
        .form-badge{width:52px; height:52px; border-radius:15px; display:grid; place-items:center; margin-bottom:20px;
            background:linear-gradient(135deg, rgba(72,150,254,.16), rgba(36,72,134,.12)); color:var(--brand); font-size:22px;
            border:1px solid var(--line);}
        .welcome{font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:1.4px; color:var(--blue);}
        .form-title{font-size:25px; font-weight:800; letter-spacing:-.5px; color:var(--ink); margin:6px 0 6px;}
        .form-sub{font-size:13.5px; color:var(--muted); margin-bottom:24px;}

        .alert{display:flex; align-items:center; gap:10px; background:#FEF2F2; border:1px solid #FCD9D6; color:#B42318;
            padding:12px 14px; border-radius:12px; font-size:13px; font-weight:600; margin-bottom:20px;}
        .alert i{font-size:15px;}

        .field{margin-bottom:16px;}
        .field label{display:block; font-size:12.5px; font-weight:700; color:var(--text); margin-bottom:7px;}
        .input-wrap{position:relative; display:flex; align-items:center;}
        .input-wrap > .lead{position:absolute; left:15px; color:var(--faint); font-size:14px; pointer-events:none; transition:color .18s;}
        .input-wrap input{
            width:100%; font-family:inherit; font-size:14px; color:var(--text); font-weight:500;
            padding:13px 15px 13px 42px; border:1.5px solid var(--line); border-radius:12px; background:#FBFCFE; outline:none;
            transition:border-color .18s var(--ease), box-shadow .18s var(--ease), background .18s;
        }
        .input-wrap input::placeholder{color:#AAB4C6; font-weight:500;}
        .input-wrap input:focus{border-color:var(--blue); background:#fff; box-shadow:0 0 0 4px rgba(72,150,254,.14);}
        .input-wrap input:focus + .lead, .input-wrap:focus-within > .lead{color:var(--blue);}
        .toggle-pass{position:absolute; right:8px; width:34px; height:34px; border:none; background:transparent; color:var(--faint);
            cursor:pointer; border-radius:9px; display:grid; place-items:center; font-size:14px; transition:.15s;}
        .toggle-pass:hover{color:var(--brand); background:var(--soft);}

        .submit-btn{
            width:100%; margin-top:6px; font-family:inherit; font-size:14.5px; font-weight:700; color:#fff; cursor:pointer;
            padding:14px; border:none; border-radius:12px; letter-spacing:.2px;
            background:linear-gradient(135deg, var(--brand) 0%, var(--brand-600) 100%);
            box-shadow:0 12px 24px -8px rgba(36,72,134,.55); transition:transform .16s var(--ease), box-shadow .2s var(--ease), opacity .2s;
            display:flex; align-items:center; justify-content:center; gap:9px;
        }
        .submit-btn:hover{transform:translateY(-1px); box-shadow:0 18px 30px -10px rgba(36,72,134,.6);}
        .submit-btn:active{transform:translateY(0);}
        .submit-btn[disabled]{opacity:.75; cursor:default; transform:none;}
        .submit-btn .spin{display:none; width:16px; height:16px; border:2px solid rgba(255,255,255,.45); border-top-color:#fff; border-radius:50%; animation:sp .7s linear infinite;}
        .submit-btn.loading .spin{display:inline-block;}
        .submit-btn.loading .lbl{opacity:.9;}
        @keyframes sp{to{transform:rotate(360deg);}}

        .forgot{display:inline-flex; align-items:center; gap:7px; margin:20px auto 0; text-decoration:none;
            color:var(--muted); font-size:13px; font-weight:600; transition:color .15s;}
        .forgot:hover{color:var(--brand);}
        .foot{margin-top:26px; padding-top:20px; border-top:1px solid var(--line); text-align:center; color:var(--faint); font-size:11.5px;}

        @media (max-width:860px){
            .login-card{grid-template-columns:1fr; max-width:460px;}
            .brand-side{padding:38px 34px;}
            .brand-title{font-size:27px; margin-top:22px;}
            .brand-desc{max-width:none;}
            .feat{display:none;}
            .form-side{padding:38px 34px;}
        }
        @media (max-width:420px){
            body{padding:14px;}
            .brand-side{padding:30px 26px;}
            .form-side{padding:32px 26px;}
        }
        @media (prefers-reduced-motion:reduce){ *{animation:none!important; transition:none!important;} }
    </style>
</head>
<body>
    <main class="login-card">
        <!-- Marca -->
        <section class="brand-side">
            <span class="blob a"></span><span class="blob b"></span>
            <div class="brand-inner">
                <div class="brand-logo">
                    <img src="assets/logo.png" alt="Evallish BPO" onerror="this.style.display='none'">
                    <div class="wm">Evall<span>ish</span></div>
                </div>
                <h1 class="brand-title">Portal de<br>Agentes</h1>
                <p class="brand-desc">Consulta tu actividad de Vicidial, tus horas, calidad y gestiona tus solicitudes desde un solo lugar.</p>
                <div class="feat">
                    <div class="row"><i class="fas fa-headset"></i><span>Tu actividad y llamadas en tiempo real</span></div>
                    <div class="row"><i class="fas fa-business-time"></i><span>Horas y quincenas siempre a la mano</span></div>
                    <div class="row"><i class="fas fa-star"></i><span>Calidad y auditorías de tus llamadas</span></div>
                </div>
            </div>
        </section>

        <!-- Formulario -->
        <section class="form-side">
            <div class="form-badge"><i class="fas fa-fingerprint"></i></div>
            <span class="welcome">Bienvenido</span>
            <h2 class="form-title">Acceso al sistema</h2>
            <p class="form-sub">Ingresa tus credenciales para continuar.</p>

            <?php if ($error): ?>
                <div class="alert"><i class="fas fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div>
            <?php endif; ?>

            <form method="POST" id="loginForm">
                <div class="field">
                    <label for="username">Usuario</label>
                    <div class="input-wrap">
                        <input type="text" name="username" id="username" autocomplete="username" required
                               placeholder="usuario@empresa" value="<?= $prefillUser ?>">
                        <i class="fas fa-user lead"></i>
                    </div>
                </div>

                <div class="field">
                    <label for="password">Contraseña</label>
                    <div class="input-wrap">
                        <input type="password" name="password" id="password" autocomplete="current-password" required
                               placeholder="••••••••">
                        <i class="fas fa-lock lead"></i>
                        <button type="button" class="toggle-pass" id="togglePass" aria-label="Mostrar contraseña">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="submit-btn" id="submitBtn">
                    <span class="spin"></span>
                    <span class="lbl">Iniciar sesión</span>
                    <i class="fas fa-arrow-right-to-bracket" style="font-size:13px;"></i>
                </button>

                <a href="password_recovery_agent.php" class="forgot" style="display:flex;">
                    <i class="fas fa-key"></i> ¿Olvidaste tu contraseña?
                </a>
            </form>

            <div class="foot">&copy; 2026 Evallish BPO. Todos los derechos reservados.</div>
        </section>
    </main>

    <script>
        // Mostrar / ocultar contraseña
        (function () {
            var btn = document.getElementById('togglePass'),
                pass = document.getElementById('password');
            if (btn && pass) {
                btn.addEventListener('click', function () {
                    var show = pass.type === 'password';
                    pass.type = show ? 'text' : 'password';
                    btn.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
                    btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
                    pass.focus();
                });
            }
        })();
        // Estado de carga al enviar (evita doble submit)
        (function () {
            var form = document.getElementById('loginForm'),
                btn = document.getElementById('submitBtn');
            if (form && btn) {
                form.addEventListener('submit', function () {
                    if (form.checkValidity()) {
                        btn.classList.add('loading');
                        btn.setAttribute('disabled', 'disabled');
                        btn.querySelector('.lbl').textContent = 'Ingresando…';
                    }
                });
            }
            // Autofocus al primer campo vacío
            var u = document.getElementById('username');
            if (u && !u.value) { u.focus(); } else { document.getElementById('password').focus(); }
        })();
    </script>
</body>
</html>
