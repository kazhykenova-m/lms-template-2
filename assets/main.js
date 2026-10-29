
function applyLogoTheme(){
  var theme = document.documentElement.getAttribute('data-theme') || 'light';
  var isLight = theme === 'light';
  document.querySelectorAll('.logo-img').forEach(function(img){
    img.setAttribute(
    'src',
    isLight ? '/assets/logo-template.svg' : '/assets/logo-template.svg'
  );
    img.style.display = 'block';
  });
  var btn = document.getElementById('theme-toggle-btn');
  if(btn) btn.textContent = isLight ? '🌙' : '☀️';
}
// Вызываем сразу при парсинге скрипта
applyLogoTheme();
// И после полной загрузки страницы (перехватываем toggleTheme из app.js)
window.addEventListener('DOMContentLoaded', function(){
  applyLogoTheme();
  // Патчим toggleTheme чтобы логотип менялся при смене темы
  var _checkInterval = setInterval(function(){
    if(typeof window.toggleTheme === 'function' && !window._logoThemePatchDone){
      var orig = window.toggleTheme;
      window.toggleTheme = function(){
        orig.apply(this, arguments);
        setTimeout(applyLogoTheme, 10);
      };
      window._logoThemePatchDone = true;
      clearInterval(_checkInterval);
    }
  }, 100);
});

;

let currentUser=null,depts=[],quizData=null;

// ── Система уровней ────────────────────────────────────────────────
const LEVELS = [
  { num:1, slug:'newbie',  title:'стажер', icon:'🌱', desc:'начало пути'            },
  { num:2, slug:'junior',  title:'junior',       icon:'⚡', desc:'сдан тест «Junior»'       },
  { num:3, slug:'middle',  title:'middle',       icon:'🏆', desc:'сдан тест «Middle»'       },
  { num:4, slug:'senior',  title:'senior',     icon:'💫', desc:'сдан тест «Senior»'     },
];
window._userLevel = 1;
window._passedSlugs = [];

// Грейд = название пройденного теста:
// сдал тест «синьор» → синьор (ур.4)
// сдал тест «мидл»   → мидл   (ур.3)
// сдал тест «джун»   → джун   (ур.2)
// иначе              → новобранец (ур.1)
// Онбординг только разблокирует доступ к тестам, но не даёт грейд
function calcLevel(obDone, obTotal, passedSlugs){
  passedSlugs = passedSlugs || [];
  if(passedSlugs.includes('senior')) return 4; // сдал senior → синьор
  if(passedSlugs.includes('middle')) return 3; // сдал middle → мидл
  if(passedSlugs.includes('junior')) return 2; // сдал junior → джун
  return 1;                                     // иначе       → новобранец
}

function getLevelInfo(num){
  return LEVELS.find(l=>l.num===num) || null; // null если уровня нет (максимум)
}

function applyLevelUI(num){
  const lv   = getLevelInfo(num) || LEVELS[0];
  const next = getLevelInfo(num+1); // null если максимальный уровень
  window._userLevel = num;
  const pfLv = document.getElementById('pf-level');
  if(pfLv) pfLv.textContent = num + ' — ' + lv.title;
  const ppLvN = document.getElementById('pp-level-num');
  if(ppLvN) ppLvN.textContent = 'ур. ' + num;
  const ppLvT = document.getElementById('pp-level-title');
  if(ppLvT) ppLvT.textContent = lv.icon + ' ' + lv.title;
  const ppLvS = document.getElementById('pp-level-sub');
  if(ppLvS) ppLvS.textContent = next ? 'след: ' + next.title : '🏆 максимальный уровень!';
}
let quizState={cur:0,answers:{},ownAnswers:{},done:false,score:0,maxScore:0,coinsEarned:0};

(async()=>{
  const[a,d]=await Promise.all([api.get('/api/auth.php?action=me'),api.get('/api/departments.php')]);
  depts=d.departments||[];
  fillDeptSelectData('rdept',depts);
  if(a.ok){currentUser=a.user;enterApp();}else goScreen('login');
})();

async function doLogin(){
  clrAllErr();
  const email=val('le'),password=val('lp');
  let ok=true;
  if(!email||!email.includes('@')){setErr('le','');ok=false;}
  if(!password){setErr('lp','');ok=false;}
  if(!ok)return;
  const errEl=document.getElementById('login-err');errEl.classList.remove('show');
  const btn=document.querySelector('#screen-login .btn');btn.disabled=true;btn.textContent='...';
  const res=await api.post('/api/auth.php?action=login',{email,password});
  btn.disabled=false;btn.textContent='войти →';
  if(!res.ok){errEl.textContent=res.error||'Ошибка';errEl.classList.add('show');return;}
  currentUser=res.user;enterApp();
}

async function doRegister(){
  clrAllErr();
  const p=val('rp1i'),p2=val('rp2i');
  let ok=true;
  if(!p||p.length<6){setErr('rp1','пароль слишком короткий');ok=false;}
  if(p!==p2){setErr('rp2','пароли не совпадают');ok=false;}
  if(!ok)return;
  const btn=document.getElementById('reg-submit-btn');btn.disabled=true;btn.textContent='...';
  const res=await api.post('/api/auth.php?action=register',{first_name:val('rfn'),last_name:val('rln'),email:val('rem'),dept_id:val('rdept'),role_id:val('rrole'),is_manager:document.getElementById('rmanager').checked?1:0,password:p});
  btn.disabled=false;btn.textContent='создать аккаунт →';
  if(!res.ok){toast(res.error||'Ошибка регистрации','err');return;}
  currentUser=res.user;enterApp();
}

async function loadEmployeeResults(){
  const el=document.getElementById('employee-results');el.textContent='Загрузка…';
  try{const res=await api.get('/api/results.php');if(!res.ok){el.textContent=res.error||'Не удалось загрузить результаты';return;}
  const publication=res.publications?.[0];
  if(!publication){el.innerHTML='<div style="border:1px solid var(--border);padding:24px">Результаты появятся после проверки и публикации руководителем.</div>';return;}
  el.innerHTML='<p style="margin-bottom:16px;color:var(--muted2)">Опубликовано: '+escH(publication.published_at)+'</p>'+ResultsDashboard.render(publication.results,{details:true,employeeOrder:true});
  }catch(e){el.textContent='Не удалось загрузить результаты. Попробуйте ещё раз.';}
}
async function openProfileEditor(){
  try {
    const res=await api.get('/api/departments.php');
    if(!res.ok){toast(res.error||'Не удалось загрузить отделы','err');return;}
    window._profileDepartments=res.departments||[];
    document.getElementById('pe-first').value=currentUser.first_name||'';
    document.getElementById('pe-last').value=currentUser.last_name||'';
    document.getElementById('pe-manager').checked=Number(currentUser.is_manager)===1;
    fillDeptSelectData('pe-dept',window._profileDepartments);
    document.getElementById('pe-dept').value=currentUser.dept_id||'';
    fillProfileRoles();document.getElementById('pe-role').value=currentUser.role_id||'';
    document.getElementById('profile-editor').style.display='';
  }catch(e){toast('Не удалось загрузить профиль','err');}
}
function fillProfileRoles(){
  const sel=document.getElementById('pe-role'),previous=sel.value;
  const manager=document.getElementById('pe-manager').checked;
  const dept=(window._profileDepartments||[]).find(d=>Number(d.id)===Number(val('pe-dept')));
  sel.innerHTML='<option value="">'+(manager?'Руководитель отдела (без дополнительной должности)':'— выбери должность —')+'</option>';
  (dept?.roles||[]).forEach(r=>{const o=document.createElement('option');o.value=r.id;o.textContent=r.name;sel.appendChild(o);});
  if([...sel.options].some(o=>o.value===previous))sel.value=previous;
}
async function saveProfileEditor(){
  const data={first_name:val('pe-first').trim(),last_name:val('pe-last').trim(),dept_id:val('pe-dept'),role_id:val('pe-role'),is_manager:document.getElementById('pe-manager').checked?1:0};
  if(!data.first_name||!data.last_name||!data.dept_id||(!data.is_manager&&!data.role_id)){toast('Заполни имя, фамилию, отдел и должность','err');return;}
  const button=document.getElementById('pe-save');button.disabled=true;
  try {const res=await api.put('/api/users.php?id='+currentUser.id,data);if(!res.ok){toast(res.error||'Не удалось сохранить профиль','err');return;}window.location.reload();}
  catch(e){toast('Не удалось сохранить профиль','err');}
  finally{button.disabled=false;}
}

async function doLogout(){
  await api.post('/api/auth.php?action=logout',{});
  currentUser=null;quizState={cur:0,answers:{},ownAnswers:{},done:false,score:0,maxScore:0,coinsEarned:0};
  goScreen('login');
}

