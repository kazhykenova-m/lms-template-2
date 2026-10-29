/* Общая сводка для сотрудника и руководителя. */
window.ResultsDashboard = (() => {
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function answerWithFiles(value){
  const text=String(value ?? '');
  const pattern=/https?:\/\/[^\s<>"']+|\/storage\/uploads\/[^\s<>"']+|\/api\/download_attachment\.php\?[^\s<>"']+/g;
  let html='',last=0;
  for(const match of text.matchAll(pattern)){
    html+=escape(text.slice(last,match.index));
    let url;
    try{url=new URL(match[0],window.location.origin);}catch(e){}
    if(url && ['http:','https:'].includes(url.protocol) && (url.pathname.startsWith('/storage/uploads/') || (url.hostname===window.location.hostname && url.pathname==='/api/download_attachment.php'))){
      // Normalize legacy HTTP links for files hosted on this site.
      if(url.hostname===window.location.hostname){url=new URL(url.pathname+url.search+url.hash,window.location.origin);}
      let href=url.href;
      if(url.origin===window.location.origin && /\.txt$/i.test(url.pathname)){
        try{href='/api/text_attachment.php?file='+encodeURIComponent(decodeURIComponent(url.pathname.split('/').pop()));}catch(e){}
      }
      html+='<a href="'+escape(href)+'" target="_blank" rel="noopener noreferrer" style="color:var(--primary);text-decoration:underline">Открыть файл ↗</a>';
    }else html+=escape(match[0]);
    last=match.index+match[0].length;
  }
  return html+escape(text.slice(last));
}
  function status(row) {
    if (row.ready === false) return 'На проверке';
    return row.passed ? 'Пройдено' : 'Не пройдено';
  }
  function card(row) {
    const pending = row.ready === false, task = row.content_type === 'task';
    const score = Math.max(0, Number(row.score) || 0), max = Math.max(0, Number(row.max_score) || 0);
    const label = pending ? 'На проверке' : task ? status(row) : `${score} / ${max} · ${status(row)}`;
    const fraction = pending ? 0 : task ? (row.passed ? 1 : 0) : max ? Math.min(1, score / max) : 0;
    const color = pending ? 'var(--primary-hover)' : row.passed ? 'var(--ok-text)' : 'var(--err-text)';
    const ringColor = pending ? 'var(--primary-hover)' : task ? color : 'var(--primary)';
    const center = pending
      ? '<text x="56" y="58" text-anchor="middle" fill="var(--muted2)" font-size="25">…</text>'
      : task
        ? `<text x="56" y="58" text-anchor="middle" fill="${color}" font-size="30" font-weight="700">${row.passed ? '✓' : '×'}</text>`
        : `<text x="56" y="57" text-anchor="middle" fill="var(--text)" font-size="27" font-weight="700">${score}<tspan fill="var(--muted2)" font-size="15" font-weight="400">/${max}</tspan></text>`;
    const caption = pending ? 'на проверке' : task ? 'ТЗ' : max ? 'правильно' : 'нет вопросов';
    const chart = `<svg width="112" height="112" viewBox="0 0 112 112" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="${escape(label)}" class="result-summary-chart">
      <circle cx="56" cy="56" r="46" fill="none" stroke="var(--border)" stroke-width="8"/>
      ${fraction > 0 ? `<circle cx="56" cy="56" r="46" fill="none" stroke="${ringColor}" stroke-width="8" pathLength="100" stroke-dasharray="${100*fraction} 100" stroke-linecap="round" transform="rotate(-90 56 56)"/>` : ''}
      ${center}<text x="56" y="75" text-anchor="middle" fill="var(--muted2)" font-size="10">${caption}</text>
    </svg>`;
    return `<article class="result-summary-card"><div class="result-summary-title">${escape(row.title)}</div><div class="result-summary-kind">${task ? 'ТЗ' : 'Тест'}${row.completed_at ? ' · '+escape(row.completed_at) : ''}</div>${chart}<strong style="color:${color}">${escape(label)}</strong></article>`;
  }
  function detail(row, options = {}) {
    const answerLabel = escape(options.answerLabel || 'Ваш ответ');
    const body = row.content_type === 'task'
      ? `<div class="result-answer"><strong>${answerLabel}:</strong><br>${answerWithFiles(row.comment)}</div>`
      : (row.answers || []).map(a => `<div class="result-answer"><strong>${escape(a.question)}</strong><p>${answerLabel}: ${answerWithFiles(a.answer)}</p><span>${a.correct == null ? 'На проверке' : a.correct ? 'Верно' : 'Неверно'}</span></div>`).join('');
    return `<details class="result-detail"><summary>${escape(row.title)} · ${escape(status(row))}</summary>${body}</details>`;
  }
  function render(rows, options = {}) {
    const sections = options.employeeOrder
      ? [['hard_quiz','Тесты'],['tasks','Практические задания']]
      : [['hard','Тесты и задания']];
    return sections.map(([key,title]) => {
      const group = rows.filter(r => key === 'hard_quiz' ? r.category === 'hard' && r.content_type !== 'task' : key === 'tasks' ? r.category !== 'onboarding' && r.content_type === 'task' : r.category === key).sort((a,b) => String(b.completed_at || '').localeCompare(String(a.completed_at || '')) || Number(b.id)-Number(a.id));
      return `<section class="results-category"><h2>${title}</h2>${group.length ? `<div class="results-summary-grid">${group.map(card).join('')}</div>${options.details ? '<h3 class="results-details-title">Детали оценки</h3>'+group.map(row => detail(row, options)).join('') : ''}` : '<p class="results-empty">Результатов пока нет.</p>'}</section>`;
    }).join('');
  }
  return {render};
})();
