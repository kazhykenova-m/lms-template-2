/* Customer-neutral interface layered over the ver-29-10 test and review engine. */
window.platformSettings={name:'Forma LMS',tagline:'Знания, которые помогают расти',welcome:'Обучение, практика и достижения вашей команды.',color:'#635bff',background:'#f5f6fa',logo:''};
function applyPlatformSettings(settings){
 window.platformSettings={...window.platformSettings,...settings};const s=window.platformSettings;
 document.title=s.name+(location.pathname.startsWith('/admin')?' · Администрирование':'');
 document.documentElement.style.setProperty('--primary',s.color);document.documentElement.style.setProperty('--primary-hover',s.color);document.documentElement.style.setProperty('--template-bg',s.background);
 document.querySelectorAll('.logo-img').forEach(img=>{img.src=s.logo||'/assets/logo-template.svg';img.alt=s.name;img.style.display='block'});
 document.querySelectorAll('[data-platform-tagline]').forEach(el=>el.textContent=s.tagline);
 document.querySelectorAll('[data-platform-welcome]').forEach(el=>el.textContent=s.welcome);
 document.querySelectorAll('.sb-brand small').forEach(el=>el.textContent=s.name);
}
async function loadPlatformSettings(){try{const r=await fetch('/api/platform.php');const j=await r.json();if(j.ok)applyPlatformSettings(j.settings)}catch(e){}}
// Theme changes preserve the customer's logo.
window.applyLogoTheme=()=>applyPlatformSettings(window.platformSettings);
loadPlatformSettings();
if(!location.pathname.startsWith('/admin')){
 window.initChat=()=>{};
 window.loadLearningPage=async()=>{};
 window.loadStandards=async()=>{};
 window.updateCoinsUI=()=>{};
 window.enterApp=function(){
  const u=currentUser;const set=(id,value)=>{const el=document.getElementById(id);if(el)el.textContent=value};
  set('sb-av',initials(u.first_name,u.last_name));set('sb-name',u.first_name+' '+u.last_name);set('sb-role',u.is_admin?'Администратор':'Пользователь');set('sb-dept','');
  set('pf-av',initials(u.first_name,u.last_name));set('pf-name',u.first_name+' '+u.last_name);set('pf-role',u.is_admin?'Администратор':'Пользователь');set('pf-email',u.email);set('pf-date',new Date(u.created_at).toLocaleDateString('ru-RU'));set('test-ey','Обучение и практика');
  if(u.avatar)applyAvatar(u);if(u.is_admin&&!document.querySelector('[data-template-admin-nav]')){addAdminNav();document.querySelector('.sb-nav')?.lastElementChild?.setAttribute('data-template-admin-nav','')}
  goScreen('app');let page='test';try{const saved=localStorage.getItem('lms_last_page');if(['test','results','profile'].includes(saved))page=saved}catch(e){}
  navTo(page,document.querySelector('.sb-nav .nav-item[onclick*="navTo(\''+page+'\'"]'));applyPlatformSettings(window.platformSettings);
 };
 window.applyLevelUI=()=>{};
 window.renderLevelsProgress=async()=>{};
 window.loadProfileExtras=async function(){
  const el=document.getElementById('pf-achievements');el.textContent='Загрузка достижений…';
  try{const r=await api.get('/api/achievements.php');if(!r.ok)throw Error(r.error||'Ошибка загрузки');el.innerHTML=(r.achievements||[]).map(a=>'<article class="template-ach '+(a.earned?'earned':'')+'"><div class="template-ach-icon">'+achievementIcon(a)+'</div><strong>'+escH(a.title)+'</strong><p>'+escH(a.description)+'</p><span>'+(a.earned?'Получено '+new Date(a.earned_at).toLocaleDateString('ru-RU'):'Пока не получено')+'</span></article>').join('')||'<p>Достижения появятся после настройки администратором.</p>'}catch(e){el.textContent='Не удалось загрузить достижения'}
 };
}
const originalApplyPlatformSettings=window.applyPlatformSettings;
window.applyPlatformSettings=function(s){originalApplyPlatformSettings(s);const data=window.platformSettings;
 const testTitle=document.querySelector('#page-test .pg-title');if(testTitle)testTitle.textContent=data.tests_title||'Мои обучения';const resultTitle=document.querySelector('#page-results .pg-title');if(resultTitle)resultTitle.textContent=data.results_title||'Мои результаты';
 const box=document.querySelector('#screen-login .auth-box');if(box){let support=document.getElementById('platform-support');if(!support){support=document.createElement('p');support.id='platform-support';support.className='pg-sub';box.append(support)}support.replaceChildren();if(data.support_email){const a=document.createElement('a');a.href='mailto:'+data.support_email;a.textContent='Помощь: '+data.support_email;support.append(a)}}
};
