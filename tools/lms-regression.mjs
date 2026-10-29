import fs from 'node:fs';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import os from 'node:os';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
const root=path.resolve(process.argv[2]||path.join(path.dirname(fileURLToPath(import.meta.url)),'..'));
// Deterministic timers for network race and retry tests.
let now=10000,id=0;const timers=new Map();
const context={setTimeout:(fn,ms)=>{timers.set(++id,{fn,at:now+ms});return id;},clearTimeout:id=>timers.delete(id),Date:{now:()=>now}};
vm.createContext(context);vm.runInContext(fs.readFileSync(path.join(root,'assets/draft-saver.js'),'utf8'),context);
let resolve;const writes=[],statuses=[];
const saver=new context.DraftSaver(async(url,data)=>{writes.push(data);return await new Promise(r=>resolve=r);},(...s)=>statuses.push(s));
saver.queue('a','/draft',{comment:'первый 💪🏻'});
const first=saver.flush('a');
saver.queue('a','/draft',{comment:'новый 🎁'});
await saver.flush('a');assert.equal(writes.length,1,'same draft cannot send concurrent writes');
resolve({ok:true});await first;assert.equal(statuses.at(-1)[1],'pending','old response cannot mark newer edit saved');
const second=saver.flush('a');resolve({ok:true});await second;
assert.equal(writes[1].comment,'новый 🎁');assert.equal(statuses.at(-1)[1],'saved');
let calls=0;const retry=new context.DraftSaver(async()=>{calls++;if(calls===1)throw Error('offline');return {ok:true};},()=>{});
retry.queue('b','/draft',{comment:'до сбоя'});await retry.flush('b');
retry.queue('b','/draft',{comment:'после сбоя'});await retry.flush('b');assert.equal(calls,1,'typing respects network retry backoff');
now+=5000;await retry.flush('b');assert.equal(calls,2);assert.equal(retry.entries.get('b').saved,2);
const locked=new context.DraftSaver(async()=>({ok:false,http_status:409,error:'expired'}),()=>{});
locked.queue('c','/draft',{});await locked.flush('c');assert.equal(locked.entries.get('c').stopped,true);
saver.stop('a');saver.queue('d','/draft',{comment:'other task'});assert.equal(saver.entries.get('a').stopped,true);
console.log('PASS draft ordering, emoji payloads, offline retry, backoff, locked attempt');
// Exercise the actual PHP submission route with isolated storage and mocked DB/side effects.
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'lms-regression-'));
try{
 for(const d of ['api','db'])fs.mkdirSync(path.join(tmp,d));
 for(const f of ['tests.php','api_errors.php'])fs.copyFileSync(path.join(root,'api',f),path.join(tmp,'api',f));
 fs.writeFileSync(path.join(tmp,'config.php'),"<?php define('SESSION_NAME','LMSRegression');");
 for(const f of ['quiz_validation.php','coins_bridge.php','onboarding_completion.php'])fs.writeFileSync(path.join(tmp,'api',f),'<?php');
 fs.writeFileSync(path.join(tmp,'api/access.php'),`<?php
 function ensureLmsSchema(){} function lmsStaff(){return false;} function lmsActor(){return ['id'=>1,'dept_id'=>2,'role_id'=>3];}
 function lmsHideLearningGrades($d){return $d;} function lmsRequireLearningMaterial($t){}
 `);
 fs.writeFileSync(path.join(tmp,'api/hard_skill_attempts.php'),`<?php
 function ensureTestResultsPassedColumn(){} function freezeExpiredAssignmentDrafts($id){} function finalizeHardSkillAttempt($u,$l){return true;}
 `);
 fs.writeFileSync(path.join(tmp,'db/Database.php'),`<?php
 class Database {
 static $pdo;static $rows=[];static $pending=[];
 static function get(){return self::$pdo ??= new FakePDO;}
 static function rollbackActive(){if(self::$pdo && self::$pdo->inTransaction())self::$pdo->rollBack();}
 static function find($sql,$args=[]){
 if(str_contains($sql,'FROM tests'))return ['id'=>9,'content_type'=>'task','skill_level_id'=>4,'onboarding_step_id'=>null,'coins_reward'=>0,'title'=>'ТЗ'];
 if(str_contains($sql,'FROM skill_level_attempts'))return ['id'=>7,'status'=>getenv('LMS_COMPLETE')?'completed':'active','expires_at'=>'2099-01-01','question_ids'=>'[]','assignment_ids'=>'[9]'];
 if(str_contains($sql,'COUNT('))return ['n'=>0];
 if(str_contains($sql,'FROM test_results'))return self::$rows[0] ?? null;
 return ['id'=>1];
 }
 static function findAll($sql,$args=[]){return [];}
 static function insert($table,$data){if(getenv('LMS_FAIL_INSERT'))throw new RuntimeException('simulated insert failure');self::$pending[]=$data;return 1;}
 static function update(...$args){} static function query(...$args){}
 }
 class FakePDO {
 public $active=false;
 function beginTransaction(){$this->active=true;}
 function inTransaction(){return $this->active;}
 function commit(){Database::$rows=array_merge(Database::$rows,Database::$pending);file_put_contents(__DIR__.'/../saved.json',json_encode(Database::$rows));$this->active=false;}
 function rollBack(){Database::$pending=[];$this->active=false;file_put_contents(__DIR__.'/../rolledback','1');}
 }
 `);
 fs.writeFileSync(path.join(tmp,'run.php'),`<?php
 session_save_path(sys_get_temp_dir());session_name('LMSRegression');session_id('lms-regression-'.getmypid());session_start();$_SESSION=['user_id'=>1];session_write_close();
 require __DIR__.'/db/Database.php';
 if(file_exists(__DIR__.'/saved.json'))Database::$rows=json_decode(file_get_contents(__DIR__.'/saved.json'),true);
 $_SERVER['REQUEST_METHOD']='POST';$_GET=['action'=>'submit'];
 $_POST=['test_id'=>9,'attempt_id'=>(int)(getenv('LMS_ATTEMPT')?:7),'answers'=>[],'comment'=>getenv('LMS_COMMENT')?:'ответ 💪🏻 🎁 👇'];
 if(getenv('LMS_LEGACY'))unset($_POST['attempt_id']);
 require __DIR__.'/api/tests.php';
 `);
 const run=(env={})=>{const r=spawnSync('php',[path.join(tmp,'run.php')],{encoding:'utf8',env:{...process.env,...env}});assert.equal(r.status,0,r.stderr);try{return JSON.parse(r.stdout);}catch{throw Error(r.stdout+'\n'+r.stderr);}};
 let result=run();assert.equal(result.ok,true);let saved=JSON.parse(fs.readFileSync(path.join(tmp,'saved.json')));assert.equal(saved.length,1);assert.equal(JSON.parse(saved[0].answers_json).comment,'ответ 💪🏻 🎁 👇');
 result=run({LMS_COMPLETE:'1'});assert.equal(result.ok,true);assert.equal(result.already_submitted,true);assert.equal(result.attempt_completed,true);assert.equal(JSON.parse(fs.readFileSync(path.join(tmp,'saved.json'))).length,1);
 result=run({LMS_LEGACY:'1'});assert.equal(result.ok,true,'already open old clients can still submit');
 result=run({LMS_COMMENT:'другой ответ'});assert.equal(result.ok,false);assert.match(result.error,/Другой ответ/);
 result=run({LMS_ATTEMPT:'8'});assert.equal(result.ok,false);assert.match(result.error,/Попытка изменилась/);
 result=run({LMS_COMMENT:'я'.repeat(100001)});assert.equal(result.ok,false);assert.match(result.error,/слишком длинный/);
 fs.unlinkSync(path.join(tmp,'saved.json'));result=run({LMS_FAIL_INSERT:'1'});assert.equal(result.ok,false);assert.ok(result.error_id);assert.equal(fs.existsSync(path.join(tmp,'saved.json')),false);assert.equal(fs.existsSync(path.join(tmp,'rolledback')),true);
 console.log('PASS task emoji storage, lost-response retry, duplicate prevention, attempt identity, length limit, rollback and JSON error');
 // Exercise the autosave route's issued-question filtering and attempt checks.
 const skill=fs.readFileSync(path.join(root,'api/skill_levels.php'),'utf8');
 const route=skill.slice(skill.indexOf("if ($method === 'POST' && $action === 'draft')"),skill.indexOf('// Save partial answers only for the issued'));
 fs.writeFileSync(path.join(tmp,'draft.php'),`<?php
 session_start();$method='POST';$action='draft';$userId=1;$user=['dept_id'=>2,'role_id'=>3];
 $body=['attempt_id'=>7,'answers'=>[11=>'текст 🎁',12=>[21],999=>'чужой вопрос'],'own_answers'=>[11=>'не разрешён',12=>'свой вариант 💪🏻']];
 function json($d,$c=200){echo json_encode($d);exit;}function lmsRequireSkillLevel($id){if($id!==4)throw new Exception('wrong level');}function lmsAnswerByteLimit($s,$n){return $s;}
 class DraftPDO{function beginTransaction(){}function commit(){}function rollBack(){}}
 class Database{
 static function get(){return new DraftPDO;}
 static function find($sql,$args){
 if(!str_contains($sql,'user_id=?') || $args!==[7,1])throw new Exception('missing owner scope');
 return ['id'=>7,'skill_level_id'=>4,'dept_id'=>getenv('WRONG_OWNER')?8:2,'role_id'=>3,'status'=>getenv('LOCKED')?'completed':'active','expires_at'=>'2099-01-01','question_ids'=>'[11,12]'];
 }
 static function findAll($sql){return str_contains($sql,'FROM questions') ? [['id'=>11,'type'=>'open','allow_own_answer'=>0],['id'=>12,'type'=>'single','allow_own_answer'=>1]] : [['id'=>21,'question_id'=>12]];}
 static function query($sql,$p){file_put_contents(__DIR__.'/draft-saved.json',json_encode($p));}
 }
 `+route);
 const draft=env=>{const out=spawnSync('php',[path.join(tmp,'draft.php')],{encoding:'utf8',env:{...process.env,...env}});assert.equal(out.status,0,out.stderr);return JSON.parse(out.stdout);};
 assert.equal(draft({}).ok,true);
 const payload=JSON.parse(fs.readFileSync(path.join(tmp,'draft-saved.json')));
 assert.deepEqual(JSON.parse(payload[1]),{'11':'текст 🎁','12':[21]});assert.deepEqual(JSON.parse(payload[2]),{'12':'свой вариант 💪🏻'});
 assert.equal(draft({WRONG_OWNER:'1'}).ok,false);assert.equal(draft({LOCKED:'1'}).ok,false);
 console.log('PASS quiz autosave owner and attempt checks, issued questions only, own-answer permissions');
 // Test actual freeze function: old autosave must not win over final browser snapshot.
 const hard=fs.readFileSync(path.join(root,'api/hard_skill_attempts.php'),'utf8');
 const freeze=hard.slice(hard.indexOf('function freezeExpiredAssignmentDrafts'));
 fs.writeFileSync(path.join(tmp,'timer.php'),`<?php
 class TimerPDO {function beginTransaction(){} function commit(){} function rollBack(){}}
 class Database {
 static $inserted=0;
 static function get(){return new TimerPDO;}
 static function find($sql,$p=[]){
 if(str_contains($sql,'SELECT id FROM skill_level_attempts'))return ['id'=>7];
 if(str_contains($sql,'FROM skill_expiry_receipts'))return getenv('RECEIPT')?['attempt_id'=>7]:null;
 return null;
 }
 static function findAll($sql,$p=[]){
 if(str_contains($sql,'FROM skill_level_attempts'))return [['id'=>7,'expires_at'=>date('Y-m-d H:i:s',time()-(getenv('OLD')?180:30)),'status'=>'active','is_current'=>1,'started_at'=>'2026-01-01']];
 if(str_contains($sql,'FROM assignment_drafts'))return [['test_id'=>9,'comment'=>'последний ответ 🎁']];
 return [];
 }
 static function insert($t,$d){self::$inserted++;} static function update(...$a){}
 }
 `+freeze+`\nfreezeExpiredAssignmentDrafts(1);echo Database::$inserted;`);
 for(const [env,count] of [[{},'0'],[{RECEIPT:'1'},'1'],[{OLD:'1'},'1']]){
   const out=spawnSync('php',[path.join(tmp,'timer.php')],{encoding:'utf8',env:{...process.env,...env}});assert.equal(out.status,0,out.stderr);assert.equal(out.stdout,count);
 }
 console.log('PASS expiry waits for final snapshot, freezes receipt immediately, freezes unattended draft after grace');
 // Actual UTF-8 boundary helper, with no DB calls.
 const access=fs.readFileSync(path.join(root,'api/access.php'),'utf8');const helper=access.slice(access.indexOf('function lmsAnswerByteLimit'));
 fs.writeFileSync(path.join(tmp,'utf8.php'),'<?php\n'+helper+`\n$t=str_repeat('я',99999).'🎁';$r=lmsAnswerByteLimit($t,200000);if(strlen($r)>200000 || preg_match('//u',$r)!==1)exit(1);`);
 const utf=spawnSync('php',[path.join(tmp,'utf8.php')],{encoding:'utf8'});assert.equal(utf.status,0,utf.stderr);console.log('PASS UTF-8 byte-boundary truncation');
}finally{fs.rmSync(tmp,{recursive:true,force:true});}