function rNext(from){
  if(from===1){
    let ok=true;
    if(!val('rfn')){setErr('rfn','введи имя');ok=false;}else clrErr('rfn');
    if(!val('rln')){setErr('rln','введи фамилию');ok=false;}else clrErr('rln');
    if(!val('rem')||!val('rem').includes('@')){setErr('rem','введи email');ok=false;}else clrErr('rem');
    if(!ok)return;
  }
  if(from===2){
    let ok=true;
    if(!val('rdept')){setErr('rdept','выбери отдел');ok=false;}else clrErr('rdept');
    if(!val('rrole')&&!document.getElementById('rmanager').checked){setErr('rrole','выбери должность');ok=false;}else clrErr('rrole');
    if(!ok)return;
  }
  setRegStep(from+1);
}

function fillDeptSelectData(id,arr){
  const sel=document.getElementById(id);if(!sel)return;
  sel.innerHTML='<option value="">— выбери отдел —</option>';
  arr.forEach(d=>{const o=document.createElement('option');o.value=d.id;o.textContent=d.name;sel.appendChild(o);});
}
function fillRegRoles(){
  const deptId=val('rdept');const dept=depts.find(d=>d.id==deptId);
  const sel=document.getElementById('rrole');const previous=sel.value;sel.innerHTML='<option value="">'+(document.getElementById('rmanager').checked?'Руководитель отдела (без дополнительной должности)':'— выбери должность —')+'</option>';
  (dept?.roles||[]).forEach(r=>{const o=document.createElement('option');o.value=r.id;o.textContent=r.name;sel.appendChild(o);});
  if([...sel.options].some(o=>o.value===previous))sel.value=previous;
}

function addAdminNav(){
  const nav=document.querySelector('.sb-nav');
  const div=document.createElement('div');
  div.innerHTML='<div style="height:1px;background:var(--border);margin:10px 0"></div><div class="nav-group-lbl" style="margin-bottom:6px">админ</div><div class="nav-item" onclick="window.location=\'/admin/\'"><div class="nav-left"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>панель</div></div>';
  nav.appendChild(div);
}

function openMobMenu(){
  document.getElementById('sidebar')?.classList.add('mob-open');
  document.getElementById('mob-overlay')?.classList.add('show');
  document.getElementById('mob-menu-btn')?.classList.add('open');
}
function closeMobMenu(){
  document.getElementById('sidebar')?.classList.remove('mob-open');
  document.getElementById('mob-overlay')?.classList.remove('show');
  document.getElementById('mob-menu-btn')?.classList.remove('open');
}
function navTo(pageId,el){
  if(pageId==='results')loadEmployeeResults();
  showPage(pageId,el);
  try { localStorage.setItem('lms_last_page', pageId); } catch(e){}
  if(pageId==='test')     loadSkillLevels();
  if(pageId === 'profile') {
    loadProfileExtras();
    renderLevelsProgress('pf-levels');
  }

  closeMobMenu&&closeMobMenu();
}

let _skillListTimerHandle = null;
function updateSkillListTimers(){
  const timers=document.querySelectorAll('[data-skill-list-deadline]');
  if(!timers.length){clearInterval(_skillListTimerHandle);_skillListTimerHandle=null;return;}
  timers.forEach(el=>{
    const seconds=Math.max(0,Math.ceil((Number(el.dataset.skillListDeadline)-Date.now())/1000));
    el.textContent=seconds>0?'осталось '+String(Math.floor(seconds/60)).padStart(2,'0')+':'+String(seconds%60).padStart(2,'0'):'время истекло';
  });
}
async function loadSkillLevels(){
  clearInterval(_skillListTimerHandle);
  document.getElementById('skill-levels-wrap').innerHTML='<div style="color:var(--muted)">загрузка...</div>';
  document.getElementById('skill-levels-wrap').style.display='';
  document.getElementById('quiz-wrap').style.display='none';
  let res;
  try {
    res = await api.get('/api/skill_levels.php');
  } catch(e) {
    document.getElementById('skill-levels-wrap').innerHTML='<div style="color:var(--muted2)">ошибка загрузки — <a href="#" onclick="loadSkillLevels();return false" style="color:var(--primary)">повторить</a></div>';
    return;
  }
  if(!res || !res.ok){ document.getElementById('skill-levels-wrap').innerHTML='<div style="color:var(--muted2)">ошибка загрузки — <a href="#" onclick="loadSkillLevels();return false" style="color:var(--primary)">повторить</a></div>'; return; }

  const levels = res.levels || [];
  const userCoins = res.user_coins || 0;

  // Сохраняем данные уровней и пересчитываем уровень пользователя
  window._skillLevelsData = levels;
  window._passedSlugs = levels.filter(l=>l.evaluated_passed).map(l=>l.slug);
  // Обновляем прогрессбар обучений — уровень считается закрытым только когда
  // сдан тест И выполнены все привязанные к нему задания (fully_done)
  const _doneCount = levels.filter(l=>l.fully_done).length;
  const _totalLvs = levels.length;
  const _lvPct = _totalLvs ? Math.round(_doneCount/_totalLvs*100) : 0;
  const _ppPct = document.getElementById('pp-test-pct');
  const _ppBar = document.getElementById('pp-test-bar');
  if(_ppPct) _ppPct.textContent = _lvPct+'%';
  if(_ppBar) _ppBar.style.width = _lvPct+'%';
  const obData = window._obProgress || {done:0,total:0};
  applyLevelUI(calcLevel(obData.done, obData.total, window._passedSlugs));

  if(!levels.length){
    document.getElementById('skill-levels-wrap').innerHTML='<div style="color:var(--muted);padding:20px">Уровни пока не назначены. Они появятся после назначения руководителем.</div>';
    return;
  }

  let html = '<div style="display:flex;flex-direction:column;gap:12px">';
  levels.forEach(function(lv){
    const activeAttempt = !!lv.has_active_attempt;
    const locked = !lv.available && !activeAttempt;
    const pct = lv.best_max ? Math.round(lv.best_score/lv.best_max*100) : 0;
    const pendingAssignments = !lv.attempt_used && lv.ever_passed && !lv.fully_done;
    const bgColor = activeAttempt ? 'rgba(203,78,0,.1)' : lv.fully_done ? 'rgba(30,92,51,.08)' : locked ? 'rgba(15,15,15,.4)' : 'transparent';
    const borderColor = activeAttempt ? 'var(--primary)' : lv.fully_done ? 'var(--ok)' : pendingAssignments ? 'var(--primary)' : locked ? 'var(--border)' : 'var(--border2)';
    const titleColor = locked ? 'var(--muted2)' : 'var(--text)';
    const numColor = lv.fully_done ? 'var(--ok-text)' : locked ? 'var(--muted)' : 'var(--muted2)';
    const icon = activeAttempt ? '⏳' : lv.fully_done ? '✅' : pendingAssignments ? '🆕' : locked ? '🔒' : '▶';
    const onclickFn = lv.attempt_used
      ? "toast('Попытка завершена. Результаты появятся после публикации руководителем')"
      : locked
      ? 'showLockedLevel(' + lv.coins_required + ',' + userCoins + ')'
      : ((lv.has_active_attempt || lv.fully_done) ? 'startLevel(' + lv.id + ')' : 'confirmStartLevel(' + lv.id + ',' + Math.max(1, Number(lv.duration_minutes)||60) + ')');

    html += '<div style="border:1px solid ' + borderColor + ';padding:20px 24px;background:' + bgColor + ';display:flex;align-items:center;gap:20px;cursor:' + (locked?'not-allowed':'pointer') + ';opacity:' + (locked?'.5':'1') + ';transition:background .15s" onclick="' + onclickFn + '">';
    html += '<div style="flex-shrink:0;text-align:center;min-width:48px">';
    html += '<div style="font-family:Unbounded,sans-serif;font-size:9px;font-weight:700;color:' + numColor + ';letter-spacing:.08em;text-transform:uppercase;margin-bottom:4px">' + String(lv.sort).padStart(2,'0') + '</div>';
    html += '<div style="font-size:20px">' + icon + '</div></div>';
    html += '<div style="flex:1;min-width:0">';
    html += '<div style="font-family:Unbounded,sans-serif;font-size:13px;font-weight:700;margin-bottom:4px;color:' + titleColor + '">' + lv.title + '</div>';
    html += '<div style="font-size:12px;color:var(--muted2);margin-bottom:8px">' + (lv.description||'') + '</div>';
    if(lv.attempt_used) html += '<div style="font-size:12px;color:var(--muted2);margin-bottom:10px">Попытка завершена. Результаты появятся в разделе «Результаты» после публикации руководителем.</div>';
    if(activeAttempt){
      const deadline=Date.now()+Math.max(0,Number(lv.active_remaining_seconds)||0)*1000;
      html += '<div style="color:var(--primary);font-size:12px;font-weight:700;margin-bottom:10px">проходишь сейчас · <span data-skill-list-deadline="'+deadline+'"></span></div>';
      html += '<button class="btn" style="margin-bottom:12px" onclick="event.stopPropagation();startLevel('+lv.id+')">продолжить →</button>';
    }
    html += '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">';
    html += '<span style="font-size:10px;color:var(--muted)">вопросов: ' + lv.questions_count + ' из ' + lv.questions_in_bank + ' в банке</span>';
    if(lv.assignments_required_count) html += '<span style="font-size:10px;color:' + (pendingAssignments?'var(--primary)':'var(--muted)') + '">' + lv.assignments_done_count + ' из ' + lv.assignments_required_count + ' ' + (lv.assignments_required_count===1?'задание':'заданий') + '</span>';
    if(locked) html += '<span style="font-size:10px;color:var(--primary)">нужно ' + lv.coins_required + ' коинов</span>';
    if(lv.attempts) html += '<span style="font-size:10px;color:var(--muted)">попыток: ' + lv.attempts + '</span>';
    if(lv.review_status==='pending') html += '<span style="font-size:10px;color:var(--muted2)">на рассмотрении</span>';
    else if(lv.ever_passed) html += '<span style="font-size:10px;color:var(--ok-text)">лучший: ' + pct + '%</span>';
    if(pendingAssignments) html += '<span style="font-size:10px;color:var(--primary);font-family:Unbounded,sans-serif;text-transform:uppercase;letter-spacing:.04em">новое задание</span>';
    html += '</div>';
    if(lv.ever_passed && lv.best_max && lv.review_status!=='pending'){
      html += '<div style="margin-top:8px;display:flex;align-items:center;gap:8px"><div style="flex:1;height:2px;background:var(--border2)"><div style="width:' + pct + '%;height:2px;background:var(--ok)"></div></div><span style="font-size:9px;font-family:Unbounded,sans-serif;color:var(--ok-text)">' + pct + '%</span></div>';
    }
    html += '</div>';
    if(!locked) html += '<div style="font-family:Unbounded,sans-serif;font-size:10px;color:var(--primary);letter-spacing:.06em;flex-shrink:0">→</div>';
    html += '</div>';
  });
  html += '</div>';
  document.getElementById('skill-levels-wrap').innerHTML = html;
  updateSkillListTimers();
  if(levels.some(lv=>lv.has_active_attempt)) _skillListTimerHandle=setInterval(updateSkillListTimers,1000);
}

