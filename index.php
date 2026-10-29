<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Платформа обучения</title>
<link rel="icon" type="image/svg+xml" href="/assets/logo-template.svg">
<link rel="shortcut icon" href="/assets/logo-template.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . "/assets/style.css") ?>">
<link rel="stylesheet" href="/assets/achievements.css?v=<?= filemtime(__DIR__ . "/assets/achievements.css") ?>">
<link rel="stylesheet" href="/assets/template.css"></head>
<!--<img src="assets/image_862901161314323133440.gif" class="lms-back">-->
<body>
    <style>
        .cert-wr{
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
    </style>
<!-- LOGIN -->
<div class="auth-screen active" id="screen-login">
  <div class="auth-left">
    <div class="auth-left-brand">
      <img src="/assets/logo-template.svg" alt="LMS" class="logo-img" id="logo-auth-1">
    </div>
    <div class="auth-tagline">
      <strong data-platform-tagline>Знания, которые помогают расти</strong><span data-platform-welcome>Обучение, практика и достижения вашей команды.</span>
    </div>
  </div>
  <div class="auth-right">
    <div class="auth-box">
      <div class="eyebrow">добро пожаловать</div>
      <h1 class="pg-title">вход<span class="dot">.</span></h1>
      <p class="pg-sub">войди в аккаунт, чтобы продолжить обучение</p>
      <div class="form-err" id="login-err"></div>
      <div class="field" id="f-le"><label>email</label><input type="email" id="le" placeholder="you@company.com"><div class="field-err">введи корректный email</div></div>
      <div class="field" id="f-lp"><label>пароль</label><input type="password" id="lp" placeholder="••••••••"><div class="field-err">введи пароль</div></div>
      
      <button class="btn btn-full" onclick="doLogin()">войти →</button>
      <div class="auth-switch"><a onclick="openPasswordReset()">забыли пароль?</a></div>
      <div class="auth-switch">нет аккаунта? </div>
    </div>
  </div>
</div>

<!-- REGISTER -->


<!-- APP -->
<div class="app-screen" id="screen-app">
  <!-- Бургер -->
  <button class="mob-menu-btn" id="mob-menu-btn" onclick="openMobMenu()" aria-label="Меню">
    <span></span><span></span><span></span>
  </button>
  <!-- Оверлей -->
  <div class="mob-overlay" id="mob-overlay" onclick="closeMobMenu()"></div>

  <aside class="sidebar" id="sidebar">
    <div class="sb-brand">
      <img src="/assets/logo-template.svg" alt="LMS" class="logo-img" id="logo-sidebar">
      <div style="font-size:9px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-family:'Unbounded',sans-serif">lms</div>
    </div>
    <div class="sb-user">
      <div class="user-row">
        <div class="av" id="sb-av">—</div>
        <div>
          <div class="u-name" id="sb-name">—</div>
          <div class="u-role" id="sb-role">—</div>
          <div class="dept-pill" id="sb-dept">—</div>
        </div>
      </div>
    </div>
    <nav class="sb-nav">
      <div class="nav-group-lbl" style="display: none; margin-bottom:6px">маршрут</div>
      
      <div class="nav-item active" onclick="navTo('test',this)">
        <div class="nav-left">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
          тест: обучения
        </div>
      </div>
      
      <div style="height:1px;background:var(--border);margin:10px 0"></div>
      <div class="nav-group-lbl" style="margin-bottom:6px">аккаунт</div>
      <div class="nav-item" onclick="navTo('results',this)"><div class="nav-left"><svg style="opacity: 0.4;" fill="#000000" height="14px" width="14px" version="1.1" id="Capa_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 297 297" xml:space="preserve">
<g>
	<path d="M261.489,66.973L197.412,2.895C195.558,1.041,193.044,0,190.423,0H42.499c-5.458,0-9.884,4.426-9.884,9.883v277.233
		c0,5.458,4.426,9.884,9.884,9.884h212.002c5.458,0,9.884-4.426,9.884-9.884V73.961C264.385,71.34,263.343,68.826,261.489,66.973z
		 M200.307,33.745l30.333,30.333h-30.333V33.745z M52.383,277.232V19.767h128.156v54.194c0,5.459,4.426,9.884,9.884,9.884h54.194
		v193.388H52.383z"></path>
	<path d="M149.523,136.676c-1.501-3.753-5.136-6.213-9.178-6.213c-4.041,0-7.676,2.46-9.177,6.213l-32.615,81.539
		c-2.027,5.068,0.438,10.819,5.506,12.848c5.066,2.025,10.819-0.439,12.848-5.507l4.037-10.095h38.803l4.037,10.095
		c1.547,3.864,5.258,6.216,9.181,6.216c1.222,0,2.464-0.229,3.667-0.709c5.068-2.028,7.534-7.779,5.507-12.848L149.523,136.676z
		 M128.852,195.693l11.494-28.735l11.495,28.735H128.852z"></path>
	<path d="M211.553,133.428h-7.952v-8.002c0-5.458-4.426-9.883-9.884-9.883c-5.458,0-9.883,4.425-9.883,9.883v8.002h-7.952
		c-5.459,0-9.884,4.425-9.884,9.883c0,5.459,4.425,9.884,9.884,9.884h7.952v8.002c0,5.458,4.425,9.884,9.883,9.884
		c5.458,0,9.884-4.426,9.884-9.884v-8.002h7.952c5.458,0,9.884-4.425,9.884-9.884C221.436,137.852,217.011,133.428,211.553,133.428z
		"></path>
</g>
</svg>результаты</div></div>
      <div class="nav-item" onclick="navTo('profile',this)">
        <div class="nav-left">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z"/></svg>
          профиль
        </div>
      </div>
      
    </nav>
    <div class="sb-bottom">
      <!-- МОНЕТЫ ВРЕМЕННО СКРЫТЫ
      <div class="coins-block">
        <div class="coins-row"><span class="coins-lbl">монеты</span><span class="coins-val" id="coins-disp">0</span></div>
        <div class="coins-track"><div class="coins-fill" id="coins-fill" style="width:0%"></div></div>
        <div class="coins-hint">до уровня 2 — 500 монет</div>
      </div>
      -->
      <div id="coins-disp" style="display:none">0</div>
      <div id="coins-fill" style="display:none"></div>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
        <div class="logout-btn" onclick="doLogout()" style="margin-bottom:0">
          <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
          выйти
        </div>
        <button onclick="toggleTheme();applyLogoTheme()" title="Сменить тему"
          style="background:none;border:1px solid var(--border2);color:var(--muted2);cursor:pointer;padding:5px 8px;font-size:14px;line-height:1;transition:border-color .15s"
          id="theme-toggle-btn">🌙</button>
      </div>
    </div>
  </aside>

  <main class="main">
    <!-- МАРШРУТ ОБУЧЕНИЯ — единая страница -->
    

    <!-- ОБУЧЕНИЯ — УРОВНИ -->
    <div class="page active" id="page-test">
      <div class="eyebrow" id="test-ey">обучения</div>
      <h1 class="pg-title">тест<span class="dot">.</span></h1>
      <p class="pg-sub" id="test-sub">выбери уровень — тебе выпадут случайные вопросы из банка.</p>

      <!-- Выбор уровня -->
      <div id="skill-levels-wrap">
        <div style="color:var(--muted)">загрузка...</div>
      </div>

      <!-- Квиз (появляется после выбора уровня) -->
      <div id="quiz-wrap" style="display:none"></div>
    </div>

    <!-- СТАНДАРТЫ -->
    

    <!-- ПРОФИЛЬ -->
    <div class="page" id="page-results"><h1 class="pg-title">Результаты<span class="dot">.</span></h1><p class="pg-sub">Проверенные тесты и задания, опубликованные администратором.</p><div id="employee-results"></div></div>
    <div class="page" id="page-profile">
      <div class="eyebrow">личный кабинет</div>
      <h1 class="pg-title">профиль<span class="dot">.</span></h1>
      <p class="pg-sub">Ваши данные и достижения.</p>
      <div class="profile-card">
        <div style="position:relative;flex-shrink:0">
          <div class="pf-av" id="pf-av" style="cursor:pointer" onclick="document.getElementById('av-upload').click()" title="Нажми чтобы сменить фото">—</div>
          <div style="position:absolute;bottom:-2px;right:-2px;width:18px;height:18px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;cursor:pointer" onclick="document.getElementById('av-upload').click()">+</div>
          <input type="file" id="av-upload" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none" onchange="uploadAvatar(this)">
        </div>
        <div>
          <div class="pf-name" id="pf-name">—</div>
          <div class="pf-meta"><span id="pf-role">—</span><span id="pf-dept-acc" style="color:var(--primary)">—</span></div>
        </div>
      </div>
      
      
      <div class="info-grid">
        <div class="info-cell"><div class="info-lbl">email</div><div class="info-val" id="pf-email">—</div></div>
        
        
        
        <div class="info-cell"><div class="info-lbl">дата регистрации</div><div class="info-val" id="pf-date">—</div></div>
        <div class="info-cell"><div class="info-lbl">статус</div><div class="info-val">в процессе</div></div>
      </div>
      

      <!-- Ачивки -->
      <div style="margin-bottom:22px">
        <div style="font-size:9px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-family:'Unbounded',sans-serif;margin-bottom:12px">достижения</div>
        <div style="display:flex;flex-wrap:wrap;gap:8px">
        <div id="pf-achievements" style="display:flex;flex-wrap:wrap;gap:8px"></div>
        <div id="pf-levels" style="display:flex;flex-wrap:wrap;gap:8px"></div>
        </div>
      </div>
      

      <button class="btn btn-ghost" onclick="doLogout()">выйти из аккаунта →</button>
    </div>

    <!-- ПРОГРЕСС -->
    
    </div>



  </main>
</div>


<!-- Модал шага онбординга -->

<div class="toast" id="toast"></div>

<!-- ── ЛЕНТА ОБСУЖДЕНИЙ ──────────────────────────────────────── -->
<!-- Кнопка-триггер -->
<link rel="stylesheet" href="/assets/results-dashboard.css?v=20261014-ring">
<script src="/assets/results-dashboard.js?v=<?= hash_file('sha256', __DIR__ . "/assets/results-dashboard.js") ?>"></script>
<script src="/assets/app.js?v=20261021-drafts"></script>
<script src="/assets/achievement-icons.js?v=<?= filemtime(__DIR__ . "/assets/achievement-icons.js") ?>"></script>

<script src="/assets/draft-saver.js?v=20261021-1"></script>
<script src="/assets/main.js?v=<?= hash_file('sha256', __DIR__ . "/assets/main.js") ?>"></script>
<script src="/assets/template.js"></script></body>
</html>
