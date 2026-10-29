// GitHub Pages presentation mode. No server, accounts or real company data.
(()=>{
const key='forma-pages-demo-v1';
const seed=()=>({brand:{name:'Forma LMS',color:'#635bff',logo:''},users:[{id:1,name:'Александра Смирнова',email:'admin@demo.local',role:'Администратор',admin:1},{id:2,name:'Данияр Алиев',email:'employee@demo.local',role:'Дизайнер',admin:0},{id:3,name:'Анна Ким',email:'anna@demo.local',role:'Маркетолог',admin:0}],courses:[{id:1,title:'Информационная безопасность',description:'Защитите свои данные и рабочее пространство. Базовые правила для каждого.',material:'Используйте уникальные пароли и двухфакторную аутентификацию. Не передавайте пароли коллегам. Проверяйте отправителя перед открытием ссылок.',minutes:15,pass_score:80,questions:[{text:'Как защитить рабочий аккаунт?',options:['Один пароль везде','Двухфакторная аутентификация','Передать пароль коллеге'],correct:1},{text:'Что делать с подозрительной ссылкой?',options:['Открыть сразу','Проверить отправителя','Переслать всем'],correct:1}]},{id:2,title:'Коммуникация в команде',description:'Как давать обратную связь и договариваться о результате.',material:'Формулируйте обратную связь на основе фактов. Вместе договаривайтесь о следующих шагах.',minutes:20,pass_score:80,questions:[{text:'На чём строится обратная связь?',options:['На фактах','На слухах'],correct:0}]}],assignments:[{id:1,user_id:2,course_id:1,status:'assigned',score:null,published:0},{id:2,user_id:3,course_id:2,status:'completed',score:100,published:0}],uid:1});
let db;try{db=JSON.parse(localStorage.getItem(key))||seed()}catch(e){db=seed()}
const save=()=>{try{localStorage.setItem(key,JSON.stringify(db))}catch(e){}};
window.demoSwitch=()=>{db.uid=db.uid===1?2:1;save();location.reload()};window.demoReset=()=>{if(confirm('Сбросить демонстрационные изменения?')){localStorage.removeItem(key);location.reload()}};
const original=window.fetch.bind(window);
window.fetch=async(url,options={})=>{
if(!String(url).startsWith('api.php?'))return original(url,options);
const params=new URL(String(url),location.href).searchParams,action=params.get('action'),b=JSON.parse(options.body||'{}');
let result={ok:true};const user=db.users.find(u=>u.id===db.uid);const next=rows=>Math.max(0,...rows.map(x=>x.id))+1;
const rows=()=>db.assignments.filter(a=>user?.admin||a.user_id===user?.id).map(a=>{const c=db.courses.find(c=>c.id===a.course_id),u=db.users.find(u=>u.id===a.user_id);return {...a,title:c.title,description:c.description,minutes:c.minutes,pass_score:c.pass_score,name:u.name,role:u.role,score:user?.admin||a.published?a.score:null}});
try{
if(action==='state'){const courses=db.courses.map(({questions,material,...c})=>c);result={ok:true,csrf:'demo',setup:false,user,brand:db.brand,courses,assignments:rows(),...(user?.admin?{users:db.users}:{})}}
else if(action==='logout')db.uid=null;
else if(action==='login'){db.uid=b.email==='admin@demo.local'?1:2}
else if(action==='brand')db.brand={...b};
else if(action==='user'){if(db.users.some(u=>u.email===b.email))throw Error('Email уже используется');db.users.push({id:next(db.users),name:b.name,email:b.email,role:b.role,admin:0})}
else if(action==='import_users'){for(const u of b.users){if(db.users.some(x=>x.email===u.email))throw Error('Email уже используется');db.users.push({id:next(db.users),name:u.name,email:u.email,role:u.role,admin:0})}}
else if(action==='course'){if(!b.questions?.length)throw Error('Добавьте вопросы');db.courses.push({...b,id:next(db.courses)})}
else if(action==='assign'){if(!b.user_ids.length)throw Error('Выберите сотрудников');for(const uid of b.user_ids)if(!db.assignments.some(a=>a.user_id===uid&&a.course_id===b.course_id))db.assignments.push({id:next(db.assignments),user_id:uid,course_id:b.course_id,status:'assigned',score:null,published:0})}
else if(action==='publish'){db.assignments.find(a=>a.id===b.id).published=1}
else if(['attempt','start','submit'].includes(action)){
const a=db.assignments.find(a=>a.id===Number(params.get('id')||b.id)&&a.user_id===user?.id);if(!a)throw Error('Назначение не найдено');const c=db.courses.find(c=>c.id===a.course_id);
if(a.status==='active'&&a.deadline<=Date.now()){a.status='expired';a.score=0}
if(!['assigned','active'].includes(a.status))throw Error('Попытка завершена');
if(action==='start'&&a.status==='assigned'){a.status='active';a.deadline=Date.now()+c.minutes*60000}
if(action==='submit'){if(a.status!=='active')throw Error('Сначала начните тест');a.score=Math.round(100*c.questions.filter((q,i)=>q.correct===b.answers[i]).length/c.questions.length);a.status='completed'}
else result={ok:true,id:a.id,title:c.title,material:c.material,status:a.status,questions:a.status==='active'?c.questions.map(({correct,...q})=>q):[],remaining:Math.max(0,Math.floor((a.deadline-Date.now())/1000))};
}else throw Error('Действие недоступно в демо');save();
}catch(e){result={ok:false,error:e.message}}
return new Response(JSON.stringify(result),{headers:{'Content-Type':'application/json'}})
};
})();
