<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/Database.php';

session_name(SESSION_NAME);
session_start();
if (empty($_SESSION['materials_import_token'])) $_SESSION['materials_import_token'] = bin2hex(random_bytes(32));
require_once __DIR__ . '/../api/access.php';
ensureLmsSchema();

$loginError = '';
$isLoggedIn = !empty($_SESSION['user_id']) && lmsStaff();

// Обработка POST-формы входа прямо здесь
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $user     = Database::find('SELECT * FROM users WHERE email = ?', [$email]);

    if ($user && (!empty($user['is_admin']) || (!empty($user['is_manager']) && !empty($user['dept_id']))) && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['is_admin'] = !empty($user['is_admin']);
        // Post/Redirect/Get — иначе браузер считает последним переходом на /admin/
        // отправку формы и при каждом F5 переспрашивает "повторить отправку формы?"
        header('Location: /admin/');
        exit;
    } else {
        $loginError = 'Неверный email или пароль, либо нет прав администратора';
    }
}

// Данные для админки — грузим только если вошли
$adminUser = $depts = $recent = $deptsJson = null;
$stats = [];
if ($isLoggedIn) {
    $adminUser  = Database::find('SELECT * FROM users WHERE id = ?', [$_SESSION['user_id']]);
    $departmentScope = !empty($adminUser['is_admin']) ? '1=1' : 'dept_id=' . (int)$adminUser['dept_id'];
    $userScope = !empty($adminUser['is_admin']) ? '1=1' : 'u.dept_id=' . (int)$adminUser['dept_id'];
    $stats = [
        'users'     => Database::find('SELECT COUNT(*) as n FROM users WHERE is_admin=0 AND (' . $departmentScope . ')')['n'],
        'tests'     => Database::find("SELECT COUNT(*) as n FROM tests WHERE content_type = 'quiz' AND (" . $departmentScope . ')')['n'],
        'assignments' => Database::find("SELECT COUNT(*) as n FROM tests WHERE content_type != 'quiz' AND skill_level_id IS NOT NULL AND (" . $departmentScope . ')')['n'],
    ];
    $depts    = Database::findAll('SELECT * FROM departments WHERE ' . (!empty($adminUser['is_admin']) ? '1=1' : 'id=' . (int)$adminUser['dept_id']) . ' ORDER BY id');
    $recent   = Database::findAll('SELECT u.first_name, u.last_name, u.email, u.coins, u.created_at,
        d.name as dept_name, r.name as role_name
        FROM users u
        LEFT JOIN departments d ON d.id=u.dept_id
        LEFT JOIN roles r ON r.id=u.role_id
        WHERE u.is_admin=0 AND (' . $userScope . ') ORDER BY u.created_at DESC LIMIT 10');
    $deptsJson = json_encode($depts, JSON_UNESCAPED_UNICODE);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Администрирование LMS</title>
<link rel="icon" type="image/svg+xml" href="/assets/logo-template.svg">
<link rel="stylesheet" href="/assets/style.css">
<link rel="icon" type="image/svg+xml" href="/assets/logo-template.svg">
<style>
.admin-ach-icon svg{width:32px;height:32px;display:block}
.les-desc{
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  max-width:500px;
}
.admin-wrap{display:flex;min-height:100vh}
.admin-sidebar{width:228px;flex-shrink:0;background:var(--dark);border-right:1px solid var(--border);display:flex;flex-direction:column}
.admin-main{flex:1;overflow:auto;background:var(--dark2)}
.admin-page{display:none;padding:38px 44px;max-width:1100px}
.admin-page.active{display:block}
.admin-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:24px}
.admin-title{font-family:'Unbounded',sans-serif;font-size:24px;font-weight:900;letter-spacing:-.02em}
.admin-title .dot{color:var(--primary)}
.admin-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--border);border:1px solid var(--border);margin-bottom:28px}
.search-row{display:flex;gap:10px;margin-bottom:16px}
.search-input{background:var(--dark3);border:1px solid var(--border2);color:var(--text);font-size:13px;padding:10px 14px;outline:none;flex:1;transition:border-color .15s;font-family:'Onest',sans-serif}
.search-input:focus{border-color:var(--primary)}
.tbl-wrap{border:1px solid var(--border);overflow:auto}
.tbl{width:100%;border-collapse:collapse}
.tbl th{font-size:9px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);font-weight:500;padding:11px 16px;border-bottom:1px solid var(--border);text-align:left;font-family:'Unbounded',sans-serif;background:var(--dark)}
.tbl td{padding:12px 16px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle;background:var(--dark)}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:var(--dark3)}
.tbl-actions{display:flex;gap:6px}
.badge-admin{font-size:9px;font-family:'Unbounded',sans-serif;padding:2px 7px;border:1px solid var(--primary);color:var(--primary);letter-spacing:.04em}
.badge-dept{font-size:9px;padding:2px 7px;border:1px solid var(--border2);color:var(--muted2)}
.tab-row{display:flex;gap:0;margin-bottom:24px;border-bottom:1px solid var(--border)}
.tab{padding:10px 20px;cursor:pointer;font-size:10px;color:var(--muted2);border-bottom:2px solid transparent;transition:color .15s;margin-bottom:-1px;font-family:'Unbounded',sans-serif;font-weight:500;letter-spacing:.06em;text-transform:uppercase}
.tab:hover{color:var(--text)}
.tab.active{color:var(--primary);border-bottom-color:var(--primary)}
.q-list{display:flex;flex-direction:column;gap:8px;margin-top:12px}
.q-item{border:1px solid var(--border);padding:14px 16px;display:flex;gap:12px;align-items:flex-start;background:var(--dark)}
.q-num{font-family:'Unbounded',sans-serif;font-size:10px;color:var(--muted);min-width:24px;padding-top:2px}
.q-body{flex:1}
.q-text-display{font-size:13px;font-weight:700;margin-bottom:6px}
.q-type-badge{font-size:9px;font-family:'Unbounded',sans-serif;padding:2px 7px;border:1px solid var(--border2);color:var(--muted);display:inline-block;margin-bottom:8px;letter-spacing:.04em}
.opt-list{display:flex;flex-direction:column;gap:4px}
.opt-item{font-size:12px;color:var(--muted2);display:flex;align-items:center;gap:8px}
.opt-correct{color:var(--ok-text);font-size:9px;font-family:'Unbounded',sans-serif;padding:1px 5px;border:1px solid var(--ok)}
#tests-content{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
#tests-content .tests-el{width:100%;border:1px solid var(--border);margin-bottom:16px;background:var(--dark)}
.tests-el .les-desc{max-width:300px}
.tests-el-con{display:flex;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border);flex-direction:column;align-items:baseline;gap:10px}
.main-el-con{display:flex;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border)}
.q-list-wr{height:400px;overflow-y:scroll}
.result-tab.active{background:var(--primary);border-color:var(--primary);color:#fff}
.result-tab.active:hover{background:var(--primary-hover);border-color:var(--primary-hover);color:#fff}
/* Correct answer selection in the question editor. */
#modal-edit-q .field > label{display:flex;align-items:center;gap:8px;white-space:nowrap;margin:0;cursor:pointer}
#modal-edit-q input[name^="eq-correct"]{appearance:none;-webkit-appearance:none;width:24px;height:24px;min-width:24px;flex:0 0 24px;padding:0;margin:0;box-sizing:border-box;display:inline-grid;place-content:center;background:var(--field-bg);border:1px solid var(--border2);cursor:pointer}
#modal-edit-q input[name^="eq-correct"]:checked{background:var(--primary);border-color:var(--primary)}
#modal-edit-q input[name^="eq-correct"]:checked::after{content:"";display:block;width:6px;height:12px;border:solid #fff;border-width:0 3px 3px 0;transform:translateY(-2px) rotate(45deg)}
#modal-edit-q input[name^="eq-correct"]:focus-visible{outline:2px solid var(--primary);outline-offset:3px}
.m-gap button{min-height:30px;}
</style>
<link rel="stylesheet" href="/assets/template.css"></head>
<body>

<?php if (!$isLoggedIn): ?>

<div style="min-height:100vh;display:flex;align-items:stretch">
  <div style="width:300px;flex-shrink:0;border-right:1px solid var(--border);display:flex;flex-direction:column;padding:40px 36px;background:var(--dark)">
    <img class="logo-img" src="/assets/logo-template.svg" alt="LMS" style="height:26px;width:auto;margin-bottom:auto">
    <div style="font-size:11px;color:var(--muted2);line-height:1.8;padding-top:40px;border-top:1px solid var(--border)">
      <strong style="display:block;font-family:'Unbounded',sans-serif;font-size:9px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--primary);margin-bottom:8px">admin panel</strong>
      Управляйте обучением, пользователями и результатами вашей команды.
    </div>
  </div>
  <div style="flex:1;display:flex;align-items:center;justify-content:center;padding:40px;background:var(--dark2)">
    <div style="width:100%;max-width:360px">
      <div class="eyebrow">администратор</div>
      <h1 class="pg-title">вход<span class="dot">.</span></h1>
      <p class="pg-sub">войди как администратор чтобы управлять системой</p>
      <?php if ($loginError): ?>
      <div style="font-size:12px;color:var(--err-text);background:var(--err-bg);border:1px solid var(--err);padding:10px 14px;margin-bottom:16px">
        <?= htmlspecialchars($loginError) ?>
      </div>
      <?php endif; ?>
      <form method="POST" action="/admin/">
        <input type="hidden" name="admin_login" value="1">
        <div class="field">
          <label>email</label>
          <input type="email" name="email" autocomplete="username" placeholder="admin@LMSagency" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
        </div>
        <div class="field" style="margin-bottom:20px">
          <label>пароль</label>
          <input type="password" name="password" autocomplete="current-password" placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn btn-full">войти →</button>
      </form>
      <div style="font-size:12px;color:var(--muted);margin-top:14px;text-align:center">
        <a href="/" style="color:var(--muted2)">← вернуться на главную</a>
      </div>
    </div>
  </div>
</div>

<?php else: ?>

<div class="admin-wrap">
<aside class="admin-sidebar">
  <div class="sb-brand" style="padding:22px 20px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;justify-content:space-between">
    <img src="/assets/logo-template.svg" class="logo-img" alt="LMS" style="height:24px;width:auto">
    <div style="font-size:9px;letter-spacing:.1em;text-transform:uppercase;color:var(--primary);font-family:'Unbounded',sans-serif">admin    <button id="admin-theme-btn" onclick="adminToggleTheme()" title="Сменить тему" style="background:none;border:1px solid var(--border2);color:var(--muted2);cursor:pointer;padding:4px 7px;font-size:13px;line-height:1">☀️</button>
  </div>
  </div>
  <nav class="sb-nav">
    <div class="nav-group-lbl" style="margin-bottom:6px;margin-top:14px">разделы</div>
    <div class="nav-item active" onclick="aNav('dashboard',this)">
      <div class="nav-left">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
        дашборд
      </div>
    </div>
    <div class="nav-item" onclick="aNav('results',this)"><div class="nav-left"><svg style="opacity: 0.4;" fill="#000000" height="14px" width="14px" version="1.1" id="Capa_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 297 297" xml:space="preserve">
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
    <div class="nav-item" onclick="aNav('users',this)">
      <div class="nav-left">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        пользователи
      </div>
      <span class="nav-badge"><?= $stats['users'] ?></span>
    </div>
    <div class="nav-item" onclick="aNav('tests',this)">
      <div class="nav-left">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        тесты
      </div>
      <span class="nav-badge"><?= $stats['tests'] ?></span>
    </div>
    
    
    
    <div class="nav-item" onclick="aNav('skilllevels',this)">
      <div class="nav-left">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        обучения
      </div>
    </div>
    <div class="nav-item" onclick="aNav('assignments',this)">
      <div class="nav-left">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 12h6m-6 4h3m-7 5h11a2 2 0 002-2V6.828a2 2 0 00-.586-1.414l-2.828-2.828A2 2 0 0015.172 2H6a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
        задания
      </div>
      <span class="nav-badge" id="assignments-admin-badge"><?= (int)$stats['assignments'] ?></span>
    </div>
    <div class="nav-item" onclick="aNav('achievements',this)">
      <div class="nav-left">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 15.5A3.5 3.5 0 1 0 12 8.5a3.5 3.5 0 0 0 0 7zm0 0V21m-4-5.5l-3 3m14-3 3 3M3 8l3 3m12-3-3 3M12 3v2"/></svg>
        достижения
      </div>
    </div>
    
    <div style="height:1px;background:var(--border);margin:10px 0"></div>
    <div class="nav-item" onclick="window.location='/'">
      <div class="nav-left">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M11 17l-5-5m0 0l5-5m-5 5h12"/></svg>
        в кабинет
      </div>
    </div>
    <div class="nav-item" onclick="doLogout()">
      <div class="nav-left">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
        выйти
      </div>
    </div>
  </nav>
  <div style="padding:14px 20px;border-top:1px solid var(--border);margin-top:auto">
    <div style="font-size:12px;font-weight:500"><?= htmlspecialchars($adminUser['first_name'] . ' ' . $adminUser['last_name']) ?></div>
    <div style="font-size:10px;color:var(--muted);margin-top:2px"><?= htmlspecialchars($adminUser['email']) ?></div>
  </div>
</aside>

<main class="admin-main">

  <!-- DASHBOARD -->
  <div class="admin-page active" id="apage-dashboard">
    <div class="admin-header">
      <div class="admin-title">дашборд<span class="dot">.</span></div>
    </div>
    <div class="admin-stats">
      <div class="stat"><div class="stat-n"><?= $stats['users'] ?></div><div class="stat-l">Пользователей</div></div>
      <div class="stat"><div class="stat-n"><?= $stats['tests'] ?></div><div class="stat-l">Тестов</div></div>
    </div>
    <div class="template-intro"><h2>Обучение вашей команды</h2><p>Создайте обучение, добавьте тест и практические задания, затем назначьте его пользователям.</p><button class="btn" onclick="aNav('skilllevels',null)">Настроить обучения →</button></div>
  </div>

  <!-- USERS -->
  <div class="admin-page" id="apage-results"><h1 class="admin-title">Результаты</h1><p class="pg-sub">Проверьте тесты и ТЗ, затем покажите результаты сотруднику.</p>
  <div class="field" style="max-width:unset;margin-bottom:18px">
      
<form class="employee-search" role="search" autocomplete="off" onsubmit="return false;">

  <div class="employee-search__field">
    <svg width="22" height="22" viewBox="0 0 24 24"
         fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round">
      <circle cx="11" cy="11" r="8"/>
      <path d="m21 21-4.35-4.35"/>
    </svg>

    <input
      type="search"
      id="results-employee-search"
      name="results_staff_filter"
      aria-label="Поиск сотрудника в результатах"
      data-1p-ignore data-lpignore="true"
      placeholder="Введите имя или фамилию..."
      autocomplete="off"
      oninput="renderResultsQueue()"
    >

    <span class="employee-search__arrow"
          aria-hidden="true">→</span>
  </div>
</form>

      </div><div id="results-queue"></div></div>
  <div class="admin-page" id="apage-users">
    <div class="admin-header">
      <div class="admin-title">пользователи<span class="dot">.</span></div>
      <button class="btn" onclick="openModal('modal-user-create')">+ добавить</button>
    </div>
    <form class="search-row" role="search" autocomplete="off" onsubmit="return false;">
      <input type="search" class="search-input" id="user-search" name="users_staff_filter" autocomplete="off" aria-label="Поиск пользователя по имени или email" data-1p-ignore data-lpignore="true" placeholder="поиск по имени или email..." oninput="searchUsers(this.value)">
    </form>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead><tr><th>сотрудник</th><th>email</th><th>Доступ</th><th>дата</th><th></th></tr></thead>
        <tbody id="users-tbody"><tr><td colspan="6" style="color:var(--muted)">загрузка...</td></tr></tbody>
      </table>
    </div>
  </div>

  <!-- TESTS -->
  <div class="admin-page" id="apage-tests">
    <div class="admin-header">
      <div class="admin-title">тесты<span class="dot">.</span></div>
      <button class="btn" onclick="openNewTestModal()">+ добавить тест</button>
    </div>
    <div class="tab-row" style="margin-bottom:10px">
      <div class="tab active" id="test-purpose-hard" onclick="switchTestPurpose('hard',this)">Тесты и задания</div>
      
    </div>
    
    <div id="tests-content"><div style="color:var(--muted)">загрузка...</div></div>
  </div>

  <!-- ONBOARDING -->
  

  <!-- STANDARDS -->
  

  <!-- ОБУЧЕНИЯ — УРОВНИ -->
  <div class="admin-page" id="apage-skilllevels">
    <div class="admin-header">
      <div class="admin-title">обучения<span class="dot">.</span></div>
      <button class="btn" onclick="openSkillLevelModal()">+ добавить уровень</button>
    </div>
    <div style="font-size:12px;color:var(--muted2);margin-bottom:16px;line-height:1.8">
      Уровни: Джун → Мидл → Синьор. К каждому уровню привязывай тесты (раздел "Тесты"). 
      Сотрудникам рандомно выпадают вопросы из банка вопросов уровня.
    </div>
    <?php if (!empty(lmsActor()['is_admin'])): ?>
    <div style="border:1px solid var(--border);padding:18px;margin-bottom:20px">
      <div style="margin-bottom:10px">импорт тестов и заданий из JSON</div>
      <button class="btn" onclick="aNav('import',null)">Открыть импорт обучений →</button>
      <div id="materials-import-info" style="font-size:12px;line-height:1.7;margin:12px 0" aria-live="polite">Выберите файл JSON. Существующие материалы будут пропущены. Время уровней не меняется.</div>
      
      
      <div style="font-size:12px;color:var(--muted2);margin-top:12px">После импорта откройте раздел «Тесты» или «Задания», чтобы увидеть материалы.</div>
    </div>
    <?php endif; ?>
    <div id="skill-levels-content"><div style="color:var(--muted)">загрузка...</div></div>
  </div>

  <!-- ЗАДАНИЯ ХАРДСКИЛЛОВ -->
  <div class="admin-page" id="apage-assignments">
    <div class="admin-header">
      <div class="admin-title">задания<span class="dot">.</span></div>
      <button class="btn" onclick="openAssignmentModal()">+ добавить задание</button>
    </div>
    <div style="font-size:12px;color:var(--muted2);margin-bottom:16px;line-height:1.8">
      Задание — это практическая работа или видео-материал для проверки хардскилла (в отличие от теста с вопросами).
      Каждое задание привязывается к уровню хардскилла и засчитывается, когда сотрудник отмечает его выполненным.
    </div>
    
    <div id="assignments-content"><div style="color:var(--muted)">загрузка...</div></div>
  </div>

  <!-- ДОСТИЖЕНИЯ -->
  <div class="admin-page" id="apage-achievements">
    <div class="admin-header">
      <div class="admin-title">достижения<span class="dot">.</span></div>
      <button class="btn" onclick="openAchModal()">+ добавить</button>
    </div>
    <div style="font-size:12px;color:var(--muted2);margin-bottom:16px;line-height:1.8">
      Ачивки выдаются автоматически при наступлении условия. Условия:
Достижение выдаётся за пройденное обучение. Название, описание и иконку можно изменить.</div>
    <div id="ach-content"><div style="color:var(--muted)">загрузка...</div></div>
  </div>

  <!-- ОБСУЖДЕНИЯ -->
  

  <!-- РАЗДЕЛЫ И УРОКИ -->
  

</main>
</div>

<!-- MODALS -->

<div class="modal-overlay" id="modal-user-create">
  <form class="modal" autocomplete="off" onsubmit="return false;">
    <div class="modal-title">новый пользователь</div>
    <div class="field-row">
      <div class="field" id="f-cu-fn"><label>имя</label><input type="text" id="cu-fn"><div class="field-err">введи имя</div></div>
      <div class="field" id="f-cu-ln"><label>фамилия</label><input type="text" id="cu-ln"><div class="field-err">введи фамилию</div></div>
    </div>
    <div class="field" id="f-cu-email"><label>email</label><input type="email" id="cu-email" autocomplete="off"><div class="field-err">введи email</div></div>
    <div class="field" id="f-cu-pass"><label>пароль</label><input type="password" id="cu-pass" autocomplete="new-password" placeholder="минимум 6 символов"><div class="field-err">мин. 6 символов</div></div>
    <div class="field-row">
      <div class="field"><label>отдел</label>
        <select id="cu-dept" onchange="fillAdminRoles('cu-dept','cu-role')">
          <option value="">—</option>
          <?php foreach ($depts as $d): ?>
          <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>должность</label><select id="cu-role"><option value="">—</option></select></div>
    </div>
    <div class="field"><label>роль в системе</label>
      <select id="cu-admin"><option value="0">сотрудник</option><option value="1">администратор</option></select><label style="margin-top: 20px;">Видимые Обучения пользователя</label><div id="cu-level"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn" onclick="createUser()">создать →</button>
      <button type="button" class="btn btn-ghost" onclick="closeModal('modal-user-create')">отмена</button>
    </div>
  </form>
</div>

<div class="modal-overlay" id="modal-user-edit">
  <form class="modal" autocomplete="off" onsubmit="return false;">
    <div class="modal-title">редактировать пользователя</div>
    <input type="hidden" id="eu-id">
    <div class="field"><label>права</label><select id="eu-access"><option value="0">сотрудник</option><option value="1">администратор</option></select><label style="margin-top: 20px;">Видимые Обучения пользователя</label><div id="eu-level"></div></div>
    <div class="field-row">
      <div class="field"><label>имя</label><input type="text" id="eu-fn"></div>
      <div class="field"><label>фамилия</label><input type="text" id="eu-ln"></div>
    </div>
    <div class="field"><label>email</label><input type="email" id="eu-email" autocomplete="off"></div>
    <div class="field"><label>новый пароль (оставь пустым — не менять)</label><input type="password" id="eu-pass" autocomplete="new-password"></div>
    <div class="field-row">
      <div class="field"><label>отдел</label>
        <select id="eu-dept" onchange="fillAdminRoles('eu-dept','eu-role')">
          <option value="">—</option>
          <?php foreach ($depts as $d): ?>
          <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>должность</label><select id="eu-role"><option value="">—</option></select></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn" onclick="updateUser()">сохранить →</button>
      <button type="button" class="btn btn-ghost" onclick="closeModal('modal-user-edit')">отмена</button>
    </div>
  </form>
</div>

<div class="modal-overlay" id="modal-test-create">
  <div class="modal" style="max-width:560px">
    <div class="modal-title" id="ct-modal-title">новый тест</div>
    <input type="hidden" id="ct-id" value="">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:end;">
      <div class="field"><label>отдел</label>
        <select id="ct-dept" onchange="fillAdminRoles('ct-dept','ct-role')">
<option value="0">для всех отделов</option>
  <?php foreach ($depts as $d): ?>
  <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
  <?php endforeach; ?>
        </select>
        
      </div>
      <div class="field">
          <div style="margin-top:8px">
          <label style="font-size:9px;color:var(--muted2);display:block;margin-bottom:6px">должность (опционально)</label>
          <select id="ct-role"><option value="">— для всех ролей —</option></select>
        </div>
      </div>
      <div class="field"><label>Обучение (обязательно для теста)</label>
        <select id="ct-skill-level">
          <option value="">— обычный тест —</option>
        </select>
      </div>
      <div class="field"><label>коины за прохождение</label>
        <input type="number" id="ct-coins" value="50" min="0">
      </div>
    </div>

    <div class="field"><label>название</label>
      <input type="text" id="ct-title" placeholder="например: обучения таргетинга">
    </div>

    <div class="field"><label>описание</label>
      <textarea id="ct-desc" rows="2" style="width:100%;background:var(--dark3);border:1px solid var(--border2);color:var(--text);padding:10px;outline:none;resize:none;font-family:'Onest',sans-serif;font-size:13px;line-height:1.5" placeholder="краткое описание для сотрудника"></textarea>
    </div>

    <div class="field">
      <label>тип контента</label>
      <div style="display:flex;gap:0;border:1px solid var(--border2)">
        <div id="ct-type-quiz" onclick="setTestType('quiz')" style="flex:1;padding:10px 16px;cursor:pointer;font-size:9px;text-align:center;background:var(--primary);color:#fff;font-family:'Unbounded',sans-serif;letter-spacing:.04em;text-transform:uppercase;transition:background .15s">тест с вопросами</div>
        <div id="ct-type-video" onclick="setTestType('video')" style="flex:1;padding:10px 16px;cursor:pointer;font-size:9px;text-align:center;background:var(--dark3);color:var(--muted2);font-family:'Unbounded',sans-serif;letter-spacing:.04em;text-transform:uppercase;transition:background .15s">видео</div>
        <div id="ct-type-task" onclick="setTestType('task')" style="flex:1;padding:10px 16px;cursor:pointer;font-size:9px;text-align:center;background:var(--dark3);color:var(--muted2);font-family:'Unbounded',sans-serif;letter-spacing:.04em;text-transform:uppercase;transition:background .15s">задание</div>
      </div>
      <input type="hidden" id="ct-content-type" value="quiz">
    </div>

    <div class="field" id="ct-question-count-block">
      <label for="ct-question-count">вопросов за одно прохождение хардскилла</label>
      <input type="number" id="ct-question-count" value="10" min="1" max="1000" step="1">
      <div class="field-hint">Случайная выборка из банка этого теста. Если вопросов меньше, будут выданы все.</div>
    </div>

    <div id="ct-video-block" style="display:none">
      <div class="field">
        <label>ссылка на видео</label>
        <input type="text" id="ct-video-url" placeholder="https://youtube.com/watch?v=... или /uploads/video.mp4">
        <div class="field-hint">YouTube, Vimeo или прямая ссылка на файл</div>
      </div>
      <div class="field">
        <label>или загрузить видеофайл</label>
        <div style="border:1px solid var(--border2);padding:12px;background:var(--dark3)">
          <input type="file" id="ct-video-file-input" accept="video/*,.mp4,.webm,.mov"
            onchange="uploadTestVideo()"
            style="font-size:12px;color:var(--muted2);width:100%;background:transparent;border:none;outline:none;margin-bottom:6px">
          <div id="ct-video-file-status" style="font-size:11px;color:var(--muted)">MP4 / WebM / MOV · до 200 MB</div>
          <input type="hidden" id="ct-video-file-url">
        </div>
      </div>
    </div>

    <div class="modal-footer">
      <button class="btn" id="ct-save-btn" onclick="createTest()">создать →</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-test-create')">отмена</button>
    </div>
  </div>
</div>







<div class="toast" id="toast"></div>
<script src="/assets/rich-editor.js"></script>
<link rel="stylesheet" href="/assets/results-dashboard.css?v=20261014-ring">
<script src="/assets/results-dashboard.js?v=<?= hash_file('sha256', __DIR__ . "/../assets/results-dashboard.js") ?>"></script>
<script src="/assets/app.js?v=20261014-errors"></script>
<script src="/assets/achievement-icons.js?v=<?= filemtime(__DIR__ . "/../assets/achievement-icons.js") ?>"></script>
<div class="modal-overlay" id="modal-employee-levels"><div class="modal">
  <div class="modal-title">Видимые Обучения пользователя</div>
  <div id="employee-levels-name"></div><input type="hidden" id="employee-levels-id">
  <div class="field" id="employee-levels-picker" style="margin-top:16px"></div>
  <div class="field-hint">Можно выбрать несколько уровней. Если снять все отметки, уровни будут скрыты.</div>
  <div class="modal-footer"><button class="btn" id="employee-levels-save" onclick="saveEmployeeLevels()">сохранить</button><button class="btn btn-ghost" onclick="closeModal('modal-employee-levels')">отмена</button></div>
</div></div>
<script>
const DEPTS = <?= $deptsJson ?>;

// Превращаем текстовые поля с HTML-содержанием в WYSIWYG-редактор



const ADMIN_ACTOR_ID = <?= (int)($adminUser['id'] ?? 0) ?>;
const ADMIN_ACTOR_ADMIN = <?= !empty($adminUser['is_admin']) ? 'true' : 'false' ?>;
const ADMIN_ACTOR_DEPT = <?= (int)($adminUser['dept_id'] ?? 0) ?>;
let checkingAdminSession=false;
window.addEventListener('lms-access-denied',async()=>{
  if(checkingAdminSession)return;
  checkingAdminSession=true;
  try {
    const response=await fetch('/api/auth.php?action=me',{credentials:'include'});
    const res=await response.json(),u=res.user;
    if(!res.ok || !u || Number(u.id)!==ADMIN_ACTOR_ID || Boolean(Number(u.is_admin))!==ADMIN_ACTOR_ADMIN || Number(u.dept_id)!==ADMIN_ACTOR_DEPT || (!Number(u.is_admin)&&!Number(u.is_manager))){
      toast(u && !Number(u.is_admin) && !Number(u.is_manager) ? 'Вы вошли как сотрудник. Для панели войдите как администратор или руководитель.' : 'Аккаунт или права изменились. Обновляем панель.','err');
      window.location.replace('/admin/');
    }
  }catch(e){toast('Не удалось проверить текущий аккаунт. Обновите страницу.','err');}
  finally{checkingAdminSession=false;}
});
const MANAGER_ONLY = <?= !empty($adminUser['is_manager']) && empty($adminUser['is_admin']) ? 'true' : 'false' ?>;
function aNav(id,el){
  if(MANAGER_ONLY && !['users','results','tests','assignments','chat'].includes(id))return;
  document.querySelectorAll('.admin-page').forEach(p=>p.classList.remove('active'));
  document.getElementById('apage-'+id).classList.add('active');
  document.querySelectorAll('.admin-sidebar .nav-item').forEach(n=>n.classList.remove('active'));
  if(el)el.classList.add('active');
  try { localStorage.setItem('lms_admin_last_page', id); } catch(e){}
  if(id==='users')loadUsers();
  if(id==='results')loadResultsQueue();
  if(id==='tests'){loadTests(curTestDept);loadSkillLevelsAdmin();}



  if(id==='skilllevels')loadSkillLevelsAdmin();
  if(id==='assignments'){loadSkillLevelsAdmin();loadAssignments();}
  if(id==='achievements')loadAchievements();

}
async function doLogout(){
  await fetch('/api/auth.php?action=logout',{method:'POST'});
  window.location='/';
}

// USERS
function userListAvatar(u){
  const letters=((u.first_name||'').trim().slice(0,1)+(u.last_name||'').trim().slice(0,1)).toUpperCase()||'—';
  // Загруженные аватары сайта; другие URL в HTML не подставляем.
  const path=String(u.avatar||'').trim().replace(/^storage\//, '/storage/');
  const image=/^\/storage\/uploads\/[^/\\?#]+$/u.test(path)
    ? '<img src="'+esc(path)+'" alt="" loading="lazy" decoding="async" width="30" height="30" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" onerror="this.remove()">' : '';
  return '<span aria-hidden="true" style="position:relative;display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;flex-shrink:0;border-radius:50%;overflow:hidden;background:var(--border);color:var(--muted2);font-size:10px;font-weight:700">'+esc(letters)+image+'</span>';
}
async function loadUsers(search=''){
  const res=await api.get('/api/users.php?search='+encodeURIComponent(search));
  if(!res.ok){document.getElementById('users-tbody').innerHTML='<tr><td colspan="6">'+esc(res.error||'Не удалось загрузить сотрудников')+'</td></tr>';toast(res.error||'Не удалось загрузить сотрудников','err');return;}
  const tbody=document.getElementById('users-tbody');
  if(!res.users.length){tbody.innerHTML='<tr><td colspan="6" style="color:var(--muted)">пользователей нет</td></tr>';return;}
  tbody.innerHTML=res.users.map(u=>`<tr>
    <td><div style="display:flex;align-items:center;gap:8px">${userListAvatar(u)}<div><strong>${esc(u.first_name+' '+u.last_name)}</strong>${u.is_admin?' <span class="badge-admin">admin</span>':(u.is_manager?' <span class="badge-admin">руководитель</span>':'')}</div></div></td>
    <td style="color:var(--muted2)">${esc(u.email)}</td>
    <td>${u.is_admin?'Администратор':'Пользователь'}</td>
    <td style="color:var(--muted)">${new Date(u.created_at).toLocaleDateString('ru-RU')}</td>
    <td><div class="tbl-actions">
      <button class="btn btn-ghost" style="padding:5px 12px;font-size:9px" onclick="openUserResults(${u.id},'${esc((u.first_name+' '+u.last_name).replace(/'/g,''))}')">результаты</button>
${MANAGER_ONLY?`<button class="btn btn-ghost" style="padding:5px 12px;font-size:9px" onclick="openEmployeeLevels(${u.id})">уровни</button>`:`<button class="btn btn-ghost" style="padding:5px 12px;font-size:9px" onclick="openEditUser(${u.id})">редакт.</button><button class="btn btn-danger" style="padding:5px 12px;font-size:9px" onclick="deleteUser(${u.id})">удалить</button>`}
    </div></td>
  </tr>`).join('');
}
let _st;
function searchUsers(v){clearTimeout(_st);_st=setTimeout(()=>loadUsers(v),300);}

// Собирает HTML со списком открытых вопросов / «своих вариантов ответа» из answers_json
function answerWithFiles(value){
  const text=String(value ?? '');
  const pattern=/https?:\/\/[^\s<>"']+|\/storage\/uploads\/[^\s<>"']+|\/api\/download_attachment\.php\?[^\s<>"']+/g;
  let html='',last=0;
  for(const match of text.matchAll(pattern)){
    html+=esc(text.slice(last,match.index));
    let url;
    try{url=new URL(match[0],window.location.origin);}catch(e){}
    if(url && ['http:','https:'].includes(url.protocol) && (url.pathname.startsWith('/storage/uploads/') || (url.hostname===window.location.hostname && url.pathname==='/api/download_attachment.php'))){
      // Legacy HTTP links on this host must use the current page protocol.
      if(url.hostname===window.location.hostname){url=new URL(url.pathname+url.search+url.hash,window.location.origin);}
      let href=url.href;
      if(url.origin===window.location.origin && /\.txt$/i.test(url.pathname)){
        try{href='/api/text_attachment.php?file='+encodeURIComponent(decodeURIComponent(url.pathname.split('/').pop()));}catch(e){}
      }
      html+='<a href="'+esc(href)+'" target="_blank" rel="noopener noreferrer" style="color:var(--primary);text-decoration:underline">Открыть файл ↗</a>';
    }else html+=esc(match[0]);
    last=match.index+match[0].length;
  }
  return html+esc(text.slice(last));
}
function renderOpenAnswers(answers,resultId,kind,userId,userName){
  if(!answers) return '';
  const items = [];
  Object.keys(answers).forEach(function(qid){
    const a = answers[qid];
    if(!a) return;
    if(a.type === 'open' && a.answer){
      items.push({ q: a.question_text || ('вопрос #'+qid), text: a.answer, label: 'открытый ответ', qid, accepted:a.accepted });
    }
    if(a.own_answer){
      items.push({ q: a.question_text || ('вопрос #'+qid), text: a.own_answer, label: 'свой вариант ответа' });
    }
  });
  if(!items.length) return '';
  return '<div style="margin-top:8px;border-top:1px solid var(--border);padding-top:8px;display:flex;flex-direction:column;gap:8px">'
    + items.map(function(it){
        return '<div><div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">' + esc(it.label) + ' · ' + esc(it.q) + '</div>'
          + '<div style="font-size:12px;color:var(--text);white-space:pre-wrap">' + answerWithFiles(it.text) + '</div>'
          + (it.qid && resultId ? '<div style="display:flex;gap:8px;margin-top:8px"><button class="btn btn-ghost" data-review-kind="'+kind+'" data-review-id="'+resultId+'" data-review-q="'+it.qid+'" data-review-user="'+userId+'" style="'+(it.accepted===true?'background:#205b38;color:white':'')+'" onclick="reviewOpenAnswer(this,true)">Верно'+(it.accepted===true?' ✓':'')+'</button><button class="btn btn-ghost" data-review-kind="'+kind+'" data-review-id="'+resultId+'" data-review-q="'+it.qid+'" data-review-user="'+userId+'" style="'+(it.accepted===false?'background:#722b32;color:white':'')+'" onclick="reviewOpenAnswer(this,false)">Неверно'+(it.accepted===false?' ✓':'')+'</button></div>' : '')+'</div>';
      }).join('')
    + '</div>';
}


function renderClosedLevelAnswers(answers){
  return Object.values(answers||{}).filter(a=>a && a.type && a.type!=='open').map(a=>
    '<div style="border-top:1px solid var(--border);padding:10px 0;margin-top:8px"><strong>'+esc(a.question_text||'Вопрос')+'</strong><div>Ответ: '+esc(a.answer_text||'Не отвечено')+'</div><div style="color:'+(a.is_correct?'var(--ok-text)':'var(--err-text)')+'">'+(a.is_correct?'Верно':'Неверно')+'</div></div>'
  ).join('');
}

function showResultTab(key){
  document.querySelectorAll('#ur-content [data-result-panel]').forEach(el=>el.style.display=el.dataset.resultPanel===key?'':'none');
  document.querySelectorAll('#ur-content [data-result-tab]').forEach(el=>{
    const active=el.dataset.resultTab===key;
    el.classList.toggle('active',active);
    el.setAttribute('aria-pressed',String(active));
  });
}
async function reviewOpenAnswer(button,accepted){
  const group=button.parentElement;
  group.querySelectorAll('button').forEach(b=>b.disabled=true);
  try {
    const res=await api.post('/api/reviews.php',{kind:button.dataset.reviewKind,id:Number(button.dataset.reviewId),question_id:Number(button.dataset.reviewQ),accepted});
    if(!res.ok){toast(res.error||'Ошибка проверки','err');return;}
    group.querySelectorAll('button').forEach(b=>{const selected=b===button;b.style.background=selected?(accepted?'#205b38':'#722b32'):'';b.style.color=selected?'#fff':'';b.setAttribute('aria-pressed',String(selected));});
    if(group.dataset.taskReview){const status=group.querySelector('[data-task-status]');status.textContent=accepted?'Пройдено ✓':'Не пройдено';status.style.color=accepted?'var(--ok-text)':'var(--err-text)';const badge=document.getElementById('task-result-badge-'+button.dataset.reviewId);if(badge){badge.textContent=status.textContent;badge.classList.toggle('tdone',accepted);}}
    toast('Оценка сохранена');
  } catch(e){toast('Не удалось сохранить оценку','err');}
  finally {group.querySelectorAll('button').forEach(b=>b.disabled=false);}

}
function renderTaskReview(r,userId,kind='test'){
  const verdict=r.answers?.verdict?.accepted;
  return '<div data-task-review="true" style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap"><div data-task-status style="width:100%;color:'+(verdict===true?'var(--ok-text)':verdict===false?'var(--err-text)':'var(--muted2)')+'">'+(verdict===true?'Пройдено ✓':verdict===false?'Не пройдено':'Ожидает проверки руководителя')+'</div>'+[true,false].map(accepted=>'<button class="btn btn-ghost" style="'+(verdict===accepted?'background:'+(accepted?'#205b38':'#722b32')+';color:#fff':'')+'" data-review-kind="'+kind+'" data-review-id="'+r.id+'" data-review-user="'+userId+'" onclick="reviewOpenAnswer(this,'+accepted+')">'+(accepted?'Пройдено':'Не пройдено')+'</button>').join('')+'</div>';
}
function renderOnboardingTestResults(rows,userId,kind='test'){
  return rows.map(r=>'<div style="border:1px solid var(--border);padding:12px;margin-bottom:10px"><strong>'+esc(r.test_title)+'</strong><div>'+(r.review_status==='pending'?'на рассмотрении':r.score+'/'+r.max_score)+'</div>'+(r.content_type==='task'?'<div style="white-space:pre-wrap;margin-top:8px">'+answerWithFiles(r.answers?.comment||r.answers?.details||'')+'</div>':'')+renderOpenAnswers(r.answers,r.id,kind,userId,'')+(r.content_type==='task'?renderTaskReview(r,userId):'')+'</div>').join('');
}

function formatDuration(seconds){
  seconds=Math.max(0,Number(seconds)||0);
  const h=Math.floor(seconds/3600);
  const m=Math.floor((seconds%3600)/60);
  const s=Math.floor(seconds%60);
  return (h?h+' ч ':'')+m+' мин '+s+' сек';
}

// Результаты тестов/заданий/обучений конкретного сотрудника
let resultsQueueUsers=[];
function normalizeEmployeeSearch(value){
  const letters={а:'a',б:'b',в:'v',г:'g',д:'d',е:'e',ё:'e',ж:'zh',з:'z',и:'i',й:'y',к:'k',л:'l',м:'m',н:'n',о:'o',п:'p',р:'r',с:'s',т:'t',у:'u',ф:'f',х:'kh',ц:'ts',ч:'ch',ш:'sh',щ:'shch',ъ:'',ы:'y',ь:'',э:'e',ю:'yu',я:'ya',ә:'a',ғ:'g',қ:'k',ң:'n',ө:'o',ұ:'u',ү:'u',һ:'h',і:'i'};
  return String(value||'').normalize('NFC').toLocaleLowerCase('ru-RU')
    .replace(/[а-яёәғқңөұүһі]/g,letter=>letters[letter])
    .normalize('NFD').replace(/[\u0300-\u036f]/g,'').trim();
}
function resultVisibilityHtml(publishedAt){
  return '<div style="margin:10px 0;color:'+(publishedAt?'var(--ok-text)':'var(--muted2)')+'"><strong>'+(publishedAt?'Результаты открыты сотруднику':'Результаты скрыты от сотрудника')+'</strong>'+(publishedAt?'<div style="font-size:12px">Опубликовано: '+esc(publishedAt)+'</div>':'')+'</div>';
}
function renderResultsQueue(){
  const el=document.getElementById('results-queue');
  const normalize=normalizeEmployeeSearch;
  const terms=normalize(document.getElementById('results-employee-search').value).split(/\s+/).filter(Boolean);
  const available=resultsQueueUsers.filter(u=>u.count||u.hard_skill_attempts_count);
  const users=available.filter(u=>{const name=normalize(u.first_name+' '+u.last_name);return terms.every(term=>name.includes(term));});
  el.innerHTML=users.map(u=>'<div style="border:1px solid var(--border);padding:18px;margin:12px 0"><strong>'+esc(u.first_name+' '+u.last_name)+'</strong>'+resultVisibilityHtml(u.published_at)+'<div>Результатов: '+u.count+' · Попыток обучений: '+(u.hard_skill_attempts_count||0)+' · Ожидают проверки: '+u.pending+'</div>'+ResultsDashboard.render(u.summary||[],{details:true,answerLabel:"Ответ сотрудника"})+'<div>'+(u.published_at?'Последняя публикация: '+esc(u.published_at):'Ещё не опубликованы')+'</div><button class="btn" data-employee="'+u.id+'">Проверить ответы</button></div>').join('')||(available.length?'Сотрудники не найдены':'Пока нет ответов для проверки');
  el.querySelectorAll('[data-employee]').forEach(b=>b.onclick=()=>{const u=users.find(u=>Number(u.id)===Number(b.dataset.employee));openUserResults(Number(u.id),u.first_name+' '+u.last_name,'hard');});
}
async function loadResultsQueue(){
  const el=document.getElementById('results-queue');el.textContent='Загрузка…';
  try{
    const res=await api.get('/api/results.php?action=queue');
    if(!res.ok){el.textContent=res.error||'Ошибка';return;}
    resultsQueueUsers=res.users||[];renderResultsQueue();
  }catch(e){el.textContent='Не удалось загрузить результаты';}
}
async function publishEmployeeResults(button,id){
  button.disabled=true;
  try{const r=await api.post('/api/results.php?action=publish',{user_id:id});if(!r.ok){toast(r.error||'Ошибка публикации','err');return;}toast('Результаты доступны сотруднику');loadResultsQueue();const status=document.getElementById('ur-visibility');if(status && Number(status.dataset.userId)===Number(id)){const fresh=await api.get('/api/users.php?action=results&id='+id);if(fresh.ok && status.isConnected)status.innerHTML=resultVisibilityHtml(fresh.published_at);}}
  catch(e){toast('Не удалось опубликовать результаты','err');}finally{button.disabled=false;}
}
async function reopenAssignment(button,userId,attemptId,testId){
  const value=prompt('На сколько минут открыть ТЗ для ответа?','60');
  if(value===null)return;
  const minutes=Number(value);
  if(!Number.isInteger(minutes)||minutes<1||minutes>10080){toast('Укажите от 1 до 10080 минут','err');return;}
  button.disabled=true;
  try{
    const r=await api.post('/api/reopen_assignment.php',{user_id:userId,attempt_id:attemptId,test_id:testId,minutes});
    if(!r.ok){toast(r.error||'Не удалось открыть ТЗ','err');return;}
    toast('ТЗ открыто для ответа. Сотруднику нужно заново открыть уровень');
    const name=document.querySelector('#modal-user-results .modal-title').textContent.replace(/^результаты — /,'');
    await openUserResults(userId,name,'hard');
    loadResultsQueue();
  }catch(e){toast('Не удалось открыть ТЗ','err');}finally{button.disabled=false;}
}
async function openUserResults(id, name, initialTab='hard'){
  document.getElementById('modal-user-results')?.remove();
  document.body.insertAdjacentHTML('beforeend', `<div class="modal-overlay open" id="modal-user-results">
    <div class="modal" style="max-width:680px;max-height:85vh;overflow-y:auto">
      <div class="modal-title">результаты — ${esc(name)}</div>
      <div id="ur-visibility" data-user-id="${id}"></div>
      <div id="ur-content"><div style="color:var(--muted)">загрузка...</div></div>
      <div class="modal-footer">
        <button class="btn" onclick="publishEmployeeResults(this,${id})">Показать сотруднику результаты</button>
        <button class="btn btn-ghost" onclick="document.getElementById('modal-user-results').remove()">закрыть</button>
      </div>
    </div>
  </div>`);

  const res = await api.get('/api/users.php?action=results&id=' + id);
  const c = document.getElementById('ur-content');
  if (!res.ok) { c.innerHTML = '<div style="color:var(--err-text)">' + esc(res.error || 'ошибка загрузки') + '</div>'; return; }

  document.getElementById('ur-visibility').innerHTML=resultVisibilityHtml(res.published_at);
  let html = '<div data-result-panel="hard">';

  // Серверные попытки уровня: таймер, фактическая длительность и выданные ТЗ.
  html += '<div style="font-size:10px;font-family:Unbounded,sans-serif;letter-spacing:.1em;text-transform:uppercase;color:var(--primary);margin-bottom:10px">История попыток</div>';
  if(!(res.hard_skill_attempts||[]).length){
    html += '<div style="color:var(--muted);font-size:12px;margin-bottom:22px">попыток не было</div>';
  } else {
    const statusLabels={active:'идёт сейчас',completed:'завершена',expired:'время истекло'};
    html += '<div style="display:flex;flex-direction:column;gap:8px;margin-bottom:22px">'+res.hard_skill_attempts.map(function(a){
      const tasks=(a.assignments||[]).map(x=>esc(x.title)).join(', ')||'без ТЗ';
      const reopen=(a.assignments||[]).filter(x=>x.can_reopen).map(x=>'<button class="btn btn-ghost" onclick="reopenAssignment(this,'+id+','+a.id+','+x.id+')">Открыть ТЗ повторно: '+esc(x.title)+'</button>').join('');
      const end=a.completed_at||a.expires_at;
      return '<div style="border:1px solid var(--border);padding:11px 14px"><div style="display:flex;justify-content:space-between;gap:10px"><strong style="font-size:13px">'+esc(a.skill_level_title)+'</strong><span style="font-size:10px;color:'+(a.status==='completed'?'var(--ok-text)':a.status==='expired'?'var(--err-text)':'var(--primary)')+'">'+esc(statusLabels[a.status]||a.status)+'</span></div><div style="font-size:11px;color:var(--muted2);margin-top:5px">Начало: '+new Date(a.started_at).toLocaleString('ru-RU')+'<br>Окончание: '+(a.status==='active'?'—':new Date(end).toLocaleString('ru-RU'))+'<br>Затрачено: '+formatDuration(a.duration_seconds)+'<br>Вопросов выдано: '+(a.question_ids||[]).length+'<br>ТЗ: '+tasks+reopen+(a.drafts||[]).filter(d=>String(d.comment||'').trim()).map(d=>'<div style="white-space:pre-wrap;padding:10px;border:1px solid var(--border)"><strong>Сохранённый ответ: '+esc(d.title)+'</strong><br>'+answerWithFiles(d.comment)+'</div>').join('')+'</div></div>';
    }).join('')+'</div>';
  }

  // Хардскилл-уровни (случайная подборка вопросов из банка уровня)
  html += '<div style="font-size:10px;font-family:Unbounded,sans-serif;letter-spacing:.1em;text-transform:uppercase;color:var(--primary);margin-bottom:10px">Результаты тестов</div>';
  if (!res.level_results.length) {
    html += '<div style="color:var(--muted);font-size:12px;margin-bottom:22px">Тест уровня не отправлен. Историю попыток смотрите выше.</div>';
  } else {
    html += '<div style="display:flex;flex-direction:column;gap:8px;margin-bottom:22px">';
    res.level_results.forEach(function(r){
      const pct = r.max_score ? Math.round(r.score / r.max_score * 100) : 0;
      const badge = r.passed == 1 ? '<span class="tag tdone">сдано ✓</span>' : '<span class="tag">не сдано</span>';
      html += '<div style="border:1px solid var(--border);padding:10px 14px">'
        + '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px">'
        + '<div><div style="font-size:13px;font-weight:700">' + esc(r.skill_level_title || '—') + '</div>'
        + '<div style="font-size:11px;color:var(--muted)">' + new Date(r.completed_at).toLocaleString('ru-RU') + '</div></div>'
        + '<div style="text-align:right;flex-shrink:0"><div style="font-size:13px;color:var(--primary);font-family:Unbounded,sans-serif;margin-bottom:4px">' + (r.review_status==='pending'?'на рассмотрении':r.score + '/' + r.max_score + ' (' + pct + '%)') + '</div>' + badge + '</div>'
        + '</div>'
        + renderClosedLevelAnswers(r.answers)
        + renderOpenAnswers(r.answers,r.id,'level',id,name)
        + renderTaskReview(r,id,'level')
        + '</div>';
    });
    html += '</div>';
  }

  // Тесты, задания (ТЗ), видео-материалы — всё что попадает в test_results
  html += '<div style="font-size:10px;font-family:Unbounded,sans-serif;letter-spacing:.1em;text-transform:uppercase;color:var(--primary);margin-bottom:10px">тесты и задания</div>';
  if (!res.test_results.length) {
    html += '<div style="color:var(--muted);font-size:12px">попыток не было</div>';
  } else {
    html += '<div style="display:flex;flex-direction:column;gap:8px">';
    res.test_results.filter(r=>!Number(r.onboarding_step_id)).forEach(function(r){
      const isQuiz = r.content_type === 'quiz';
      const typeLabel = isQuiz ? 'тест' : (r.content_type === 'video' ? 'видео' : 'техническое задание');
      const badge = r.content_type==='task' ? '<span id="task-result-badge-'+r.id+'" class="tag '+(r.answers?.verdict?.accepted===true?'tdone':'')+'">'+(r.answers?.verdict?.accepted===true?'пройдено ✓':r.answers?.verdict?.accepted===false?'не пройдено':'ожидает проверки')+'</span>' : (r.passed == 1 ? '<span class="tag tdone">пройдено ✓</span>' : '<span class="tag">не пройдено</span>');
      const scoreHtml = isQuiz ? '<div style="font-size:13px;color:var(--primary);font-family:Unbounded,sans-serif;margin-bottom:4px">' + (r.review_status==='pending'?'на рассмотрении':r.score + '/' + r.max_score) + '</div>' : '';
      const comment = r.answers && (r.answers.comment || r.answers.details);
      const answerHtml = (comment
        ? '<div style="margin-top:8px;font-size:12px;color:var(--text);white-space:pre-wrap;border-top:1px solid var(--border);padding-top:8px"><div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px">ответ сотрудника</div>' + answerWithFiles(comment) + '</div>'
        : '') + renderOpenAnswers(r.answers,r.id,'test',id,name);
      html += '<div style="border:1px solid var(--border);padding:10px 14px">'
        + '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">'
        + '<div><div style="font-size:13px;font-weight:700">' + esc(r.test_title || '—') + '</div>'
        + '<div style="font-size:11px;color:var(--muted)">' + typeLabel + (r.skill_level_title ? ' · ' + esc(r.skill_level_title) : '') + ' · ' + new Date(r.completed_at).toLocaleString('ru-RU') + '</div></div>'
        + '<div style="text-align:right;flex-shrink:0">' + scoreHtml + badge + '</div>'
        + '</div>'
        + answerHtml + (r.content_type==='task'?renderTaskReview(r,id):'')
        + '</div>';
    });
    html += '</div>';
  }

  c.innerHTML = html+'</div>';
  showResultTab(initialTab);
}

async function fillUserLevels(id,selected=[]){
  const res=await api.get('/api/skill_levels.php?action=visibility_levels');
  if(!res.ok)throw new Error(res.error||'Не удалось загрузить уровни');
  const all=selected===null;
  document.getElementById(id).innerHTML='<label style="display:flex;align-items:center;gap:10px"><input type="checkbox" data-level-all '+(all?'checked':'')+' onchange="toggleAllUserLevels(this)">Все уровни</label>'+(res.levels||[]).map(l=>'<label style="display:flex;align-items:center;gap:10px"><input type="checkbox" data-level-id="'+l.id+'" '+(all||selected.map(Number).includes(Number(l.id))?'checked':'')+' onchange="toggleOneUserLevel(this)">'+esc(l.title)+'</label>').join('');
  document.getElementById(id).setAttribute('data-level-picker','');
}
function toggleOneUserLevel(input){input.closest('[data-level-picker]').querySelector('[data-level-all]').checked=false;}
function toggleAllUserLevels(input){input.parentElement.parentElement.querySelectorAll('[data-level-id]').forEach(c=>c.checked=input.checked);}
function selectedUserLevels(id){const el=document.getElementById(id);return el.querySelector('[data-level-all]').checked?null:[...el.querySelectorAll('[data-level-id]:checked')].map(c=>Number(c.dataset.levelId));}
async function openEmployeeLevels(id){
  try {
    const res=await api.get('/api/users.php?id='+id);
    if(!res.ok){toast(res.error||'Нет доступа','err');return;}
    document.getElementById('employee-levels-id').value=id;
    document.getElementById('employee-levels-name').textContent=res.user.first_name+' '+res.user.last_name;
    await fillUserLevels('employee-levels-picker',res.user.visible_skill_level_ids);
    openModal('modal-employee-levels');
  }catch(e){toast(e.message||'Ошибка загрузки','err');}
}
async function saveEmployeeLevels(){
  const button=document.getElementById('employee-levels-save');button.disabled=true;
  try {
    const res=await api.put('/api/users.php?action=levels&id='+document.getElementById('employee-levels-id').value,{visible_skill_level_ids:selectedUserLevels('employee-levels-picker')});
    if(!res.ok){toast(res.error||'Не удалось сохранить','err');return;}
    toast('Видимые уровни сохранены');closeModal('modal-employee-levels');
  }catch(e){toast('Ошибка сохранения','err');}finally{button.disabled=false;}
}
async function createUser(){
  const data={first_name:document.getElementById('cu-fn').value.trim(),last_name:document.getElementById('cu-ln').value.trim(),email:document.getElementById('cu-email').value.trim(),password:document.getElementById('cu-pass').value,dept_id:document.getElementById('cu-dept').value,role_id:document.getElementById('cu-role').value,is_admin:document.getElementById('cu-admin').value};
  if(!data.first_name||!data.email||!data.password){toast('Заполни обязательные поля','err');return;}
  data.visible_skill_level_ids=selectedUserLevels('cu-level');
  data.is_manager=data.is_admin==='2'?1:0;data.is_admin=data.is_admin==='1'?1:0;
  const res=await api.post('/api/users.php',data);
  if(!res.ok){toast(res.error||'Ошибка','err');return;}
  toast('Пользователь создан');closeModal('modal-user-create');loadUsers();
}
async function openEditUser(id){
  try {
  const res=await api.get('/api/users.php?id='+id);if(!res.ok){toast(res.error||'Нет доступа','err');return;}
  const u=res.user;
  document.getElementById('eu-id').value=u.id;
  document.getElementById('eu-access').value=u.is_admin==1?'1':(u.is_manager==1?'2':'0');
  document.getElementById('eu-fn').value=u.first_name;
  document.getElementById('eu-ln').value=u.last_name;
  document.getElementById('eu-email').value=u.email;
  document.getElementById('eu-pass').value='';
  document.getElementById('eu-dept').value=u.dept_id||'';
  await fillAdminRoles('eu-dept','eu-role');
  document.getElementById('eu-role').value=u.role_id||'';
  await fillUserLevels('eu-level',u.visible_skill_level_ids);
  openModal('modal-user-edit');
  }catch(e){toast(e.message||'Не удалось открыть сотрудника','err');}
}
async function updateUser(){
  const id=document.getElementById('eu-id').value;
  const data={first_name:document.getElementById('eu-fn').value.trim(),last_name:document.getElementById('eu-ln').value.trim(),email:document.getElementById('eu-email').value.trim(),dept_id:document.getElementById('eu-dept').value,role_id:document.getElementById('eu-role').value};
  data.visible_skill_level_ids=selectedUserLevels('eu-level');
  data.is_admin=document.getElementById('eu-access').value==='1'?1:0;data.is_manager=document.getElementById('eu-access').value==='2'?1:0;
  const pass=document.getElementById('eu-pass').value;if(pass)data.password=pass;
  const res=await api.put('/api/users.php?id='+id,data);
  if(!res.ok){toast(res.error||'Ошибка','err');return;}
  toast('Сохранено');closeModal('modal-user-edit');loadUsers();
}
async function deleteUser(id){
  if(!confirm('Удалить пользователя?'))return;
  const res=await api.delete('/api/users.php?id='+id);
  if(!res.ok){toast(res.error||'Ошибка','err');return;}
  toast('Удалено');loadUsers();
}
async function fillAdminRoles(deptSelId,roleSelId){
  const deptId=document.getElementById(deptSelId).value;
  const res=await api.get('/api/departments.php');
  const d=res.departments?.find(d=>d.id==deptId);
  const sel=document.getElementById(roleSelId);
  if(String(deptId) === '0' || !d){
    sel.innerHTML='<option value="">— для всех ролей —</option>';
    sel.disabled = true;
    return;
  }
  sel.disabled = false;
  sel.innerHTML='<option value="">— выбери должность —</option>';
  if(['cu-role','eu-role'].includes(roleSelId)){sel.innerHTML='<option value="">Без должности / руководитель отдела (без обучения)</option>';if(roleSelId==='cu-role'&&!document.getElementById('cu-level').querySelector('[data-level-all]'))fillUserLevels('cu-level').catch(e=>toast(e.message,'err'));}
  (d.roles||[]).forEach(r=>{const o=document.createElement('option');o.value=r.id;o.textContent=r.name;sel.appendChild(o);});
}

// TESTS
let curTestDept=Number(DEPTS?.[0]?.id)||1;
let curTestPurpose='hard';
async function openNewTestModal(){
  if(curTestPurpose==='onboarding'){toast('Создайте тест в разделе «Онбординг» внутри шага — так он будет привязан к обучению','err');return;}
  await loadSkillLevelsAdmin();
  document.getElementById('ct-dept').value=curTestDept;
  await fillAdminRoles('ct-dept','ct-role');
  document.getElementById('ct-role').value='1';
  document.getElementById('ct-id').dataset.onboarding='0';
  document.getElementById('ct-question-count').value=10;
  document.getElementById('ct-id').value='';
  document.getElementById('ct-modal-title').textContent='новый тест';
  document.getElementById('ct-save-btn').textContent='создать →';
  document.getElementById('ct-title').value='';
  document.getElementById('ct-desc').value='';
  document.getElementById('ct-video-url').value='';
  document.getElementById('ct-skill-level').value='';
  setTestType('quiz');
  openModal('modal-test-create');
}
function switchTestPurpose(purpose,el){
  curTestPurpose=purpose;
  document.getElementById('test-purpose-hard').classList.remove('active');
  
  el.classList.add('active');
  loadTests(curTestDept);
}
function switchTestTab(id,el){curTestDept=id;document.querySelectorAll('#apage-tests .tab').forEach(t=>{if(!t.id.startsWith('test-purpose-'))t.classList.remove('active')});el.classList.add('active');loadTests(id);}
let testsLoadVersion=0;
async function loadTests(deptId){
  const version=++testsLoadVersion;
  const purpose=curTestPurpose;
  const c=document.getElementById('tests-content');
  c.innerHTML='<div style="color:var(--muted);padding:20px">загрузка тестов...</div>';
  let res;
  try {
    res=await api.get('/api/tests.php?dept_id='+deptId+'&onboarding='+(purpose==='onboarding'?1:0)+'&include_questions=1');
  } catch(e) {
    if(version===testsLoadVersion)c.innerHTML='<div style="color:var(--muted);padding:20px">Не удалось загрузить тесты. Попробуйте выбрать вкладку ещё раз.</div>';
    return;
  }
  if(version!==testsLoadVersion)return;
  if(!res.ok){c.innerHTML='<div style="color:var(--muted);padding:20px">'+esc(res.error||'Не удалось загрузить тесты.')+(res.diagnostic?'<br>'+esc(res.diagnostic):'')+'</div>';return;}
  // Видео/задания живут в разделе «Задание» — здесь показываем только тесты с вопросами
  const tests=(res.tests||[]).filter(t=>t.content_type==='quiz' && (curTestPurpose==='onboarding' ? Number(t.onboarding_step_id)>0 : !Number(t.onboarding_step_id)));
  if(!res.ok||!tests.length){c.innerHTML='<div style="color:var(--muted);padding:20px">тестов нет — добавь первый</div>';return;}
  let html='';
  for(const t of tests){
    const qs=t.questions||[];
    const isVideo=t.content_type==='video';
    const isTask=t.content_type==='task';
    const isQuiz=!isVideo&&!isTask;
    const typeLabel=isVideo?'видео':isTask?'задание':'тест';
    const typeColor=isQuiz?'var(--border2)':'var(--primary)';
    const typeTextColor=isQuiz?'var(--muted2)':'var(--primary)';
    html+=`<div class="tests-el" style="border:1px solid var(--border);margin-bottom:16px;background:var(--dark)">
      <div class="tests-el-con">
        <div style="display:flex;align-items:center;gap:10px">
          <div>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="font-size:14px;font-weight:700">${esc(t.title)}</div>
              <span style="font-size:9px;font-family:'Unbounded',sans-serif;padding:2px 7px;border:1px solid ${typeColor};color:${typeTextColor};letter-spacing:.04em;text-transform:uppercase">${typeLabel}</span>
              ${t.skill_level_title?`<span style="font-size:9px;font-family:'Unbounded',sans-serif;padding:2px 7px;border:1px solid var(--ok);color:var(--ok-text);letter-spacing:.04em;text-transform:uppercase">${esc(t.skill_level_title)}</span>`:''}
            </div>
            <div class="les-desc" style="font-size:11px;color:var(--muted);margin-top:3px">${esc(t.description||'')} · ${t.coins_reward} коинов</div>
            ${!Number(t.onboarding_step_id)&&(!Number(t.role_id)||!Number(t.skill_level_id)||!Number(t.dept_id))?'<div style="font-size:12px;color:var(--err-text);margin-top:8px">Не заполнены отдел, должность или уровень. Нажмите «редакт.» и задайте привязку.</div>':''}
            ${!isQuiz&&t.video_path?`<div style="font-size:11px;color:var(--muted2);margin-top:2px">🔗 ${esc(t.video_path)}</div>`:''}
          </div>
        </div>
        <div class="m-gap" style="display:flex;gap:8px;display: grid;grid-template-columns: 1fr 1fr;">
          <button class="btn btn-ghost" style="padding:5px 12px;font-size:9px" onclick="editLinkedTest(${t.id})">редакт.</button>
          ${isQuiz?`<button class="btn btn-ghost" style="padding:5px 12px;font-size:9px" onclick="openAddQuestion(${t.id})">+ вопрос</button>`:''}
          <button class="btn btn-ghost" style="padding:5px 12px;font-size:9px" onclick="editAllQuestions(${t.id})">редактировать все вопросы</button>
          <button class="btn btn-danger" style="padding:5px 12px;font-size:9px" onclick="deleteTest(${t.id})">удалить</button>
        </div>
      </div>
      ${!isQuiz?`<div style="padding:14px 18px;font-size:12px;color:var(--muted2)">${isVideo?'видео-материал':'задание'} — вопросов нет</div>`:`
      <div class="q-list-wr"><div class="q-list" style="padding:16px 18px">
        ${qs.map((q,i)=>`<div class="q-item" data-test-question="${q.id}">
          <div class="q-num">${String(i+1).padStart(2,'0')}</div>
          <div class="q-body">
            <div class="q-type-badge">${q.type}</div>
            ${q.allow_own_answer==1?'<div class="q-type-badge" style="border-color:var(--primary);color:var(--primary)">+ свой ответ</div>':''}
            <div class="q-text-display">${esc(q.question_text)}</div>
            ${q.options?`<div class="opt-list">${q.options.map(o=>`<div class="opt-item"><span>${esc(o.option_text)}</span>${o.is_correct?'<span class="opt-correct">верный</span>':''}</div>`).join('')}</div>`:''}
          </div>
          <!--<button class="btn btn-ghost" style="padding:4px 10px;font-size:9px;flex-shrink:0" onclick="editQuestion(${t.id},${q.id})">редакт.</button>-->
          <button class="btn btn-danger" style="padding:4px 10px;font-size:9px;flex-shrink:0" onclick="deleteQuestion(${q.id})">✕</button>
        </div>`).join('')}
        ${!qs.length?'<div style="color:var(--muted);font-size:12px">вопросов нет — нажми «+ вопрос»</div>':''}
      </div></div>`}
    </div>`;
  }
  c.innerHTML=html;
}
function setTestType(type){
  document.getElementById('ct-question-count-block').style.display=type==='quiz'?'block':'none';
  document.getElementById('ct-content-type').value=type;
  const isVideo = type==='video';
  const isTask = type==='task';
  document.getElementById('ct-video-block').style.display=isVideo?'block':'none';
  document.getElementById('ct-type-quiz').style.background=type==='quiz'?'var(--primary)':'var(--dark3)';
  document.getElementById('ct-type-quiz').style.color=type==='quiz'?'#fff':'var(--muted2)';
  document.getElementById('ct-type-video').style.background=isVideo?'var(--primary)':'var(--dark3)';
  document.getElementById('ct-type-video').style.color=isVideo?'#fff':'var(--muted2)';
  document.getElementById('ct-type-task').style.background=isTask?'var(--primary)':'var(--dark3)';
  document.getElementById('ct-type-task').style.color=isTask?'#fff':'var(--muted2)';
}
async function createTest(){
  const editId=document.getElementById('ct-id').value;
  const deptId=document.getElementById('ct-dept').value;
  const roleId=document.getElementById('ct-role')?.value||null;
  const title=document.getElementById('ct-title').value.trim();
  const contentType=document.getElementById('ct-content-type').value;
  const questionsToIssue=Number(document.getElementById('ct-question-count').value);
  if(contentType==='quiz' && (!Number.isInteger(questionsToIssue)||questionsToIssue<1||questionsToIssue>1000)){toast('Количество вопросов должно быть целым числом от 1 до 1000','err');return;}
  const videoUrl=document.getElementById('ct-video-url')?.value.trim()||'';
  if(!title){toast('Введи название','err');return;}
  if(contentType==='video'&&!videoUrl){toast('Введи ссылку на видео','err');return;}
  const skillLevelId = document.getElementById('ct-skill-level')?.value || null;
  if(contentType==='quiz' && document.getElementById('ct-id').dataset.onboarding!=='1'){
    if(Number(deptId)<1){toast('Выберите отдел теста','err');return;}
    if(!roleId){toast('Выберите должность','err');return;}
    if(!skillLevelId){toast('Выберите Обучение','err');return;}
  }
  const coinsRaw = parseInt(document.getElementById('ct-coins').value);
  const data={dept_id:deptId,role_id:roleId,title,description:document.getElementById('ct-desc').value.trim(),content_type:contentType,video_path:videoUrl,coins_reward:isNaN(coinsRaw)?50:coinsRaw,skill_level_id:skillLevelId||null};
  if(contentType==='quiz')data.questions_to_issue=questionsToIssue;
  const res=await fetch(editId?'/api/tests.php?action=update&id='+editId:'/api/tests.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
  const j=await res.json();
  if(!j.ok){toast(j.error||'Ошибка','err');return;}
  toast(editId?'Тест сохранён':'Тест создан');closeModal('modal-test-create');
  // Сброс формы
  document.getElementById('ct-title').value='';
  document.getElementById('ct-desc').value='';
  document.getElementById('ct-video-url').value='';
  document.getElementById('ct-id').value='';
  document.getElementById('ct-modal-title').textContent='новый тест';
  document.getElementById('ct-save-btn').textContent='создать →';
  setTestType('quiz');
  curTestDept=Number(deptId);
  loadTests(deptId);
}
async function editLinkedTest(id){
  if(!_skillLevels.length) await loadSkillLevelsAdmin();
  const res=await api.get('/api/tests.php?id='+id);
  if(!res.ok){toast(res.error||'Тест не найден','err');return;}
  const t=res.test;
  document.getElementById('ct-question-count').value=t.questions_to_issue??10;
  document.getElementById('ct-id').value=t.id;
  document.getElementById('ct-id').dataset.onboarding=Number(t.onboarding_step_id)>0?'1':'0';
  document.getElementById('ct-modal-title').textContent='редактировать тест';
  document.getElementById('ct-save-btn').textContent='сохранить →';
  document.getElementById('ct-dept').value=t.dept_id||0;
  await fillAdminRoles('ct-dept','ct-role');
  document.getElementById('ct-role').value=t.role_id||'';
  document.getElementById('ct-skill-level').value=t.skill_level_id||'';
  document.getElementById('ct-coins').value=t.coins_reward||0;
  document.getElementById('ct-title').value=t.title||'';
  document.getElementById('ct-desc').value=t.description||'';
  document.getElementById('ct-video-url').value=t.video_path||'';
  setTestType(t.content_type||'quiz');
  openModal('modal-test-create');
}
async function deleteTest(id){
  if(!confirm('Удалить тест со всеми вопросами?'))return;
  const res=await fetch('/api/tests.php?id='+id,{method:'DELETE'});
  const j=await res.json();
  if(j.ok){toast('Удалено');loadTests(curTestDept);}else toast(j.error||'Ошибка','err');
}

// ЗАДАНИЯ ХАРДСКИЛЛОВ (content_type task/video, привязаны к уровню)
let _assignments=[];
let curAssignDept=Number(DEPTS?.[0]?.id)||1;
function switchAssignDept(id,el){
  curAssignDept=id;
  document.querySelectorAll('#apage-assignments .tab').forEach(t=>t.classList.remove('active'));
  el.classList.add('active');
  renderAssignmentsList();
}
async function loadAssignments(){
  const res=await api.get('/api/tests.php?assignments=1');
  const c=document.getElementById('assignments-content');
  if(!res.ok){c.innerHTML='<div style="color:var(--muted)">ошибка загрузки</div>';return;}
  _assignments=res.tests||[];
  document.getElementById('assignments-admin-badge').textContent=_assignments.length;
  renderAssignmentsList();
}
function renderAssignmentsList(){
  const c=document.getElementById('assignments-content');
  // как и в разделах «тесты»/«онбординг»/«стандарты» — своего отдела + общие («все отделы»)
  const filtered=_assignments.filter(a=>Number(a.dept_id)===0||Number(a.dept_id)===curAssignDept);
  if(!filtered.length){c.innerHTML='<div style="color:var(--muted);padding:20px">заданий нет — добавь первое</div>';return;}
  const groups={};
  filtered.forEach(a=>{const key=a.skill_level_title||'— без уровня —';(groups[key]=groups[key]||[]).push(a);});
  let html='';
  Object.keys(groups).forEach(levelTitle=>{
    html+=`<div style="font-size:10px;font-family:'Unbounded',sans-serif;letter-spacing:.08em;text-transform:uppercase;color:var(--primary);margin:20px 0 8px">${esc(levelTitle)}</div>`;
    groups[levelTitle].forEach(a=>{
      const typeLabel=a.content_type==='video'?'видео':'задание';
      html+=`<div style="border:1px solid var(--border);margin-bottom:10px;background:var(--dark);padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px">
        <div style="min-width:0">
          <div style="display:flex;align-items:center;gap:8px">
            <div style="font-size:14px;font-weight:700">${esc(a.title)}</div>
            <span style="font-size:9px;font-family:'Unbounded',sans-serif;padding:2px 7px;border:1px solid var(--primary);color:var(--primary);letter-spacing:.04em;text-transform:uppercase">${typeLabel}</span>
          </div>
          <div class="les-desc" style="font-size:11px;color:var(--muted);margin-top:3px">${esc((a.description||'').replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim())} · ${a.coins_reward} коинов · ${esc(a.dept_name||'все отделы')}${a.role_name?' · '+esc(a.role_name):''}</div>
          ${a.video_path?`<div style="font-size:11px;color:var(--muted2);margin-top:2px">🔗 ${esc(a.video_path)}</div>`:''}
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0">
          <button class="btn btn-ghost" style="padding:5px 12px;font-size:9px" onclick="openAssignmentModal(${a.id})">редакт.</button>
          <button class="btn btn-danger" style="padding:5px 12px;font-size:9px" onclick="deleteAssignment(${a.id})">удалить</button>
        </div>
      </div>`;
    });
  });
  c.innerHTML=html;
}
function setAssignmentType(type){
  document.getElementById('as-content-type').value=type;
  document.getElementById('as-type-task').style.background=type==='task'?'var(--primary)':'var(--dark3)';
  document.getElementById('as-type-task').style.color=type==='task'?'#fff':'var(--muted2)';
  document.getElementById('as-type-video').style.background=type==='video'?'var(--primary)':'var(--dark3)';
  document.getElementById('as-type-video').style.color=type==='video'?'#fff':'var(--muted2)';
}
async function openAssignmentModal(id){
  if(!_skillLevels.length) await loadSkillLevelsAdmin();
  const a=id?_assignments.find(x=>x.id===id):null;
  document.getElementById('modal-assignment')?.remove();
  if(!_skillLevels.length){ toast('Сначала создай Обучение в разделе «Обучения»','err'); return; }
  document.body.insertAdjacentHTML('beforeend',`<div class="modal-overlay open" id="modal-assignment">
    <div class="modal" style="max-width:560px">
      <div class="modal-title">${a?'редактировать задание':'новое задание'}</div>
      <input type="hidden" id="as-id" value="${a?.id||''}">
      <div class="field"><label>Обучение</label>
        <select id="as-skill-level">${_skillLevels.map(lv=>`<option value="${lv.id}" ${a&&a.skill_level_id==lv.id?'selected':''}>${esc(lv.title)}</option>`).join('')}</select>
      </div>
      <div class="field"><label>название</label><input type="text" id="as-title" value="${esc(a?.title||'')}" placeholder="например: сделать медиаплан для тестового клиента"></div>
      <div class="field"><label>описание задания</label>
        <textarea id="as-desc" rows="4" style="display:none"></textarea>
      </div>
      <div class="field">
        <label>тип</label>
        <div style="display:flex;gap:0;border:1px solid var(--border2)">
          <div id="as-type-task" onclick="setAssignmentType('task')" style="flex:1;padding:10px 16px;cursor:pointer;font-size:10px;text-align:center;font-family:'Unbounded',sans-serif;letter-spacing:.04em;text-transform:uppercase;transition:background .15s">задание</div>
          <div id="as-type-video" onclick="setAssignmentType('video')" style="flex:1;padding:10px 16px;cursor:pointer;font-size:10px;text-align:center;font-family:'Unbounded',sans-serif;letter-spacing:.04em;text-transform:uppercase;transition:background .15s">видео</div>
        </div>
        <input type="hidden" id="as-content-type" value="${a?.content_type||'task'}">
      </div>
      <div class="field"><label>ссылка на видео / файл (опционально)</label>
        <input type="text" id="as-video-url" value="${esc(a?.video_path||'')}" placeholder="https://youtube.com/... или /uploads/файл">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="field"><label>отдел</label>
          <select id="as-dept" onchange="fillAdminRoles('as-dept','as-role')">${MANAGER_ONLY?'':'<option value="0">для всех отделов</option>'}${DEPTS.map(d=>`<option value="${d.id}" ${a&&a.dept_id==d.id?'selected':''}>${esc(d.name)}</option>`).join('')}</select>
        </div>
        <div class="field"><label>коины за выполнение</label><input type="number" id="as-coins" value="${a?.coins_reward??50}" min="0"></div>
      </div>
      <div class="field">
        <label style="font-size:12px;color:var(--muted2);display:block;margin-bottom:6px">должность (опционально)</label>
        <select id="as-role"><option value="">— для всех ролей —</option></select>
      </div>
      <div class="modal-footer">
        <button class="btn" onclick="saveAssignment()">${a?'сохранить →':'создать →'}</button>
        <button class="btn btn-ghost" onclick="document.getElementById('modal-assignment').remove()">отмена</button>
      </div>
    </div>
  </div>`);
  initRichEditor('as-desc');
  setRichEditorValue('as-desc', a?.description||'');
  setAssignmentType(a?.content_type||'task');
  await fillAdminRoles('as-dept','as-role');
  if(a&&a.role_id) document.getElementById('as-role').value=a.role_id; else document.getElementById('as-role').value='';
}
async function saveAssignment(){
  const id=document.getElementById('as-id').value;
  const title=document.getElementById('as-title').value.trim();
  if(!title){toast('Введи название','err');return;}
  const skillLevelId=document.getElementById('as-skill-level').value;
  if(!skillLevelId){toast('Выбери Обучение','err');return;}
  const deptId=document.getElementById('as-dept').value;
  const roleId=document.getElementById('as-role')?.value||null;
  const data={
    title,
    description: document.getElementById('as-desc').value.trim(),
    content_type: document.getElementById('as-content-type').value,
    video_path: document.getElementById('as-video-url').value.trim(),
    dept_id: deptId,
    role_id: roleId,
    coins_reward: parseInt(document.getElementById('as-coins').value)||0,
    skill_level_id: skillLevelId,
  };
  const url = id ? '/api/tests.php?action=update&id='+id : '/api/tests.php';
  const res=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
  const j=await res.json();
  if(!j.ok){toast(j.error||'Ошибка','err');return;}
  toast(id?'Сохранено':'Задание создано');
  document.getElementById('modal-assignment').remove();
  loadAssignments();
}
async function deleteAssignment(id){
  if(!confirm('Удалить задание?'))return;
  const res=await fetch('/api/tests.php?id='+id,{method:'DELETE'});
  const j=await res.json();
  if(j.ok){toast('Удалено');loadAssignments();}else toast(j.error||'Ошибка','err');
}

let _addQTestId=null;
function openAddQuestion(testId){
  _addQTestId=testId;
  document.getElementById('modal-add-q')?.remove();
  document.body.insertAdjacentHTML('beforeend',`<div class="modal-overlay open" id="modal-add-q">
    <div class="modal">
      <div class="modal-title">добавить вопрос</div>
      <div class="field"><label>тип</label>
        <select id="aq-type" onchange="renderOptFields()">
          <option value="single">single — один ответ</option>
          <option value="multi">multi — несколько</option>
          <option value="open">open — открытый</option>
        </select>
      </div>
      <div class="field"><label>текст вопроса</label><textarea id="aq-text" rows="3" style="width:100%;background:var(--dark3);border:1px solid var(--border2);color:var(--text);padding:10px;outline:none;resize:none;font-size:13px;font-family:'Onest',sans-serif"></textarea></div>
      <div id="aq-opts-wrap"></div>
      <div class="modal-footer">
        <button class="btn" onclick="submitQuestion()">добавить →</button>
        <button class="btn btn-ghost" onclick="document.getElementById('modal-add-q').remove()">отмена</button>
      </div>
    </div>
  </div>`);
  renderOptFields();
}
function renderOptFields(){
  const type=document.getElementById('aq-type').value;
  const wrap=document.getElementById('aq-opts-wrap');
  if(type==='open'){wrap.innerHTML='';return;}
  const count = Number(document.getElementById('aq-option-count')?.value || 4);
  const ownChecked = document.getElementById('aq-allow-own')?.checked ? 'checked' : '';
  wrap.innerHTML=`<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:8px">
    <div style="font-size:9px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-family:'Unbounded',sans-serif">варианты ответов</div>
    <div style="display:flex;align-items:center;gap:6px">
      <label style="font-size:11px;color:var(--muted)">кол-во</label>
      <input type="number" id="aq-option-count" min="2" max="8" value="${count}" onchange="renderOptFields()" style="width:58px;background:var(--dark3);border:1px solid var(--border2);color:var(--text);padding:6px 8px;outline:none;font-size:12px">
    </div>
  </div>
    ${Array.from({length: Math.max(2, count)}).map((_,i)=>`<div style="display:flex;gap:8px;margin-bottom:6px">
      <input type="text" id="aq-opt-${i}" placeholder="вариант ${i+1}" style="flex:1;background:var(--dark3);border:1px solid var(--border2);color:var(--text);padding:8px 12px;outline:none;font-size:13px;font-family:'Onest',sans-serif">
      <label style="display:flex;align-items:center;gap:6px;font-size:11px;color:var(--muted);cursor:pointer;white-space:nowrap"><input type="${type==='multi'?'checkbox':'radio'}" name="aq-correct" id="aqc-${i}" value="${i}"> верный</label>
    </div>`).join('')}
    <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--muted2);cursor:pointer;margin-top:10px;padding-top:10px;border-top:1px solid var(--border2)">
      <input type="checkbox" id="aq-allow-own" ${ownChecked}> разрешить сотруднику вписать свой вариант ответа (доп. поле рядом с вариантами, не влияет на баллы)
    </label>`;
}
async function submitQuestion(){
  const type=document.getElementById('aq-type').value;
  const text=document.getElementById('aq-text').value.trim();
  if(!text){toast('Введи текст вопроса','err');return;}
  const options=[];
  let allowOwnAnswer=false;
  if(type!=='open'){
    const count = Math.max(2, Number(document.getElementById('aq-option-count')?.value || 4));
    for(let i=0;i<count;i++){
      const v=document.getElementById('aq-opt-'+i)?.value.trim();
      const c=document.getElementById('aqc-'+i)?.checked;
      if(v)options.push({option_text:v,is_correct:c?1:0});
    }
    if(!options.length){toast('Добавь варианты ответов','err');return;}
    if(!options.some(o=>o.is_correct)){toast('Отметь правильный ответ','err');return;}
    allowOwnAnswer = !!document.getElementById('aq-allow-own')?.checked;
  }
  const res=await fetch('/api/tests.php?action=add_question',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({test_id:_addQTestId,type,question_text:text,options,allow_own_answer:allowOwnAnswer?1:0})});
  const j=await res.json();
  if(!j.ok){toast(j.error||'Ошибка','err');return;}
  toast('Вопрос добавлен');document.getElementById('modal-add-q')?.remove();loadTests(curTestDept);
}
let bulkQuestionTest=null;
async function editAllQuestions(testId){
  try{
    const res=await api.get('/api/tests.php?id='+testId);
    if(!res.ok)throw Error(res.error||'Не удалось загрузить тест');
    const t=res.test;
    if(!t.questions?.length){toast('В тесте нет вопросов','err');return;}
    bulkQuestionTest=t;
    document.getElementById('modal-edit-q')?.remove();
    document.body.insertAdjacentHTML('beforeend',`<div class="modal-overlay open" id="modal-edit-q"><div class="modal" style="max-width:900px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden">
      <div class="modal-title">${esc(t.title)} — вопросы</div>
      <div style="overflow-y:auto;min-height:0;padding:4px" id="bulk-question-fields">
      ${t.questions.map((q,i)=>`<section style="border:1px solid var(--border);padding:16px;margin-bottom:14px">
        <div style="margin-bottom:8px">Вопрос ${i+1}</div>
        <div class="field"><textarea aria-label="Текст вопроса ${i+1}" id="bq-text-${i}" rows="4" style="width:100%;resize:vertical"></textarea></div>
        ${(q.options||[]).map((o,j)=>`<div class="field" style="display:flex;gap:8px;align-items:flex-start"><textarea aria-label="Вариант ${j+1} вопроса ${i+1}" id="bq-opt-${i}-${j}" rows="2" style="flex:1;min-width:0;resize:vertical"></textarea><label><input type="${q.type==='multi'?'checkbox':'radio'}" name="eq-correct" data-bulk-correct="${i}" id="bq-correct-${i}-${j}" onchange="bulkCorrectChanged(${i},${j})"> верный</label></div>`).join('')}
      </section>`).join('')}</div>
      <div id="bulk-question-status" role="status" style="margin-top:8px"></div>
      <div class="modal-footer" style="flex-shrink:0"><button class="btn" id="bulk-question-save" onclick="saveAllQuestions()">сохранить все вопросы</button><button class="btn btn-ghost" id="bulk-question-close" onclick="closeAllQuestions()">закрыть</button></div>
    </div></div>`);
    t.questions.forEach((q,i)=>{document.getElementById('bq-text-'+i).value=q.question_text;(q.options||[]).forEach((o,j)=>{document.getElementById(`bq-opt-${i}-${j}`).value=o.option_text;const el=document.getElementById(`bq-correct-${i}-${j}`);el.name='eq-correct-'+i;el.checked=Number(o.is_correct)===1;});});
  }catch(e){toast(e.message,'err');}
}
function bulkCorrectChanged(i,j){
  if(bulkQuestionTest.questions[i].type==='single')bulkQuestionTest.questions[i].options.forEach((_,k)=>{document.getElementById(`bq-correct-${i}-${k}`).checked=k===j;});
}
function closeAllQuestions(){
  if(document.getElementById('bulk-question-fields').dataset.dirty==='1'&&!confirm('Закрыть без сохранения изменений?'))return;
  document.getElementById('modal-edit-q')?.remove();
}
document.addEventListener('input',e=>{const fields=e.target.closest('#bulk-question-fields');if(fields)fields.dataset.dirty='1';});
async function saveAllQuestions(){
  const t=bulkQuestionTest;
  const questions=t.questions.map((q,i)=>({id:Number(q.id),question_text:document.getElementById('bq-text-'+i).value.trim(),options:(q.options||[]).map((o,j)=>({id:Number(o.id),option_text:document.getElementById(`bq-opt-${i}-${j}`).value.trim(),is_correct:document.getElementById(`bq-correct-${i}-${j}`).checked?1:0}))}));
  for(let i=0;i<questions.length;i++){
    const q=questions[i],correct=q.options.filter(o=>o.is_correct).length;
    if(!q.question_text||q.options.some(o=>!o.option_text)||(t.questions[i].type==='single'&&correct!==1)||(t.questions[i].type==='multi'&&!correct)){
      toast('Проверьте текст, варианты и правильные ответы в вопросе '+(i+1),'err');document.getElementById('bq-text-'+i).focus();return;
    }
  }
  const button=document.getElementById('bulk-question-save'),close=document.getElementById('bulk-question-close'),fields=document.getElementById('bulk-question-fields'),status=document.getElementById('bulk-question-status');
  button.disabled=true;close.disabled=true;fields.inert=true;status.textContent='Сохранение…';
  try{
    const res=await api.post('/api/tests.php?action=update_questions',{test_id:Number(t.id),questions});
    if(!res.ok)throw Error(res.error||'Не удалось сохранить вопросы');
    questions.forEach((q,i)=>{t.questions[i].question_text=q.question_text;t.questions[i].options=q.options;const row=document.querySelector(`[data-test-question="${q.id}"]`);if(row){row.querySelector('.q-text-display').textContent=q.question_text;const opts=row.querySelector('.opt-list');if(opts)opts.innerHTML=q.options.map(o=>`<div class="opt-item"><span>${esc(o.option_text)}</span>${o.is_correct?'<span class="opt-correct">верный</span>':''}</div>`).join('');}});
    fields.dataset.dirty='0';status.textContent='Все вопросы сохранены';toast('Все вопросы сохранены');
  }catch(e){status.textContent=e.message;toast(e.message,'err');}finally{button.disabled=false;close.disabled=false;fields.inert=false;}
}

let editingQuestion=null;
async function editQuestion(testId,questionId){
  try{
    const res=await fetch('/api/tests.php?id='+testId);
    const data=await res.json();
    if(!res.ok||!data.ok)throw Error(data.error||'Не удалось загрузить вопрос');
    const q=data.test.questions.find(q=>Number(q.id)===Number(questionId));
    if(!q)throw Error('Вопрос не найден');
    editingQuestion=q;
    document.getElementById('modal-edit-q')?.remove();
    document.body.insertAdjacentHTML('beforeend',`<div class="modal-overlay open" id="modal-edit-q"><div class="modal">
      <div class="modal-title">редактировать вопрос</div>
      <div class="field"><label>текст вопроса</label><textarea id="eq-text" rows="5" style="width:100%"></textarea></div>
      ${(q.options||[]).map((o,i)=>`<div class="field" style="display:flex;gap:8px"><input id="eq-opt-${i}" type="text" style="flex:1;min-width:0"><label><input type="${q.type==='multi'?'checkbox':'radio'}" name="eq-correct" id="eq-correct-${i}"> верный</label></div>`).join('')}
      <div class="modal-footer"><button class="btn" id="eq-save" onclick="saveEditedQuestion()">сохранить</button><button class="btn btn-ghost" onclick="document.getElementById('modal-edit-q').remove()">отмена</button></div>
    </div></div>`);
    document.getElementById('eq-text').value=q.question_text;
    (q.options||[]).forEach((o,i)=>{document.getElementById('eq-opt-'+i).value=o.option_text;document.getElementById('eq-correct-'+i).checked=Number(o.is_correct)===1;});
  }catch(e){toast(e.message,'err');}
}
async function saveEditedQuestion(){
  const q=editingQuestion,button=document.getElementById('eq-save');
  const question_text=document.getElementById('eq-text').value.trim();
  const options=(q.options||[]).map((o,i)=>({id:Number(o.id),option_text:document.getElementById('eq-opt-'+i).value.trim(),is_correct:document.getElementById('eq-correct-'+i).checked?1:0}));
  if(!question_text||options.some(o=>!o.option_text)){toast('Заполните вопрос и варианты','err');return;}
  const correct=options.filter(o=>o.is_correct).length;
  if((q.type==='single'&&correct!==1)||(q.type==='multi'&&!correct)){toast('Отметьте правильные ответы','err');return;}
  button.disabled=true;
  try{
    const res=await fetch('/api/tests.php?action=update_question',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:Number(q.id),question_text,options})});
    const data=await res.json();if(!res.ok||!data.ok)throw Error(data.error||'Не удалось сохранить вопрос');
    document.getElementById('modal-edit-q')?.remove();toast('Вопрос сохранён');loadTests(curTestDept);
  }catch(e){toast(e.message,'err');}finally{button.disabled=false;}
}

async function deleteQuestion(qId){
  if(!confirm('Удалить вопрос?'))return;
  const res=await fetch('/api/tests.php?action=delete_question&id='+qId,{method:'POST'});
  const j=await res.json();
  if(j.ok){toast('Удалено');loadTests(curTestDept);}else toast(j.error||'Ошибка','err');
}

// ── Обучения — уровни ─────────────────────────────────────────
let _skillLevels = [];

async function loadSkillLevelsAdmin(){
  const res = await api.get('/api/skill_levels.php');
  const c = document.getElementById('skill-levels-content');
  if(!res.ok){ c.innerHTML='<div style="color:var(--muted)">ошибка загрузки</div>'; return; }
  _skillLevels = res.levels || [];

  // Заполняем select в модале тестов
  const sel = document.getElementById('ct-skill-level');
  if(sel){
    sel.innerHTML = '<option value="">— обычный тест —</option>';
    _skillLevels.forEach(lv => {
      const o = document.createElement('option');
      o.value = lv.id; o.textContent = lv.title;
      sel.appendChild(o);
    });
  }

  if(!_skillLevels.length){ c.innerHTML='<div style="color:var(--muted);padding:20px">уровней нет — добавь первый</div>'; return; }

  c.innerHTML = `<div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>порядок</th><th>уровень / тест</th><th style="display:none">нужно коинов</th><th>вопросов</th><th style="display:none">ТЗ за раз</th><th>таймер</th><th>статус</th><th></th></tr></thead>
    <tbody>${_skillLevels.map(lv=>`<tr>
      <td style="color:var(--muted)">${lv.sort}</td>
      <td><strong>${esc(lv.title)}</strong><br><span style="font-size:11px;color:var(--muted)">${esc(lv.description||'')}</span>
        <div style="margin-top:6px">${(lv.linked_tests||[]).length
          ? lv.linked_tests.map(t=>`<button class="btn btn-ghost" style="display: none; padding:3px 8px;font-size:8px" onclick="event.stopPropagation();editLinkedTest(${t.id})">${esc(t.title)} ↗</button>`).join(' ')
          : '<span style="font-size:10px;color:var(--primary)">тест не привязан</span>'}</div></td>
      <td style="display:none">${lv.coins_required}</td>
      <td>${lv.questions_count} из <span style="color:var(--muted2)">${lv.questions_in_bank}</span></td>
      <td style="display:none">1 <span style="color:var(--muted2)">из ${lv.assignments_available_count||0}</span></td>
      <td>${lv.duration_minutes||60} мин.</td>
      <td style="font-size:11px;color:${lv.is_active?'var(--ok-text)':'var(--muted)'}">${lv.is_active?'активен':'скрыт'}</td>
      <td><div class="tbl-actions">
        <button class="btn btn-ghost" style="padding:5px 12px;font-size:9px" onclick="openSkillLevelModal(${lv.id})">редакт.</button>
        <button class="btn btn-danger" style="padding:5px 12px;font-size:9px" onclick="deleteSkillLevel(${lv.id})">удалить</button>
      </div></td>
    </tr>`).join('')}</tbody>
  </table></div>`;
}

function openSkillLevelModal(id){
  const lv = id ? _skillLevels.find(l=>l.id==id) : null;
  document.getElementById('modal-skill-level')?.remove();
  document.body.insertAdjacentHTML('beforeend',`
    <div class="modal-overlay open" id="modal-skill-level">
      <div class="modal">
        <div class="modal-title">${lv?'редактировать обучение':'новое обучение'}</div>
        <input type="hidden" id="sl-id" value="${lv?.id||''}">
        <div class="field-row">
          <div class="field"><label>название</label><input type="text" id="sl-title" value="${esc(lv?.title||'')}"></div>
          <div class="field"><label>порядок</label><input type="number" id="sl-sort" value="${lv?.sort||1}" min="1"></div>
        </div>
        <div class="field-row">
          <div class="field"><label>ТЗ за одно прохождение</label><input type="text" value="1 случайное задание из обучения" disabled></div>
          <div class="field"><label>время на уровень</label><input type="number" id="sl-duration" value="${lv?.duration_minutes??60}" min="1" step="1"></div>
        </div>
        <div class="field"><label>описание</label><input type="text" id="sl-desc" value="${esc(lv?.description||'')}"></div>
        <div class="field-row">
          <div class="field" style="display:none"><label>нужно коинов для доступа</label><input type="number" id="sl-coins" value="${lv?.coins_required||0}" min="0"></div>
          <div class="field-hint">Количество вопросов задаётся отдельно в настройках каждого теста: «Тесты» → «редактировать».</div>
        </div>
        <div class="field"><label>статус</label>
          <select id="sl-active"><option value="1" ${!lv||lv.is_active?'selected':''}>активен</option><option value="0" ${lv&&!lv.is_active?'selected':''}>скрыт</option></select>
        </div>
        <div class="modal-footer">
          <button class="btn" onclick="saveSkillLevel()">сохранить →</button>
          <button class="btn btn-ghost" onclick="document.getElementById('modal-skill-level').remove()">отмена</button>
        </div>
      </div>
    </div>`);
}

async function saveSkillLevel(){
  const id = document.getElementById('sl-id').value;
  const data = {
    id: id ? parseInt(id) : 0,
    title: document.getElementById('sl-title').value.trim(),
    description: document.getElementById('sl-desc').value.trim(),
    sort: parseInt(document.getElementById('sl-sort').value)||1,
    coins_required: parseInt(document.getElementById('sl-coins').value)||0,
    assignments_count: 1,
    duration_minutes: Number(document.getElementById('sl-duration').value),
    is_active: parseInt(document.getElementById('sl-active').value),
  };
  if(!data.title){toast('Введи название','err');return;}
  if(!Number.isInteger(data.duration_minutes)||data.duration_minutes<1){toast('Укажи целое положительное число минут','err');return;}
  const res = await fetch('/api/skill_levels.php?action=save_level',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
  const j = await res.json();
  if(j.ok){toast('Сохранено');document.getElementById('modal-skill-level').remove();loadSkillLevelsAdmin();}
  else toast(j.error||'Ошибка','err');
}

async function deleteSkillLevel(id){
  if(!confirm('Удалить уровень?'))return;
  const res = await fetch('/api/skill_levels.php?action=delete_level&id='+id,{method:'POST'});
  const j = await res.json();
  if(j.ok){toast('Удалено');loadSkillLevelsAdmin();}else toast(j.error||'Ошибка','err');
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

loadUsers();


// ── Тема для админки ──────────────────────────────────────────────
function adminApplyTheme(){
  var theme = localStorage.getItem('theme') || 'light';
  document.documentElement.setAttribute('data-theme', theme);
  var btn = document.getElementById('admin-theme-btn');
  if(btn) btn.textContent = theme === 'light' ? '🌙' : '☀️';
  document.querySelectorAll('.logo-img').forEach(function(img){
    img.src = theme === 'light' ? '/assets/logo-template.svg' : '/assets/logo-template.svg';
    img.style.height = '24px';
  });
}
function adminToggleTheme(){
  var cur = localStorage.getItem('theme') || 'light';
  localStorage.setItem('theme', cur === 'dark' ? 'light' : 'dark');
  adminApplyTheme();
}
adminApplyTheme();
</script>
<?php endif; ?>
<!-- МОДАЛ: достижение -->
<div class="modal-overlay" id="modal-ach">
  <div class="modal">
    <div class="modal-title" id="ach-modal-title">достижение</div>
    <input type="hidden" id="ach-id">
    <div class="field"><label>иконка (emoji)</label><input type="text" id="ach-icon" value="🏆" maxlength="4" style="width:60px"></div>
    <div class="field"><label>SVG-код иконки</label><textarea id="ach-svg" rows="6" placeholder="<svg viewBox=&quot;0 0 64 64&quot;>…</svg>" style="width:100%;font-family:monospace" oninput="updateAchievementIconPreview()"></textarea><div class="field-hint">Полный тег SVG с фигурами path, rect, circle и другими. Пустое поле использует стандартную иконку.</div><div id="ach-svg-preview" style="margin-top:12px;width:80px;height:80px"></div></div>
    <div class="field"><label>название</label><input type="text" id="ach-title" placeholder="Добро пожаловать!"></div>
    <div class="field"><label>описание</label><input type="text" id="ach-desc" placeholder="Успешно завершил обучение"></div>
    <div class="field">
      <label>условие</label>
      <select id="ach-ctype" onchange="achCondChange()">
        
        
        
        
        <option value="skill_level">skill_level — сдан тест обучений</option>
      </select>
    </div>
    <div class="field" id="ach-cval-wrap" style="display:none">
      <label id="ach-cval-label">значение</label>
      <input type="text" id="ach-cval" placeholder="">
    </div>
    <div class="field">
      <label>статус</label>
      <select id="ach-active">
        <option value="1">активно</option>
        <option value="0">скрыто</option>
      </select>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="saveAch()">сохранить →</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-ach')">отмена</button>
    </div>
  </div>
</div>

<script>
function updateAchievementIconPreview(){
  const target=document.getElementById('ach-svg-preview');target.replaceChildren();
  const svg=document.getElementById('ach-svg').value.trim();
  if(!svg)return;
  const img=document.createElement('img');img.alt='Предпросмотр иконки';img.width=80;img.height=80;
  img.src='data:image/svg+xml;charset=utf-8,'+encodeURIComponent(svg);target.appendChild(img);
}
// ── Достижения (админка) ──────────────────────────────────────────
function achCondChange(){
  const ct = document.getElementById('ach-ctype').value;
  const wrap = document.getElementById('ach-cval-wrap');
  const lbl  = document.getElementById('ach-cval-label');
  const inp  = document.getElementById('ach-cval');
  if(ct === 'section_done'){
    wrap.style.display=''; lbl.textContent='slug раздела (tools / job_instructions)';
    inp.placeholder='tools';
  } else if(ct === 'coins'){
    wrap.style.display=''; lbl.textContent='сколько коинов';
    inp.placeholder='100';
  } else if(ct === 'skill_level'){
    wrap.style.display=''; lbl.textContent='slug уровня обучений';
    inp.placeholder='junior';
  } else {
    wrap.style.display='none';
  }
}

async function loadAchievements(){
  document.getElementById('ach-content').innerHTML='<div style="color:var(--muted)">загрузка...</div>';
  const res = await api.get('/api/achievements.php?action=admin');
  if(!res.ok){ document.getElementById('ach-content').innerHTML='<div style="color:var(--err-text)">ошибка загрузки</div>'; return; }
  const achs = res.achievements || [];
  if(!achs.length){
    document.getElementById('ach-content').innerHTML='<div style="color:var(--muted);padding:20px">нет достижений — нажми «+ добавить»</div>';
    return;
  }
  const rows = achs.map(a=>{
    const activeLabel = a.is_active ? '<span style="color:var(--ok-text);font-size:11px">активно</span>' : '<span style="color:var(--muted);font-size:11px">скрыто</span>';
    const cval = a.condition_value ? ' <span style="color:var(--muted2);font-size:11px">= '+esc(a.condition_value)+'</span>' : '';
    return `<tr>
      <td style="font-size:22px;width:40px" class="admin-ach-icon">${achievementIcon(a)}</td>
      <td>
        <div style="font-weight:700;font-size:13px">${esc(a.title)}</div>
        <div style="color:var(--muted2);font-size:11px">${esc(a.description||'')}</div>
      </td>
      <td style="font-size:11px;font-family:'Unbounded',sans-serif;color:var(--muted2)">${esc(a.condition_type)}${cval}</td>
      <td>${activeLabel}</td>
      <td>
        <div class="tbl-actions">
          <button class="btn btn-ghost" style="padding:5px 12px;font-size:9px" onclick="editAch(${a.id})">редактировать</button>
          <button class="btn btn-danger" style="padding:5px 12px;font-size:9px" onclick="deleteAch(${a.id})">удалить</button>
        </div>
      </td>
    </tr>`;
  }).join('');
  document.getElementById('ach-content').innerHTML=`
    <div style="overflow-x:auto"><table class="tbl" style="width:100%">
      <thead><tr>
        <th></th><th>название</th><th>условие</th><th>статус</th><th></th>
      </tr></thead>
      <tbody>${rows}</tbody>
    </table></div>`;
}

function openAchModal(ach){
  document.getElementById('ach-id').value = ach ? ach.id : '';
  document.getElementById('ach-modal-title').textContent = ach ? 'редактировать достижение' : 'новое достижение';
  document.getElementById('ach-icon').value  = ach ? (ach.icon||'🏆') : '🏆';
  document.getElementById('ach-svg').value = ach ? (ach.icon_svg || (achievementIcon(ach).startsWith('<svg')?achievementIcon(ach):'')) : '';
  updateAchievementIconPreview();
  document.getElementById('ach-title').value = ach ? ach.title : '';
  document.getElementById('ach-desc').value  = ach ? (ach.description||'') : '';
  document.getElementById('ach-ctype').value = 'skill_level';
  document.getElementById('ach-cval').value  = ach ? (ach.condition_value||'') : '';
  document.getElementById('ach-active').value = ach ? (ach.is_active?'1':'0') : '1';
  achCondChange();
  openModal('modal-ach');
}

async function editAch(id){
  const res = await api.get('/api/achievements.php?action=admin');
  if(!res.ok) return;
  const ach = res.achievements.find(a=>a.id===id);
  if(ach) openAchModal(ach);
}

async function saveAch(){
  const id    = document.getElementById('ach-id').value;
  const ct    = document.getElementById('ach-ctype').value;
  const data  = {
    icon:            document.getElementById('ach-icon').value.trim() || '🏆',
    icon_svg:        document.getElementById('ach-svg').value.trim(),
    title:           document.getElementById('ach-title').value.trim(),
    description:     document.getElementById('ach-desc').value.trim(),
    condition_type:  ct,
    condition_value: ['section_done','coins','skill_level'].includes(ct) ? document.getElementById('ach-cval').value.trim() : '',
    is_active:       parseInt(document.getElementById('ach-active').value),
  };
  if(!data.title){ toast('Введи название','err'); return; }
  let res;
  if(id){
    res = await api.put('/api/achievements.php?id='+id, data);
  } else {
    res = await api.post('/api/achievements.php?action=create', data);
  }
  if(res.ok){ toast('Сохранено ✓'); closeModal('modal-ach'); loadAchievements(); }
  else toast(res.error||'Ошибка','err');
}

async function deleteAch(id){
  if(!confirm('Удалить достижение? Также удалятся все выданные записи.')) return;
  const res = await fetch('/api/achievements.php?id='+id, {method:'DELETE'});
  const j   = await res.json();
  if(j.ok){ toast('Удалено'); loadAchievements(); }
  else toast(j.error||'Ошибка','err');
}


// Возвращаем на ту же вкладку админки, что была до перезагрузки
(function(){
  let lastPage = '';
  try { lastPage = localStorage.getItem('lms_admin_last_page') || ''; } catch(e){}
  if(MANAGER_ONLY){
    document.querySelectorAll('.admin-sidebar .nav-item').forEach(el=>{
      const action=el.getAttribute('onclick') || '';
      if(action.includes('aNav(') && !['users','results','tests','assignments','chat'].some(key=>action.includes("aNav('"+key+"'"))) el.style.display='none';
    });
    document.querySelectorAll('[onclick*="openModal(\'modal-user-create\')"]').forEach(el=>el.style.display='none');
    document.querySelectorAll('select option[value="0"]').forEach(el=>{if(el.textContent.includes('всех отделов'))el.remove();});
    lastPage=['users','results','tests','assignments','chat'].includes(lastPage)?lastPage:'users';
  }
  if(!lastPage || lastPage === 'dashboard') return; // dashboard уже активна по умолчанию
  if(!['dashboard','results','users','tests','skilllevels','assignments','achievements'].includes(lastPage))lastPage='dashboard';
  const navEl = document.querySelector('.admin-sidebar .nav-item[onclick*="aNav(\'' + lastPage + '\'"]');
  if(navEl) aNav(lastPage, navEl);
})();
</script>

<script>window.materialsImportToken = <?= json_encode($_SESSION['materials_import_token']) ?>;</script>
<script src="/assets/template-import-link.js"></script>
<script src="/assets/template.js"></script><script src="/assets/template-admin.js"></script></body>
</html>

<script>if(typeof MANAGER_ONLY!=='undefined'&&!MANAGER_ONLY)fillUserLevels('cu-level').catch(e=>toast(e.message,'err'));</script>
