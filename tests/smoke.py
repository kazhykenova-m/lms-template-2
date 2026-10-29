"""Run against a fresh disposable installation: python3 tests/smoke.py URL"""
import urllib.request, urllib.error, http.cookiejar, json, sys
base=sys.argv[1].rstrip('/')+'/api.php?action='
class Client:
 def __init__(self):
  self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())); self.csrf=''
 def req(self,action,data=None):
  req=urllib.request.Request(base+action,data=None if data is None else json.dumps(data).encode(),headers={'Content-Type':'application/json','X-CSRF-Token':self.csrf})
  try:r=self.opener.open(req)
  except urllib.error.HTTPError as e:r=e
  j=json.load(r)
  if 'csrf' in j:self.csrf=j['csrf']
  return r.status,j
admin=Client();assert admin.req('state')[1]['setup']
assert admin.req('setup',{'name':'Admin','email':'admin@example.com','password':'Testing-Password-2026','role':'Admin'})[0]==200
assert admin.req('brand',{'name':'Test Academy','color':'#246854','logo':''})[0]==200
assert admin.req('user',{'name':'Learner','email':'learner@example.com','password':'Testing-Password-2026','role':'Designer'})[0]==200
course={'title':'Test','description':'Description','material':'Read first','minutes':1,'pass_score':80,'questions':[{'text':'Choose B','options':['A','B'],'correct':1}]}
assert admin.req('course',course)[0]==200
s=admin.req('state')[1];uid=next(u['id'] for u in s['users'] if not u['admin']);cid=s['courses'][0]['id']
assert admin.req('assign',{'course_id':cid,'user_ids':[uid]})[0]==200
student=Client();student.req('state');assert student.req('login',{'email':'learner@example.com','password':'Testing-Password-2026'})[0]==200
s=student.req('state')[1];aid=s['assignments'][0]['id']
assert student.req('course',course)[0]==403
assert student.req('attempt&id='+str(aid))[1]['questions']==[]
a=student.req('start',{'id':aid})[1];assert 'correct' not in a['questions'][0]
assert student.req('submit',{'id':aid,'answers':[]})[0]==400
assert student.req('submit',{'id':aid,'answers':[1]})[0]==200
assert student.req('state')[1]['assignments'][0]['score'] is None
assert student.req('start',{'id':aid})[0]==409
assert student.req('publish',{'id':aid})[0]==403
assert admin.req('publish',{'id':aid})[0]==200
assert student.req('state')[1]['assignments'][0]['score']==100
assert admin.req('assign',{'course_id':cid,'user_ids':[uid]})[0]==200
assert len(student.req('state')[1]['assignments'])==1
assert admin.req('import_users',{'users':[{'name':'Batch','email':'batch@example.com','role':'Role','password':'Testing-Password-2026'},{'name':'Duplicate','email':'learner@example.com','role':'Role','password':'Testing-Password-2026'}]})[0]==400
assert not any(u['email']=='batch@example.com' for u in admin.req('state')[1]['users'])
print('PASS: setup, brand, users, course, assignment, grading, publication, single attempt, permissions, atomic import')
