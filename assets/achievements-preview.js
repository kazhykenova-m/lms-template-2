// SVG для тестовой страницы. Редактируй содержимое каждой иконки здесь.
const previewIconPaths = {
  'Уровень завершён': `<path d="M19 12H10v8c0 9 6 14 14 14m21-22h9v8c0 9-6 14-14 14" stroke="#E05500" stroke-width="5"/><path d="M18 8h28v16c0 11-6 18-14 18s-14-7-14-18V8Zm11 32h6v12h-6zM20 52h24v6H20z" fill="#E05500"/><path d="m25 24 5 5 10-11" stroke="white" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>`,
  'Добро пожаловать': `<g fill="#E05500"><path d="m4 22 28-14 28 14-28 14L4 22Zm12 12 16 8 16-8v12c-10 8-22 8-32 0V34Z"/><path d="M57 25v22" stroke="#E05500" stroke-width="4" stroke-linecap="round"/><circle cx="57" cy="49" r="4"/></g>`,
  'Мастер инструментов': `<g stroke="#E05500" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"><path d="m12 12 40 40M8 19 19 8m-3 20L28 16M12 52l28-28"/><path d="M40 24c-9-10-1-21 9-18l-7 7 9 9 7-7c3 10-8 18-18 9Z" fill="#E05500" stroke-width="2"/></g>`,
  'Профессионал': `<path d="M23 20v-7h18v7" stroke="#E05500" stroke-width="5" stroke-linejoin="round"/><rect x="7" y="20" width="50" height="35" rx="6" fill="#E05500"/><path d="M9 32q23 12 46 0" stroke="white" stroke-width="3"/><rect x="28" y="32" width="8" height="10" rx="2" fill="white"/>`,
  'Путь пройден': `<path fill="#E05500" d="M21 36C27 17 43 7 58 6c-1 15-11 31-30 37l-7-7ZM21 28l-12 3-5 14 17-4Zm15 15-3 17 14-5 3-12ZM19 43C10 44 6 50 5 59c9-1 15-5 16-14Z"/><circle cx="42" cy="22" r="6" fill="white"/>`,
  'Первые коины': `<g fill="#E05500"><rect x="6" y="34" width="29" height="21" rx="5"/><ellipse cx="20.5" cy="34" rx="14.5" ry="6"/><path d="M7 42q13 7 27 0M7 49q13 7 27 0" fill="none" stroke="white" stroke-width="2"/><circle cx="43" cy="25" r="18" stroke="white" stroke-width="3"/><path d="M48 18h-7a4 4 0 0 0 0 8h4a4 4 0 0 1 0 8h-8m6-20v24" fill="none" stroke="white" stroke-width="3" stroke-linecap="round"/></g>`,
  'Состоятельный': `<path d="m5 23 12-13h30l12 13-27 33L5 23Z" fill="#E05500"/><path d="M6 23h52M23 11l-6 12 15 32 15-32-6-12" stroke="white" stroke-width="2" stroke-linejoin="round"/>`,
  'Junior': `<path fill="#E05500" d="M31 37C12 40 5 27 7 13c16-1 27 8 24 24Zm2-9C31 12 44 5 58 8c1 16-10 25-25 20Z"/><path d="M32 54V31M19 55h26" stroke="#E05500" stroke-width="5" stroke-linecap="round"/>`,
  'Middle': `<path fill="#E05500" d="M36 5 12 36h18l-5 23 27-34H34l7-20Z"/>`,
  'Senior': `<path d="M19 12H10v8c0 9 6 14 14 14m21-22h9v8c0 9-6 14-14 14" stroke="#E05500" stroke-width="5"/><path d="M18 8h28v16c0 11-6 18-14 18s-14-7-14-18V8Zm11 32h6v12h-6zM20 52h24v6H20z" fill="#E05500"/><path d="m32 15 3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1Z" fill="white"/>`,
};
const previewCards = [...document.querySelectorAll('.preview-achievement')];
function replayAchievement(card){
  card.classList.remove('is-animating');
  void card.offsetWidth;
  card.classList.add('is-animating');
}
previewCards.forEach(card=>{
  const sparks=document.createElement('div');
  sparks.className='achievement-sparks';
  sparks.setAttribute('aria-hidden','true');
  for(let i=0;i<18;i++){
    const angle=(i/18)*Math.PI*2;
    const spark=document.createElement('i');
    spark.className='achievement-spark';
    spark.style.setProperty('--spark-x',Math.round(Math.cos(angle)*(140+(i%3)*25))+'px');
    spark.style.setProperty('--spark-y',Math.round(Math.sin(angle)*110+30)+'px');
    spark.style.setProperty('--spark-turn',(i*43)+'deg');
    spark.style.setProperty('--spark-delay',(400+(i%4)*35)+'ms');
    sparks.appendChild(spark);
  }
  card.appendChild(sparks);
  const title=card.dataset.achievementTitle.trim().replace(/[!.\s]+$/g,'');
  const paths=previewIconPaths[title] || previewIconPaths['Уровень завершён'];
  card.querySelector('.game-achievement__icon').innerHTML=card.dataset.iconSvg || `<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64" fill="none">${paths}</svg>`;
  card.closest('.preview-item').querySelector('.preview-replay').addEventListener('click',()=>replayAchievement(card));
});
document.getElementById('preview-replay-all').addEventListener('click',()=>previewCards.forEach(replayAchievement));
// Каждая карточка анимируется, когда появляется в области просмотра.
if ('IntersectionObserver' in window){
  const observer=new IntersectionObserver(entries=>entries.forEach(entry=>{
    if(entry.isIntersecting){replayAchievement(entry.target);observer.unobserve(entry.target);}
  }),{threshold:.25});
  previewCards.forEach(card=>observer.observe(card));
}else previewCards.forEach(replayAchievement);