function showLockedLevel(required, current){
  toast('Нужно ' + required + ' коинов — у тебя ' + current, 'err');
}

function confirmStartLevel(levelId, durationMinutes){
  if(window.confirm('Вы уверены, что хотите начать? После старта у вас будет ' + durationMinutes + ' мин.')){
    startLevel(levelId);
  }
}


// Уровень хардскилла = набор этапов, точно как в онбординге:
// 01 — тест (если есть банк вопросов), 02+ — задания/ТЗ уровня.
// Клик по уровню открывает список этапов, а не сразу тест.

function restoreDraftQuestion(q,answers,prefix,fieldId,save){
  (Array.isArray(answers[q.id])?answers[q.id]:[]).forEach(id=>document.getElementById(prefix+id)?.classList.add('sel'));
  const field=document.getElementById(fieldId);if(field){field.value=answers[q.id]||'';field.oninput=()=>{answers[q.id]=field.value;save();};}
}
async function quizPost(url,data){try{return await api.post(url,data);}catch(e){return {ok:false,error:'Не удалось отправить ответы. Они сохранены — повторите отправку.'};}}
function quizDraftKey(kind,id,attempt=''){
  const onboarding=kind==='step'||kind==='lesson';
  const dept=onboarding?(currentUser?.onboarding_dept_id??currentUser?.dept_id):currentUser?.dept_id;
  const role=onboarding?(currentUser?.onboarding_role_id??currentUser?.role_id):currentUser?.role_id;
  return ['lms-quiz-v2',currentUser?.id,dept,role,kind,id,attempt].join(':');
}
function readQuizDraft(key){try{return JSON.parse(localStorage.getItem(key)||'null');}catch(e){return null;}}
let _localDraftWarning=false;
function writeQuizDraft(key,data){
  try{localStorage.setItem(key,JSON.stringify(data));return true;}
  catch(e){if(!_localDraftWarning){_localDraftWarning=true;toast('Браузер не сохраняет черновик. Не закрывайте страницу до подтверждения отправки','err');}return false;}
}
const draftSaver=new DraftSaver(async(url,data)=>{
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),15000);
  try{return await api.request(url,'POST',data,controller.signal);}finally{clearTimeout(timeout);}
},(key,state,error)=>{
  document.querySelectorAll('[data-draft-status]').forEach(el=>{
    if(el.dataset.draftStatus!==key)return;
    el.textContent=state==='saved'?'Черновик сохранён на сервере':state==='pending'?'Сохраняем черновик…':'Нет подтверждения сохранения: '+error+'. Не закрывайте страницу.';
    el.style.color=state==='failed'?'var(--danger)':'var(--muted2)';
  });
});
window.addEventListener('online',()=>draftSaver.flushAll());
document.addEventListener('visibilitychange',()=>{if(document.hidden)draftSaver.flushAll();});
function queueAssignmentDraft(testId,key,comment){
  draftSaver.queue(key,'/api/assignment_drafts.php',{test_id:testId,attempt_id:_skillAttemptId,comment});
}
function dropQuizDraft(key){try{localStorage.removeItem(key);}catch(e){}}
let _skillDraftKey='', _quizDraftKey='', _skillServerResult=null;
let _skillAttemptId=0, _skillExpirySending=false, _skillExpiryData=null;
let _skillQuizSubmitting=false;
function saveSkillDraft(){
  const key=quizData?.level_id?_skillDraftKey:_quizDraftKey;
  if(key && !quizState.done){
    writeQuizDraft(key,quizState);
    if(quizData?.level_id && _skillAttemptId && !_skillExpiryData && Date.now()<_skillDeadlineMs){
      draftSaver.queue(key,'/api/skill_levels.php?action=draft',{attempt_id:_skillAttemptId,answers:quizState.answers,own_answers:quizState.ownAnswers||{}});
    }
  }
}
async function saveExpiredSkillAnswers(){
  if(_skillExpirySending || !_skillExpiryData)return;
  _skillExpirySending=true;
  const timer=document.getElementById('skill-level-timer');
  if(timer)timer.textContent='Время истекло. Сохраняем ответы…';
  try{
    const r=await api.post('/api/skill_levels.php?action=expire',_skillExpiryData);
    if(!r.ok)throw new Error(r.error||'Не удалось сохранить ответы');
    if(timer)timer.textContent='Время истекло. Ответы сохранены и доступны руководителю';
    document.getElementById('skill-expiry-retry')?.remove();
  }catch(e){
    if(timer)timer.textContent='Время истекло. Ответы остались в браузере. Повторите сохранение';
    toast(e.message||'Ошибка соединения','err');
    if(timer && !document.getElementById('skill-expiry-retry'))timer.insertAdjacentHTML('afterend','<button id="skill-expiry-retry" class="btn" onclick="saveExpiredSkillAnswers()">повторить сохранение</button>');
  }finally{_skillExpirySending=false;}
}
function captureExpiredSkillAnswers(){
  if(!quizState.done){const saved=readQuizDraft(_skillDraftKey);if(saved){quizState.answers={...(saved.answers||{}),...quizState.answers};quizState.ownAnswers={...(saved.ownAnswers||{}),...(quizState.ownAnswers||{})};}}
  const q=quizData?.questions[quizState.cur];
  if(q && !quizState.done){
    const open=document.getElementById('open-ans'),own=document.getElementById('own-ans');
    if(open)quizState.answers[q.id]=open.value;
    if(own)quizState.ownAnswers[q.id]=own.value;
    saveSkillDraft();
  }
  const assignments={};
  (_currentAssignments||[]).filter(a=>a.content_type!=='video'&&!a.done).forEach(a=>{
    const key=quizDraftKey('assignment',a.id,_skillDraftKey),ta=document.getElementById('assign-answer-'+a.id);
    const local=readQuizDraft(key);
    if((!ta || ta.dataset.draftLoading) && !local)return;
    const comment=ta && !ta.dataset.draftLoading ? ta.value : (local?.comment||'');
    writeQuizDraft(key,{comment});assignments[a.id]=comment;
  });
  _skillExpiryData=JSON.parse(JSON.stringify({level_id:_currentLevel.id,attempt_id:_skillAttemptId,answers:quizState.done?{}:quizState.answers,own_answers:quizState.ownAnswers||{},assignments}));
  saveExpiredSkillAnswers();
}
function nextSkillStage(){const i=_currentLevelSteps.findIndex(s=>s.type==='assignment'&&!s.data.done);if(i>=0)openLevelStep(i);else renderLevelSteps();}

