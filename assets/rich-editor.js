// Лёгкий WYSIWYG-редактор без внешних библиотек.
// Превращает <textarea id="..."> в панель инструментов + contenteditable-блок,
// синхронизируя HTML обратно в textarea.value — весь остальной код (сохранение форм,
// чтение .value) не меняется и продолжает работать как раньше.
(function () {
  var BUTTONS = [
    ['bold', 'Ж', 'жирный'],
    ['italic', 'К', 'курсив'],
    ['underline', 'П', 'подчёркнутый'],
    ['formatBlock:H3', 'H3', 'заголовок'],
    ['formatBlock:P', '¶', 'абзац'],
    ['insertUnorderedList', '• список', 'маркированный список'],
    ['insertOrderedList', '1. список', 'нумерованный список'],
    ['formatBlock:BLOCKQUOTE', '" цитата', 'цитата'],
    ['link', '🔗', 'вставить ссылку'],
    ['image', '🖼', 'вставить картинку по ссылке'],
    ['removeFormat', '✕', 'очистить форматирование'],
  ];

  function makeBtn(label, title, onClick) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = label;
    btn.title = title || '';
    btn.style.cssText = 'padding:4px 9px;background:var(--dark3);border:1px solid var(--border2);color:var(--text);cursor:pointer;font-size:11px;white-space:nowrap';
    // preventDefault на mousedown — иначе клик по кнопке снимает выделение текста
    btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
    btn.addEventListener('click', onClick);
    return btn;
  }

  window.initRichEditor = function (textareaId) {
    var ta = document.getElementById(textareaId);
    if (!ta) return;

    if (ta.dataset.richInit === '1') {
      // Уже инициализирован — просто подхватываем текущее значение textarea
      var existing = document.getElementById(textareaId + '-rte');
      if (existing) existing.innerHTML = ta.value || '';
      return;
    }
    ta.dataset.richInit = '1';

    var wrap = document.createElement('div');
    wrap.className = 'rte-wrap';
    wrap.style.cssText = 'border:1px solid var(--border2)';

    var toolbar = document.createElement('div');
    toolbar.style.cssText = 'display:flex;gap:4px;flex-wrap:wrap;padding:6px;background:var(--dark3);border-bottom:1px solid var(--border2)';

    var editable = document.createElement('div');
    editable.id = textareaId + '-rte';
    editable.contentEditable = 'true';
    editable.style.cssText = 'min-height:' + ((ta.rows || 6) * 22) + 'px;max-height:440px;overflow-y:auto;padding:12px;font-size:13px;line-height:1.7;color:var(--text);background:var(--dark3);outline:none';
    editable.innerHTML = ta.value || '';

    function sync() { ta.value = editable.innerHTML; }

    function exec(cmd, val) {
      editable.focus();
      if (cmd === 'link') {
        var url = prompt('Ссылка (https://...)');
        if (!url) return;
        document.execCommand('createLink', false, url);
      } else if (cmd === 'image') {
        var imgUrl = prompt('Ссылка на картинку (https://...)');
        if (!imgUrl) return;
        document.execCommand('insertImage', false, imgUrl);
        editable.querySelectorAll('img').forEach(function (img) {
          if (!img.style.maxWidth) img.style.maxWidth = '100%';
        });
      } else {
        document.execCommand(cmd, false, val);
      }
      sync();
    }

    BUTTONS.forEach(function (b) {
      var cmd = b[0], label = b[1], title = b[2];
      toolbar.appendChild(makeBtn(label, title, function () {
        if (cmd.indexOf('formatBlock:') === 0) exec('formatBlock', cmd.split(':')[1]);
        else exec(cmd);
      }));
    });

    // Переключатель в режим «код» — редактировать HTML напрямую (для сложных вставок)
    var codeMode = false;
    var codeBtn = makeBtn('</> код', 'редактировать как HTML-код', function () {
      codeMode = !codeMode;
      if (codeMode) {
        sync();
        editable.style.display = 'none';
        ta.style.display = 'block';
        ta.style.cssText = 'display:block;width:100%;min-height:' + editable.offsetHeight + 'px;background:var(--dark3);border:none;color:var(--text);padding:12px;font-family:monospace;font-size:12px;outline:none;resize:vertical';
        codeBtn.style.background = 'var(--primary)';
        codeBtn.style.color = '#fff';
      } else {
        editable.innerHTML = ta.value || '';
        ta.style.display = 'none';
        editable.style.display = 'block';
        codeBtn.style.background = 'var(--dark3)';
        codeBtn.style.color = 'var(--text)';
      }
    });
    codeBtn.style.marginLeft = 'auto';
    toolbar.appendChild(codeBtn);

    editable.addEventListener('input', sync);
    editable.addEventListener('blur', sync);

    ta.style.display = 'none';
    ta.parentNode.insertBefore(wrap, ta);
    wrap.appendChild(toolbar);
    wrap.appendChild(editable);
    wrap.appendChild(ta);
  };

  // Программно задать содержимое (сброс формы / загрузка на редактирование) —
  // обновляет и textarea, и видимый contenteditable-блок.
  window.setRichEditorValue = function (textareaId, html) {
    var ta = document.getElementById(textareaId);
    if (ta) ta.value = html || '';
    var editable = document.getElementById(textareaId + '-rte');
    if (editable) editable.innerHTML = html || '';
  };
})();