let _currentLevelSteps = [];
let _skillDeadlineMs = 0;
let _skillTimerHandle = null;
let _skillAttemptCompleted = false;

const _celebratedSkillAttempts=new Set();
function showLevelCompletion(){
  if(!_skillAttemptCompleted)return;
  document.getElementById('skill-level-timer')?.remove();
  const key=_skillDraftKey||String(_currentLevel?.id);
  if(_celebratedSkillAttempts.has(key))return;
  _celebratedSkillAttempts.add(key);
  document.querySelector('.level-completion')?.remove();
  const block=document.createElement('div');
  block.className='level-completion';
  block.setAttribute('role','status');
  block.setAttribute('aria-live','polite');
  block.innerHTML=`<div class="game-achievement__icon" aria-hidden="true">${achievementIcon({id:"level-completion",icon:"🏆"})}</div>
    <div class="game-achievement__text">
      <div class="game-achievement__label">Уровень завершён!</div>
      <div class="game-achievement__title">Спасибо! Результаты появятся после проверки</div>
    </div>
    `;
  document.body.appendChild(block);
  animateAchievement(block);
  setTimeout(()=>{block.classList.add('is-leaving');setTimeout(()=>block.remove(),400);},5000);
  if(window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
  const confetti=document.createElement('div');confetti.className='level-confetti';confetti.setAttribute('aria-hidden','true');
  const colors=['#cb4e00','#ffbf47','#74d99b','#6daaff','#ef7daf'];
  for(let i=0;i<55;i++){
    const piece=document.createElement('i');
    piece.style.cssText='left:'+Math.random()*100+'%;background:'+colors[i%colors.length]+';--drift:'+(Math.random()*240-120)+'px;--spin:'+(Math.random()*1080-540)+'deg;animation-delay:'+Math.random()*0.4+'s;animation-duration:'+(2.1+Math.random()*0.9)+'s';
    confetti.appendChild(piece);
  }
  document.body.appendChild(confetti);
  setTimeout(()=>confetti.remove(),3600);
}

function skillTimerHtml(){
  if(_skillAttemptCompleted)return '';
  const label = 'осталось 03:00';
  return '<div id="skill-level-timer" style="position:sticky;top:8px;z-index:5;border:1px solid var(--primary);background:var(--dark);color:var(--primary);padding:10px 14px;margin-bottom:14px;font-family:Unbounded,sans-serif;font-size:11px;letter-spacing:.05em;text-align:center">'+label+'</div>';
}
function updateSkillTimer(){
  if(_skillAttemptCompleted){document.getElementById('skill-level-timer')?.remove();return;}
  const seconds = Math.max(0, Math.ceil((_skillDeadlineMs-Date.now())/1000));
  const el = document.getElementById('skill-level-timer');
  if(el) el.textContent = 'осталось ' + String(Math.floor(seconds/60)).padStart(2,'0') + ':' + String(seconds%60).padStart(2,'0');
  if(seconds<=0){
    clearInterval(_skillTimerHandle); _skillTimerHandle=null;
    if(!_skillExpiryData && _skillAttemptId)captureExpiredSkillAnswers();
    document.querySelectorAll('#quiz-wrap textarea,#quiz-wrap input,#quiz-wrap .q-btn').forEach(e=>e.disabled=true);
    if(el){el.style.color='var(--danger)';el.style.borderColor='var(--danger)';}
  }
}
function startSkillTimer(expiresAt, remainingSeconds){
  clearInterval(_skillTimerHandle);
  // remaining_seconds is authoritative and avoids browser/server timezone parsing differences.
  _skillDeadlineMs = Date.now() + Math.max(0, Number(remainingSeconds)||0)*1000;
  updateSkillTimer();
  _skillTimerHandle=setInterval(updateSkillTimer,1000);
}

async function startLevel(levelId, levelSlug){
  const wrap = document.getElementById('skill-levels-wrap');
  wrap.innerHTML = '<div style="color:var(--muted)">загрузка уровня...</div>';

  let res;
  try {
    res = await api.get('/api/skill_levels.php?action=get_test&level_id='+levelId);
  } catch(e) {
    toast('Ошибка сети — попробуй ещё раз','err'); loadSkillLevels(); return;
  }
  if(!res || !res.ok){
    toast((res&&res.error)||'Ошибка','err'); loadSkillLevels(); return;
  }

  _skillQuizSummary=null;
  _currentLevel = res.level;
  _skillAttemptCompleted = !!res.attempt_completed;
  _skillServerResult = res.quiz_result || null;
  _skillAttemptId=Number(res.attempt_id);
  _skillExpiryData=null;
  _skillDeadlineMs=Date.now()+Math.max(0,Number(res.remaining_seconds)||0)*1000;
  _skillDraftKey = quizDraftKey('level',levelId,res.attempt_id);
  if(res.quiz_draft && !readQuizDraft(_skillDraftKey)){
    try{writeQuizDraft(_skillDraftKey,{cur:0,answers:JSON.parse(res.quiz_draft.answers_json),ownAnswers:JSON.parse(res.quiz_draft.own_answers_json)});}catch(e){}
  }
  _currentAssignments = res.assignments || [];
  _assignmentDraftRequests.clear();
  _currentQuestionIds = [];
  quizData = null;
  quizState = {cur:0,answers:{},ownAnswers:{},done:false,score:0,maxScore:0,coinsEarned:0,passed:false,attempt:0};

  if ((res.questions && res.questions.length) || res.quiz_result) {
    _currentQuestionIds = (res.questions||[]).map(q=>q.id);
    quizData = { id: 0, questions: res.questions||[], level_id: levelId, total_in_bank: res.total_in_bank };
  }

  document.getElementById('skill-levels-wrap').style.display = 'none';
  document.getElementById('quiz-wrap').style.display = 'block';

  await loadSkillQuizSummary();
  renderLevelSteps();
  if(!_skillAttemptCompleted)startSkillTimer(res.expires_at, res.remaining_seconds);
}

// Список этапов уровня — «01 Тест», «02 Техническое задание» и т.д.
let _skillQuizSummary=null;
async function loadSkillQuizSummary(){
  if(!_skillAttemptCompleted || !_skillAttemptId)return;
  const attemptId=_skillAttemptId;
  try{
    const res=await api.get('/api/skill_levels.php?action=quiz_summary&attempt_id='+attemptId);
    if(res.ok && attemptId===_skillAttemptId)_skillQuizSummary=res;
  }catch(e){}
}
function renderLevelSteps(){
  const lvData = (window._skillLevelsData||[]).find(l=>l.id===_currentLevel.id) || {};
  const testDone = !!_skillServerResult || !!lvData.ever_passed;

  const steps = [];
  if (quizData && (quizData.questions.length || _skillServerResult)) steps.push({type:'quiz'});
  (_currentAssignments||[]).forEach(a=>steps.push({type:'assignment', data:a}));
  _currentLevelSteps = steps;

  document.getElementById('test-sub').textContent = _currentLevel.title + ' — выбери этап';

  let html = skillTimerHtml() + '<button class="btn btn-ghost" style="margin-bottom:16px;font-size:9px" onclick="backToLevels()">← к уровням</button>';

  if (!steps.length) {
    html += '<div style="color:var(--muted);padding:20px">для этого уровня пока нет ни теста, ни заданий</div>';
    document.getElementById('quiz-wrap').innerHTML = html;
    return;
  }

  html += '<div class="steps">';
  steps.forEach(function(st, i){
    const num = String(i+1).padStart(2,'0');
    let title, desc, tagCls, tagLabel, isDone;
    if (st.type === 'quiz') {
      isDone = testDone;
      title = 'Тест';
      desc = quizData.questions.length + ' случайных вопросов из ' + (quizData.total_in_bank || quizData.questions.length);
      if(_skillAttemptCompleted && _skillQuizSummary){
        desc+=' · Верных ответов: '+_skillQuizSummary.correct_count+' из '+_skillQuizSummary.total_count;
        if(_skillQuizSummary.pending_count)desc+=' · На проверке: '+_skillQuizSummary.pending_count;
      }
      tagCls = isDone ? 'tdone' : 'ttest';
      tagLabel = isDone ? (_skillServerResult?.review_status==='pending'?'на рассмотрении':'готово ✓') : 'тест';
    } else {
      const a = st.data;
      isDone = !!a.done;
      title = a.title;
      desc = (a.description||'').replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim();
      const typeLabel = a.content_type === 'video' ? 'видео' : 'техническое задание';
      tagCls = isDone ? 'tdone' : '';
      tagLabel = isDone ? 'готово ✓' : typeLabel;
    }
    const cls = 'step step-click' + (isDone ? ' done' : '');
    html += '<div class="' + cls + '" onclick="openLevelStep(' + i + ')">';
    html += '<div class="step-num">' + num + '</div>';
    html += '<div class="step-body"><div class="step-title">' + escH(title) + '</div><div class="step-desc">' + escH(desc) + '</div></div>';
    html += '<div class="step-meta"><span class="tag ' + tagCls + '">' + tagLabel + '</span>' + (!isDone ? '<span class="step-arrow">→</span>' : '') + '</div>';
    html += '</div>';
  });
  html += '</div>';


  document.getElementById('quiz-wrap').innerHTML = html;
  showLevelCompletion();
  updateSkillTimer();
}

function openLevelStep(idx){
  draftSaver.flushAll();
  const st = _currentLevelSteps[idx];
  if (!st) return;
  if(st.type==='assignment' && quizData?.questions.length && !_skillServerResult){toast('Сначала отправь ответы на тест','err');return;}

  document.getElementById('quiz-wrap').innerHTML =
    skillTimerHtml() + '<button class="btn btn-ghost" style="margin-bottom:16px;font-size:9px" onclick="renderLevelSteps()">← к этапам уровня</button><div id="quiz-inner"></div><div id="skill-assignments-wrap"></div>';
  updateSkillTimer();

  if (st.type === 'quiz') {
    quizState = {cur:0,answers:{},ownAnswers:{},done:false,score:0,maxScore:0,coinsEarned:0,passed:false,attempt:0};
    if(_skillServerResult){quizState={...quizState,done:true,passed:!!Number(_skillServerResult.passed),score:Number(_skillServerResult.score),maxScore:Number(_skillServerResult.max_score),reviewStatus:_skillServerResult.review_status};}
    else {const saved=readQuizDraft(_skillDraftKey);if(saved)quizState={...quizState,...saved,done:false};}
    document.getElementById('test-sub').textContent =
      _currentLevel.title + ' — ' + quizData.questions.length + ' случайных вопросов из ' + (quizData.total_in_bank || quizData.questions.length);
    renderQuizInner();
  } else {
    document.getElementById('test-sub').textContent = _currentLevel.title + ' — задание';
    renderSingleAssignment(st.data);
  }
}

async function renderSingleAssignment(a){
  const c = document.getElementById('skill-assignments-wrap') || document.getElementById('quiz-inner');
  if (!c) return;
  const assignmentType = a.content_type === 'video' ? 'видео' : 'техническое задание';
  const videoLink = a.video_path ? '<div style="margin:10px 0"><a href="'+encodeURI(a.video_path)+'" target="_blank" rel="noreferrer" style="color:var(--primary);text-decoration:underline;">Открыть '+assignmentType+'</a></div>' : '';
  // Для ТЗ (не видео) — окно для ввода ответа, обязательное перед отметкой «выполнено»
  const isTask = a.content_type !== 'video';
  const answerBlock = (isTask && !a.done)
    ? '<div style="margin-top:14px">'
      + '<label style="font-size:11px;color:var(--muted2);display:block;margin-bottom:6px">твой ответ</label>'
      + '<textarea id="assign-answer-'+a.id+'" rows="5" style="width:100%;background:var(--opt-bg);border:1px solid var(--border2);color:var(--text);padding:10px 12px;outline:none;resize:vertical;font-size:13px;font-family:Onest,sans-serif;line-height:1.6" placeholder="опиши, как выполнил(а) задание, или вставь ссылку на результат"></textarea>'
      + '</div>'
    : '';
  const actionHtml = a.done
    ? '<div style="font-size:10px;font-family:\'Unbounded\',sans-serif;letter-spacing:.08em;text-transform:uppercase;color:var(--ok-text);">'+(isTask?(a.verdict===true?'Пройдено ✓':a.verdict===false?'Не пройдено':'Ответ отправлен — ожидает проверки руководителя'):'выполнено ✓')+'</div>'
    : '<button class="q-btn" onclick="submitSkillAssignment('+a.id+')">'+(isTask?'отправить на проверку':'отметить выполненным')+'</button>';
  c.innerHTML = '<div style="border:1px solid var(--border2);padding:18px;margin-top:14px;border-radius:2px;">'
    + '<div style="font-size:9px;font-family:\'Unbounded\',sans-serif;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:8px;">'+assignmentType+' хардскилла</div>'
    + '<div style="font-size:15px;font-weight:700;margin-bottom:6px;">'+escH(a.title)+'</div>'
    + '<div style="font-size:13px;color:var(--muted2);line-height:1.7;">'+(a.description||'')+'</div>'
    + videoLink
    + answerBlock
    + '<div style="margin-top:10px;">'+actionHtml+'</div>'
    + '</div>';
  if(isTask && !a.done){
    const ta=document.getElementById('assign-answer-'+a.id);
    const key=quizDraftKey('assignment',a.id,_skillDraftKey);
    const local=readQuizDraft(key);
    const attemptId=_skillAttemptId;
    ta.value=local?.comment||'';
    ta.dataset.draftLoading='1';
    ta.disabled=true;
    let server;
    try {server=await api.get('/api/assignment_drafts.php?test_id='+a.id);} catch(e){}
    if(!ta || !ta.isConnected || attemptId!==_skillAttemptId) return;
    if(!server?.ok){_assignmentDraftRequests.set(a.id,{busy:false,saved:null,failed:true});toast(server?.error||'Черновик сохраняется только в браузере. Проверьте API черновиков','err');}
    delete ta.dataset.draftLoading;
    ta.value=server?.locked ? (server.comment||'') : (local?.comment ?? server?.comment ?? '');
    ta.disabled=!!server?.locked || _skillDeadlineMs<=Date.now();
    const status=document.createElement('div');status.dataset.draftStatus=key;status.setAttribute('role','status');status.style.fontSize='12px';ta.after(status);
    ta.oninput=()=>{writeQuizDraft(key,{comment:ta.value});queueAssignmentDraft(a.id,key,ta.value);};
    if(!ta.disabled && ta.value)queueAssignmentDraft(a.id,key,ta.value);
    attachAnswerUpload(ta);

  }
}

async function retryQuizFromResult(){
  await startLevel(_currentLevel.id, '');
  const qIdx = _currentLevelSteps.findIndex(s=>s.type==='quiz');
  if (qIdx >= 0) openLevelStep(qIdx);
}

// Navigation saves answers through the existing input handlers and draft storage.
function quizAnswered(q, answers){
  const a=answers[q.id];
  return q.type==='open' ? typeof a==='string' && !!a.trim() : Array.isArray(a) && a.length>0;
}
function quizOverview(container, questions, answers, go, submit){
  const missing=questions.map((q,i)=>quizAnswered(q,answers)?null:i+1).filter(n=>n!==null);
  container.innerHTML='<h3>Отправка теста</h3>'
    +(missing.length?'<div style="margin: 20px 0;" class="fb err" role="alert">Нет ответа на вопросы: '+missing.join(', ')+'. Ответь на них перед отправкой.</div>':'<p style="margin: 20px 0;">Все ответы заполнены. Отправить тест?</p>');
  const back=document.createElement('button');back.type='button';back.className='btn btn-ghost';
  back.textContent=missing.length?'к вопросу без ответа →':'← назад';
  back.onclick=()=>go(missing.length?missing[0]-1:Math.max(0,questions.length-1));container.appendChild(back);
  const b=document.createElement('button');b.type='button';b.className='q-btn';b.textContent='отправить тест →';b.disabled=!!missing.length;
  b.onclick=async()=>{if(b.disabled||answerUploads.size)return;const buttons=container.querySelectorAll('button');buttons.forEach(el=>el.disabled=true);try{await submit();}finally{buttons.forEach(el=>el.disabled=false);b.disabled=!!missing.length;}};
  container.appendChild(b);
}
function quizNavigation(container, questions, answers, index, go, overview){
  const footer=container.querySelector('.quiz-footer');if(!footer)return;
  const status=document.createElement('div');status.className='quiz-answer-status';status.setAttribute('role','status');status.setAttribute('aria-live','polite');
  status.style.cssText='margin:10px 0 16px;font-size:12px;color:var(--muted2)';
  container.querySelector('.quiz-hd')?.after(status);
  if(quizData?.level_id){
    const saved=document.createElement('div');saved.dataset.draftStatus=_skillDraftKey;saved.setAttribute('role','status');saved.style.fontSize='12px';status.after(saved);
  }
  const updateStatus=()=>{
    if(!status.isConnected)return;
    const answered=quizAnswered(questions[index],answers);
    const count=questions.filter(q=>quizAnswered(q,answers)).length;
    status.textContent=(answered?'✓ Ответ сохранён':'○ Нет ответа')+' · Отвечено '+count+' из '+questions.length;
    status.style.color=answered?'var(--ok-text)':'var(--muted2)';
  };
  container.oninput=updateStatus;
  container.onclick=()=>queueMicrotask(updateStatus);
  updateStatus();
  const b=document.createElement('button');b.type='button';b.className='btn btn-ghost';b.textContent='← назад';b.disabled=index===0;b.onclick=()=>go(index-1);footer.prepend(b);
  const next=footer.querySelector('.q-btn');if(next)next.textContent=index===questions.length-1?'завершить тест →':'далее →';
  container.querySelectorAll('textarea.open-ta').forEach(ta=>{
    if(ta.id.includes('own'))return;
    attachAnswerUpload(ta);
  });
}
const answerUploads=new Set();
function attachAnswerUpload(ta){
  if(!ta || ta.disabled || ta.dataset.uploadAttached)return;
  ta.dataset.uploadAttached='1';
  const label=document.createElement('label');label.className='answer-upload';label.textContent='Прикрепить файл (до 300 МБ) ';
  const input=document.createElement('input');input.type='file';label.appendChild(input);ta.after(label);
  const panel=document.createElement('div');panel.hidden=true;label.after(panel);
  const status=document.createElement('div');status.setAttribute('role','status');status.setAttribute('aria-live','polite');
  const progress=document.createElement('progress');progress.max=100;progress.value=0;progress.style.width='100%';progress.setAttribute('aria-label','Загрузка вложения');
  const cancel=document.createElement('button');cancel.type='button';cancel.className='btn btn-ghost';cancel.textContent='Отменить загрузку';
  panel.append(status,progress,cancel);
  const mb=bytes=>(bytes/1024/1024).toFixed(1)+' МБ';
  input.onchange=async()=>{
    const file=input.files[0];if(!file)return;
    if(file.size>300*1024*1024){toast('Файл слишком большой: максимум 300 МБ','err');input.value='';return;}
    input.disabled=true;answerUploads.add(input);
    panel.hidden=false;progress.hidden=false;progress.value=0;cancel.hidden=false;
    let sent=0,processing=false,lastProgress=Date.now();
    const started=Date.now();
    const render=()=>{
      const seconds=Math.floor((Date.now()-started)/1000);
      status.textContent=processing
        ? file.name+' — файл передан, ждём сохранения на сервере ('+seconds+' с)'
        : file.name+' — загружается '+Math.floor(progress.value)+'% · '+mb(sent)+' из '+mb(file.size)+' · '+seconds+' с'+(Date.now()-lastProgress>15000?' · ожидаем продолжения передачи':'');
    };
    render();
    const ticker=setInterval(render,1000);
    try{
      const data=new FormData();data.append('file',file);
      const res=await new Promise((resolve,reject)=>{
        const xhr=new XMLHttpRequest();
        xhr.open('POST','/api/upload.php?type=answer');
        xhr.timeout=30*60*1000;
        cancel.onclick=()=>xhr.abort();
        xhr.upload.onprogress=event=>{
          lastProgress=Date.now();
          if(event.lengthComputable){progress.value=100*event.loaded/event.total;sent=Math.min(file.size,Math.round(file.size*event.loaded/event.total));}
          render();
        };
        xhr.upload.onload=()=>{processing=true;progress.value=100;sent=file.size;render();};
        xhr.onerror=()=>reject(new Error('Соединение прервано. Повторите загрузку файла'));
        xhr.onabort=()=>reject(new Error('Загрузка отменена'));
        xhr.ontimeout=()=>reject(new Error('Сервер не завершил загрузку за 30 минут. Повторите загрузку'));
        xhr.onload=()=>{
          let result;
          try{result=JSON.parse(xhr.responseText);}catch(e){reject(new Error(xhr.status===413?'Сервер отклонил размер файла. Проверьте лимиты PHP и веб-сервера (до 300 МБ)':'Ошибка загрузки сервера (HTTP '+xhr.status+'). Повторите загрузку'));return;}
          if(xhr.status<200||xhr.status>=300||!result?.ok){reject(new Error(result?.error||'Не удалось загрузить файл'));return;}
          resolve(result);
        };
        xhr.send(data);
      });
      // Keep the attachment with the answer in local/server drafts and review history.
      ta.value+=(ta.value.trim()?'\n':'')+'Файл: '+res.filename+' — '+(res.url.startsWith('/api/download_attachment.php?')?res.url:encodeURI(res.url));
      ta.dispatchEvent(new Event('input',{bubbles:true}));
      status.textContent=file.name+' — прикреплён. Отправь ответ на ТЗ, чтобы сдать задание.';
      toast('Файл прикреплён');
    }catch(e){status.textContent=file.name+' — '+(e.message||'Не удалось загрузить файл');toast(e.message||'Не удалось загрузить файл','err');}
    finally{clearInterval(ticker);progress.hidden=true;cancel.hidden=true;cancel.onclick=null;answerUploads.delete(input);input.disabled=false;input.value='';}
  };
}

function quizReady(questions,answers){
  if(answerUploads.size){toast('Дождись загрузки файла','err');return false;}
  return questions.length>0 && questions.every(q=>quizAnswered(q,answers));
}

function renderQuizInner(){
  const c = document.getElementById('quiz-inner');
  if(!c||!quizData) return;
  if(quizState.done){ renderSkillResult(c); return; }
  const q = quizData.questions[quizState.cur]; if(!q){quizOverview(c,quizData.questions,quizState.answers,goLevelQuestion,submitSkillQuiz);return;}
  let body = '';
  if(q.type==='single'){
    const optsHtml = q.options.map(function(o){
      return '<div class="opt" id="opt'+o.id+'" onclick="selS('+o.id+')"><div class="opt-dot"></div>'+escH(o.option_text)+'</div>';
    }).join('');
    body = '<div class="opts">'+optsHtml+'</div>';
  } else if(q.type==='multi'){
    quizState.answers[q.id]=quizState.answers[q.id]||[];
    const optsHtml = q.options.map(function(o){
      return '<div class="opt" id="opt'+o.id+'" onclick="togM('+o.id+')"><div class="opt-chk"></div>'+escH(o.option_text)+'</div>';
    }).join('');
    body = '<div class="opts">'+optsHtml+'</div>';
  } else {
    body = '<textarea class="open-ta" id="open-ans" rows="4" placeholder="напиши развёрнутый ответ..."></textarea>';
  }
  // Если админ разрешил — добавляем необязательное поле «свой вариант ответа»
  // рядом с вариантами (single/multi). Не влияет на баллы.
  const ownAnswerBlock = (q.type!=='open' && q.allow_own_answer==1)
    ? '<div style="margin-top:12px"><label style="font-size:11px;color:var(--muted2);display:block;margin-bottom:6px">свой вариант ответа (необязательно)</label>'
      + '<textarea class="open-ta" id="own-ans" rows="2" placeholder="если ни один вариант не подходит — напиши свой">'+escH(quizState.ownAnswers[q.id]||'')+'</textarea></div>'
    : '';
  const tl = q.type==='single' ? 'один верный ответ' : q.type==='multi' ? 'несколько верных ответов' : 'открытый вопрос';
  c.innerHTML = '<div class="quiz-hd"><div class="quiz-type">'+tl+'</div><div class="quiz-cnt">'+(quizState.cur+1)+' / '+quizData.questions.length+'</div></div>'
    + '<div class="q-text">'+q.question_text+'</div>'
    + body
    + ownAnswerBlock
    + '<div class="quiz-footer"><div id="fb"></div><button class="q-btn" onclick="nextQLevel()">ответить →</button></div>'
;
  (quizState.answers[q.id] instanceof Array ? quizState.answers[q.id] : []).forEach(id=>document.getElementById('opt'+id)?.classList.add('sel'));
  const open=document.getElementById('open-ans');if(open){open.value=quizState.answers[q.id]||'';open.oninput=()=>{quizState.answers[q.id]=open.value;saveSkillDraft();};}
  const own=document.getElementById('own-ans');if(own){own.value=quizState.ownAnswers[q.id]||'';}if(own)own.oninput=()=>{quizState.ownAnswers[q.id]=own.value;saveSkillDraft();};
  quizNavigation(c,quizData.questions,quizState.answers,quizState.cur,goLevelQuestion,()=>goLevelQuestion(quizData.questions.length));
}

function goLevelQuestion(index){
  if(answerUploads.size){toast('Дождись загрузки файла','err');return;}
  quizState.cur=index;saveSkillDraft();renderQuizInner();
}
function nextQLevel(){goLevelQuestion(quizState.cur+1);}

async function submitSkillQuiz(){
  if(!quizReady(quizData.questions,quizState.answers)){goLevelQuestion(quizData.questions.length);return;}
  if(_skillQuizSubmitting)return;
  _skillQuizSubmitting=true;
  saveSkillDraft();
  const ap={};quizData.questions.forEach(q=>{if(quizState.answers[q.id]!==undefined)ap[q.id]=quizState.answers[q.id];});
  const res = await quizPost('/api/skill_levels.php?action=submit',{
    level_id: _currentLevel.id,
    attempt_id: _skillAttemptId,
    answers: ap,
    own_answers: quizState.ownAnswers || {},
    question_ids: _currentQuestionIds,
  });
  _skillQuizSubmitting=false;
  if(!res.ok){
    toast(res.error||'Не удалось отправить тест','err');
    const inner=document.getElementById('quiz-inner');
    if(inner)inner.innerHTML='<div style="margin-bottom:14px">'+escH(res.error||'Не удалось отправить тест')+'</div>'+(res.http_status===409||res.refresh_attempt||res.already_submitted||res.expired
      ? '<button class="q-btn" onclick="startLevel(_currentLevel.id)">открыть текущую попытку →</button>'
      : '<button class="q-btn" onclick="submitSkillQuiz()">повторить отправку →</button>');
    return;
  }
  quizState.done=true;
  draftSaver.stop(_skillDraftKey);
  dropQuizDraft(_skillDraftKey);
  quizState.reviewStatus=res.review_status;
  _skillServerResult={passed:res.passed,score:res.score,max_score:res.max_score,review_status:res.review_status};
  quizState.passed = res.passed;
  quizState.score = res.score ?? quizState.score;
  quizState.maxScore = res.max_score ?? quizState.maxScore;
  quizState.coinsEarned = res.coins_earned || 0;
  if(res.attempt_completed){
    clearInterval(_skillTimerHandle); _skillTimerHandle=null;
    _skillAttemptCompleted=true;
    requestAnimationFrame(showLevelCompletion);
    const timer=document.getElementById('skill-level-timer');
    timer?.remove();
  }
  if(res.coins_earned>0){
    currentUser.coins=Number.isFinite(Number(res.total_coins))
      ? Number(res.total_coins)
      : (currentUser.coins||0)+res.coins_earned;
    updateCoinsUI(currentUser.coins);
    if(res.merch_synced === false){
      toast('Коины начислены в LMS, но магазин их не принял — проверь токен интеграции','err');
    }
  } else if(res.passed && res.coins_already_earned){
    toast('Коины за этот уровень уже начислялись ранее');
  }
  // После сдачи — перезагружаем данные уровней чтобы обновить ever_passed
  try {
    const freshLvs = await api.get('/api/skill_levels.php');
    if(freshLvs.ok && freshLvs.levels){
      window._skillLevelsData = freshLvs.levels;
      window._passedSlugs = freshLvs.levels.filter(l=>l.evaluated_passed).map(l=>l.slug);
      const doneCount = freshLvs.levels.filter(l=>l.fully_done).length;
      const pct = freshLvs.levels.length ? Math.round(doneCount/freshLvs.levels.length*100) : 0;
      const ppPct = document.getElementById('pp-test-pct');
      const ppBar = document.getElementById('pp-test-bar');
      if(ppPct) ppPct.textContent = pct+'%';
      if(ppBar) ppBar.style.width = pct+'%';
      // Пересчитываем уровень
      const obData = window._obProgress || {done:0,total:0};
      applyLevelUI(calcLevel(obData.done, obData.total, window._passedSlugs));
    }
  } catch(e){}
  await loadSkillQuizSummary();
  // Показываем плашки ачивок ПОСЛЕ рендера результата
  renderSkillResult(document.getElementById('quiz-inner'));

  if (res.new_achievements && res.new_achievements.length) {
    showAchievementToast(res.new_achievements);
  }
}

async function uploadAvatar(input){
  const file = input.files?.[0];
  if(!file) return;
  if(file.size > 5*1024*1024){ toast('Файл слишком большой (макс 5MB)','err'); return; }

  // Превью сразу из FileReader
  const reader = new FileReader();
  reader.onload = function(e){
    ['pf-av','sb-av'].forEach(id=>{
      const el=document.getElementById(id);
      if(!el) return;
      el.style.backgroundImage='url('+e.target.result+')';
      el.style.backgroundSize='cover';
      el.style.backgroundPosition='center';
      el.textContent='';
    });
  };
  reader.readAsDataURL(file);

  // Загружаем на сервер
  const fd = new FormData();
  fd.append('avatar', file);
  try {
    const r = await fetch('/api/upload.php?type=avatar', {
  method: 'POST',
  body: fd
});

const text = await r.text();
console.log('UPLOAD RESPONSE:', text);

let j;
try {
  j = JSON.parse(text);
} catch (e) {
  toast('Сервер вернул не JSON. Смотри Console → UPLOAD RESPONSE', 'err');
  return;
}

if (j.ok) {
  toast('Фото обновлено ✓');
  currentUser.avatar = j.url;
  applyAvatar(currentUser);
} else {
  toast(j.error || 'Ошибка загрузки', 'err');
}
    if(j.ok){
      toast('Фото обновлено ✓');
      currentUser.avatar = j.url;
    } else {
      toast(j.error||'Ошибка загрузки','err');
    }
  } catch(e){
    toast('Ошибка сети: '+e.message,'err');
  }
}

function applyAvatar(user){
  if(!user.avatar) return;
  ['pf-av','sb-av'].forEach(id=>{
    const el=document.getElementById(id);
    if(!el) return;
    el.style.backgroundImage='url('+user.avatar+')';
    el.style.backgroundSize='cover';
    el.style.backgroundPosition='center';
    el.textContent='';
  });
}

function backToLevels(){
  draftSaver.flushAll();
  clearInterval(_skillTimerHandle); _skillTimerHandle=null;
  document.getElementById('quiz-wrap').style.display='none';
  document.getElementById('skill-levels-wrap').style.display='';
  loadSkillLevels();
}

const _assignmentDraftRequests=new Map();
const _assignmentSubmissions=new Set();
async function submitSkillAssignment(testId){
  if(answerUploads.size){toast('Дождись загрузки файла','err');return;}
  const a = (_currentAssignments||[]).find(x=>x.id===testId);
  if(!a){ toast('Задание не найдено','err'); return; }
  const isTask = a.content_type !== 'video';
  let comment = '';
  if(isTask){
    const ta = document.getElementById('assign-answer-'+testId);
    comment = ta ? ta.value.trim() : '';
    if(!comment){ toast('Опиши, как ты выполнил(а) задание, прежде чем отметить его выполненным','err'); return; }
  }
  if(_assignmentSubmissions.has(testId))return;
  const attemptId=_skillAttemptId;
  const draftKey=quizDraftKey('assignment',testId,_skillDraftKey);
  if(isTask){
    if(new TextEncoder().encode(comment).length>200000){toast('Ответ слишком длинный (максимум 200 КБ). Прикрепите его файлом','err');return;}
    writeQuizDraft(draftKey,{comment});
  }
  _assignmentSubmissions.add(testId);
  const button=document.querySelector('#skill-assignments-wrap .q-btn');
  if(button)button.disabled=true;
  let res;
  try{res=await api.post('/api/tests.php?action=submit',{test_id:testId,attempt_id:attemptId,answers:{},comment});}
  catch(e){toast('Не удалось получить подтверждение. Не закрывайте страницу и повторите отправку','err');return;}
  finally{_assignmentSubmissions.delete(testId);if(button?.isConnected)button.disabled=false;}
  if(attemptId!==_skillAttemptId)return;
  if(res.ok)draftSaver.stop(draftKey);
  if(!res.ok){ toast(res.error||'Ошибка','err'); return; }
  a.done = true;
  if(isTask)a.verdict=null;
  if(res.attempt_completed){
    clearInterval(_skillTimerHandle); _skillTimerHandle=null;
    _skillAttemptCompleted=true;
    requestAnimationFrame(showLevelCompletion);
  }
  if(res.coins_earned>0){
    currentUser.coins=(currentUser.coins||0)+res.coins_earned;
    updateCoinsUI(currentUser.coins);
    toast('+'+res.coins_earned+' коинов');
  } else {
    toast(isTask?'Ответ отправлен на проверку':'Задание отмечено выполненным');
  }
  // Тихо обновляем данные уровней в фоне (для fully_done/прогресса), не трогая
  // текущий экран — loadSkillLevels() переключает видимость skill-levels-wrap/quiz-wrap,
  // а мы должны остаться на этапах уровня
  try {
    const freshLvs = await api.get('/api/skill_levels.php');
    if (freshLvs.ok && freshLvs.levels) {
      window._skillLevelsData = freshLvs.levels;
      window._passedSlugs = freshLvs.levels.filter(l=>l.evaluated_passed).map(l=>l.slug);
      const doneCount = freshLvs.levels.filter(l=>l.fully_done).length;
      const pct = freshLvs.levels.length ? Math.round(doneCount/freshLvs.levels.length*100) : 0;
      const ppPct = document.getElementById('pp-test-pct');
      const ppBar = document.getElementById('pp-test-bar');
      if(ppPct) ppPct.textContent = pct+'%';
      if(ppBar) ppBar.style.width = pct+'%';
    }
  } catch(e){}
  // Возвращаемся к списку этапов уровня — там сразу видно обновлённый статус
  await loadSkillQuizSummary();
  renderLevelSteps();
  if(res.new_achievements && res.new_achievements.length){ showAchievementToast(res.new_achievements); }
}

function renderSkillResult(c){
  if(!_skillAttemptCompleted){
    c.innerHTML='<div class="result-wrap"><p style="margin-bottom: 20px;">Ответы на тест сохранены. Результат будет доступен после завершения хардскилла.</p><button style="margin-right: 20px;" class="q-btn" onclick="nextSkillStage()">перейти к заданию →</button><button class="btn btn-ghost" onclick="renderLevelSteps()">к этапам уровня</button></div>';return;
  }
  const hasPendingAssignment = _currentLevelSteps.some(step=>step.type==='assignment' && !step.data.done);
  const passed = quizState.passed;
  const pct = quizState.maxScore ? Math.round(quizState.score/quizState.maxScore*100) : (passed?100:0);
  c.innerHTML=`<div class="result-wrap">
    ${quizState.reviewStatus==='pending'?'<div style="margin-bottom:20px">на рассмотрении у руководителя</div>':`<div class="result-big">
      <div class="result-score" style="color:${passed?'var(--primary)':'var(--err-text)'}">${quizState.score}</div>
      <div class="result-total">/${quizState.maxScore}</div>
    </div>
    `}<div style="font-size:10px;letter-spacing:.1em;text-transform:uppercase;font-family:'Unbounded',sans-serif;color:${passed?'var(--ok-text)':'var(--err-text)'};margin-bottom:8px">
      ${quizState.reviewStatus==='pending'?'ответы отправлены':(passed?'пройдено ✓':'результат получен')}
    </div>
    <div style="font-family:'Unbounded',sans-serif;font-size:14px;color:var(--muted2);margin-bottom:24px">${quizState.reviewStatus==='pending'?'':pct+'%'}</div>
    ${quizState.coinsEarned>0?`<div class="result-coins">+${quizState.coinsEarned} коинов</div>`:''}
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
      ${hasPendingAssignment?'<button class="q-btn" onclick="nextSkillStage()">перейти к заданию →</button>':''}
      <button class="q-btn" style="background:transparent;border:1px solid var(--border2);color:var(--muted2)" onclick="renderLevelSteps()">← к этапам уровня</button>
    </div>
  </div>`;
}

function selS(id){if(document.getElementById('fb').dataset.locked==='1')return;document.getElementById('fb').innerHTML='';const q=quizData.questions[quizState.cur];quizState.answers[q.id]=[id];document.querySelectorAll('.opt').forEach(e=>e.classList.remove('sel'));document.getElementById('opt'+id).classList.add('sel');saveSkillDraft();}
function togM(id){if(document.getElementById('fb').dataset.locked==='1')return;document.getElementById('fb').innerHTML='';const q=quizData.questions[quizState.cur];const arr=quizState.answers[q.id]||[];const i=arr.indexOf(id);if(i>-1)arr.splice(i,1);else arr.push(id);quizState.answers[q.id]=arr;document.querySelectorAll('.opt').forEach(e=>e.classList.remove('sel'));arr.forEach(x=>document.getElementById('opt'+x)?.classList.add('sel'));saveSkillDraft();}

// Уведомление об ачивке
const achievementQueue=[];
let achievementToastRunning=false;
function showAchievementToast(achievements){
  achievementQueue.push(...(achievements||[]));
  playNextAchievement();
}
function playNextAchievement(){
  if(achievementToastRunning || !achievementQueue.length)return;
  achievementToastRunning=true;
  const ach=achievementQueue.shift(),el=document.createElement('div');
  el.className='game-achievement';el.setAttribute('role','status');
  el.innerHTML=`<div class="game-achievement__icon" aria-hidden="true">${achievementIcon(ach)}</div>
    <div class="game-achievement__text"><div class="game-achievement__label">${escH(ach.title||'Достижение открыто')}</div>
    <div class="game-achievement__title">${escH(ach.description||'Новое достижение получено!')}</div></div>`;
  document.body.appendChild(el);animateAchievement(el);
  setTimeout(()=>{
    el.classList.add('is-leaving');
    setTimeout(()=>{el.remove();achievementToastRunning=false;playNextAchievement();},400);
  },5000);
}
function updateCoinsUI(n){
  n = n || 0;
  ['coins-disp','st-coins','pp-coins','pf-coins'].forEach(function(id){
    var el = document.getElementById(id);
    if(el) el.textContent = n;
  });
  var fill = document.getElementById('coins-fill');
  if(fill) fill.style.width = Math.min(100, Math.round(n/5)) + '%';
}


function escH(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function escAttr(s){return escH(s);}

window.addEventListener('pagehide',()=>{
  document.querySelectorAll('[id^="assign-answer-"]').forEach(ta=>{
    writeQuizDraft(quizDraftKey('assignment',Number(ta.id.replace('assign-answer-','')),_skillDraftKey),{comment:ta.value});
  });
});


function openPasswordReset(){
  document.getElementById('modal-password-reset')?.remove();
  document.body.insertAdjacentHTML('beforeend',`<div class="modal-overlay open" id="modal-password-reset"><div class="modal" style="max-width:460px">
    <h2>Сброс пароля</h2><p>Укажи email аккаунта — отправим код на почту.</p>
    <label>email<input class="open-ta" id="reset-email" type="email" autocomplete="email"></label>
    <button class="btn" id="reset-send" type="button">отправить код</button>
    <div id="reset-fields" hidden><p style="font-size: 12px; margin-top: 10px;">Код действует 10 минут.</p>
      <label>код из письма<input class="open-ta" id="reset-code" inputmode="numeric" autocomplete="one-time-code" maxlength="8"></label>
      <label>новый пароль<input class="open-ta" id="reset-password" type="password" autocomplete="new-password" placeholder="минимум 6 символов"></label>
      <label>повтори пароль<input class="open-ta" id="reset-confirm" type="password" autocomplete="new-password"></label>
      <button class="btn" id="reset-submit" type="button">сохранить пароль</button>
    </div><p id="reset-message" style="margin: 20px 0;" role="status" aria-live="polite"></p>
    <button class="btn btn-ghost" onclick="document.getElementById('modal-password-reset').remove()">закрыть</button>
  </div></div>`);
  const email=document.getElementById('reset-email'), message=document.getElementById('reset-message');email.value=val('le');email.focus();
  document.getElementById('reset-send').onclick=async function(){
    if(!email.value.trim() || !email.checkValidity()){message.textContent='Введи корректный email';return;}
    this.disabled=true;
    try{
      const res=await api.post('/api/auth.php?action=forgot_password',{email:email.value.trim()});
      message.textContent=res.message||res.error||'Не удалось отправить код';
      if(res.ok)document.getElementById('reset-fields').hidden=false;
    }catch(e){message.textContent='Не удалось отправить код. Повтори запрос позже.';}
    finally{this.disabled=false;}
  };
  document.getElementById('reset-submit').onclick=async function(){
    const password=document.getElementById('reset-password').value;
    if(password.length<6){message.textContent='Пароль минимум 6 символов';return;}
    if(password!==document.getElementById('reset-confirm').value){message.textContent='Пароли не совпадают';return;}
    this.disabled=true;
    try{
      const res=await api.post('/api/auth.php?action=reset_password',{email:email.value.trim(),code:document.getElementById('reset-code').value.trim(),password});
      message.textContent=res.message||res.error||'Не удалось изменить пароль';
      if(res.ok){document.getElementById('reset-fields').hidden=true;document.getElementById('reset-send').hidden=true;document.getElementById('lp').value='';email.disabled=true;}
    }catch(e){message.textContent='Не удалось изменить пароль. Повтори запрос позже.';}
    finally{this.disabled=false;}
  };
}
