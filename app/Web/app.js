'use strict';

const TOKEN = document.querySelector('meta[name="app-token"]').content;

const state = {
    settings: {},
    catalog: {},
    recipes: [],
    paths: {},
    runtime: {},
    privacy: {},
    session: null,
    profile: null,
    lookupProfile: null,
    recipe: null,
    validation: null,
    runJob: null,
    runRecipe: null,
    pollTimer: null,
    pendingPrompt: null,
    viewRecipe: null,
};

// ------------------------------------------------------------------ утилиты

function el(id) {
    return document.getElementById(id);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

async function api(action, options = {}) {
    const url = new URL('/api/', window.location.origin);
    url.searchParams.set('action', action);
    for (const [key, value] of Object.entries(options.query || {})) {
        url.searchParams.set(key, value);
    }

    const init = { method: options.method || (options.form ? 'POST' : 'GET'), headers: { 'X-App-Token': TOKEN } };
    if (options.form) {
        init.method = 'POST';
        init.body = options.form;
    } else if (options.body !== undefined) {
        init.method = 'POST';
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(options.body);
    }

    const response = await fetch(url, init);
    const text = await response.text();
    let data;
    try {
        data = JSON.parse(text);
    } catch (error) {
        throw new Error('Сервер вернул неожиданный ответ: ' + text.slice(0, 300));
    }

    if (!response.ok && data.ok !== false) {
        throw new Error(data.error || ('Ошибка ' + response.status));
    }

    return data;
}

function notice(container, type, title, text) {
    container.innerHTML = '<div class="notice ' + type + '"><strong>' + escapeHtml(title) + '</strong>' + (text || '') + '</div>';
}

function showProgress(percent, message) {
    const area = el('run-area');
    const bar = area.querySelector('.progress > div');
    if (bar) {
        bar.style.width = Math.max(0, Math.min(100, percent)) + '%';
    }
    const label = area.querySelector('.progress-label');
    if (label) {
        label.textContent = message || '';
    }
}

// ------------------------------------------------------------------ вкладки

document.querySelectorAll('nav button').forEach((button) => {
    button.addEventListener('click', () => {
        document.querySelectorAll('nav button').forEach((b) => b.classList.remove('active'));
        button.classList.add('active');
        ['library', 'create', 'help', 'settings', 'about'].forEach((tab) => {
            el('tab-' + tab).classList.toggle('hidden', tab !== button.dataset.tab);
        });
    });
});

// ------------------------------------------------------------------ запуск

async function bootstrap() {
    const data = await api('bootstrap');
    state.settings = data.settings;
    state.catalog = data.catalog;
    state.recipes = data.recipes;
    state.paths = data.paths;
    state.runtime = data.runtime || {};
    state.privacy = data.privacy;

    el('version').textContent = 'версия ' + data.version;
    el('storage').innerHTML = 'Данные: <code>' + escapeHtml(data.paths.data) + '</code>'
        + (data.paths.portable ? ' · портативный режим' : ' · резервный режим');

    renderLibrary();
    renderSettings();
    renderStorage();
    renderAbout();
}

// ------------------------------------------------------------------ библиотека

function collectAliases(recipe) {
    const aliases = new Set();
    for (const step of recipe.steps || []) {
        for (const key of ['file', 'template']) {
            if (typeof step[key] === 'string' && step[key] !== '') {
                aliases.add(step[key]);
            }
        }
    }
    return [...aliases];
}

function renderLibrary() {
    const query = (el('search').value || '').toLowerCase().trim();
    const container = el('recipe-cards');
    container.innerHTML = '';

    const filtered = state.recipes.filter((item) => {
        if (!query) {
            return true;
        }
        const haystack = [item.name, item.description, (item.tags || []).join(' ')].join(' ').toLowerCase();
        return haystack.includes(query);
    });

    el('library-empty').classList.toggle('hidden', state.recipes.length !== 0);

    for (const item of filtered) {
        const card = document.createElement('div');
        card.className = 'card';

        const badges = [];
        badges.push(item.uses_network
            ? '<span class="badge">требует интернет</span>'
            : '<span class="badge offline">работает офлайн</span>');
        if (item.uses_ai) {
            badges.push('<span class="badge ai">обращается к нейросети</span>');
        }
        if (item.runs_count) {
            badges.push('<span class="badge">запусков: ' + item.runs_count + '</span>');
        }

        card.innerHTML =
            '<h3>' + escapeHtml(item.name) + '</h3>' +
            '<div class="desc">' + escapeHtml(item.description || 'Без описания') + '</div>' +
            '<div class="meta">' + badges.join('') + '</div>' +
            '<div class="actions">' +
            '<button class="primary" data-run="' + escapeHtml(item.id) + '">Запустить</button>' +
            (item.has_sample
                ? '<button class="ghost" data-sample="' + escapeHtml(item.id) + '">Образец</button>'
                : '') +
            '<button class="ghost" data-export="' + escapeHtml(item.id) + '">Экспорт</button>' +
            '<button class="ghost danger" data-delete="' + escapeHtml(item.id) + '">Удалить</button>' +
            '</div>';

        container.appendChild(card);
    }

    container.querySelectorAll('[data-run]').forEach((button) => {
        button.addEventListener('click', () => openRun(button.dataset.run, false));
    });
    container.querySelectorAll('[data-sample]').forEach((button) => {
        button.addEventListener('click', () => openSample(button.dataset.sample));
    });
    container.querySelectorAll('[data-export]').forEach((button) => {
        button.addEventListener('click', () => downloadFile('/api/?action=recipe.export&id=' + encodeURIComponent(button.dataset.export)));
    });
    container.querySelectorAll('[data-delete]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!confirm('Удалить сценарий из библиотеки?')) {
                return;
            }
            const data = await api('recipe.delete', { body: { id: button.dataset.delete } });
            state.recipes = data.recipes;
            if (state.viewRecipe === button.dataset.delete) {
                closeRecipeView();
            }
            renderLibrary();
        });
    });
}

function closeRecipeView() {
    state.viewRecipe = null;
    el('recipe-view').innerHTML = '';
}

function showViewError(title, message) {
    el('recipe-view').innerHTML = '<div class="panel"><h2>' + escapeHtml(title) + '</h2>'
        + '<div class="notice error"><strong>Не получилось</strong>' + escapeHtml(message) + '</div>'
        + '<div class="actions"><button class="ghost" id="btn-view-close">Закрыть</button></div></div>';
    el('btn-view-close').addEventListener('click', closeRecipeView);
}

async function openSample(recipeId) {
    const area = el('recipe-view');
    const entry = state.recipes.find((item) => item.id === recipeId);
    const title = 'Образец: ' + (entry ? entry.name : recipeId);

    state.viewRecipe = recipeId;
    area.innerHTML = '<div class="panel"><h2>' + escapeHtml(title) + '</h2>'
        + '<div class="notice info"><strong>Читаю файл…</strong>На большом файле это займёт несколько секунд.</div></div>';
    area.scrollIntoView({ behavior: 'smooth' });

    let data;
    try {
        data = await api('recipe.sample_profile', { query: { id: recipeId } });
    } catch (error) {
        showViewError(title, error.message);
        return;
    }

    const sample = data.sample || {};
    const extraSamples = data.extra_samples || [];

    let extraHtml = '';
    if (extraSamples.length) {
        extraHtml = '<div class="notice info"><strong>Сценарию нужен второй файл</strong>'
            + 'Вместе со сценарием сохранён файл-справочник: '
            + extraSamples.map((file) => escapeHtml(file.name)).join(', ')
            + '. При запуске приложите его вместе с таблицей.'
            + '<div class="actions">'
            + extraSamples.map((file, index) => '<button class="ghost" data-extra-sample="' + index + '">Скачать '
                + escapeHtml(file.name) + '</button>').join('')
            + '</div></div>';
    }

    area.innerHTML = '<div class="panel"><h2>' + escapeHtml(title) + '</h2>'
        + '<p class="hint">Это файл, который сохранился вместе со сценарием при его создании. '
        + 'На нём сценарий проверялся, и по нему видно, с какими данными он работал.</p>'
        + extraHtml
        + '<div id="sample-summary"></div>'
        + '<details open><summary>Колонки</summary><div class="table-wrap" style="margin-top:8px"><table id="sample-columns"></table></div></details>'
        + '<details><summary>Первые строки</summary><div class="table-wrap" style="margin-top:8px"><table id="sample-samples"></table></div></details>'
        + '<div class="actions">'
        + '<button class="primary" id="btn-sample-download">Скачать файл образца</button>'
        + '<button class="ghost" id="btn-view-close">Закрыть</button>'
        + '</div></div>';

    renderProfile(data.profile, { summary: 'sample-summary', columns: 'sample-columns', samples: 'sample-samples' });

    area.querySelectorAll('[data-extra-sample]').forEach((button) => {
        button.addEventListener('click', () => {
            const file = extraSamples[Number(button.dataset.extraSample)] || {};
            downloadFile('/api/?action=file.download&path=' + encodeURIComponent(file.path || ''));
        });
    });

    el('btn-sample-download').addEventListener('click', () => {
        downloadFile('/api/?action=file.download&path=' + encodeURIComponent(sample.path || ''));
    });
    el('btn-view-close').addEventListener('click', closeRecipeView);
}

async function downloadFile(url, options = {}) {
    const response = await fetch(url, { headers: { 'X-App-Token': TOKEN } });
    if (!response.ok) {
        const text = await response.text();
        let message = text.slice(0, 200);
        try {
            message = JSON.parse(text).error || message;
        } catch (ignored) {
            // ответ не в формате JSON — оставляем текст как есть
        }
        if (options.strict) {
            throw new Error(message);
        }
        alert('Не удалось получить файл: ' + message);
        return;
    }
    const blob = await response.blob();
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = /filename\*=UTF-8''([^;]+)/i.exec(disposition);
    const name = match ? decodeURIComponent(match[1]) : 'download';

    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = name;
    link.click();
    URL.revokeObjectURL(link.href);
}

async function openRun(recipeId, dryRun) {
    const entry = state.recipes.find((item) => item.id === recipeId);
    if (!entry) {
        return;
    }

    const full = await api('recipe.get', { query: { id: recipeId } });
    const recipe = full.recipe_entry.recipe;
    state.runRecipe = recipeId;

    const aliases = collectAliases(recipe);
    const params = recipe.params || [];
    const area = el('run-area');

    let html = '<div class="panel"><h2>' + escapeHtml(dryRun ? 'Проверка: ' : 'Запуск: ') + escapeHtml(entry.name) + '</h2>';
    html += '<p class="hint">' + escapeHtml(entry.description || '') + '</p>';

    if (!dryRun) {
        const fileTitles = recipe.files || {};
        html += '<h3>Файлы для обработки</h3><div class="row">';
        for (const alias of aliases) {
            // Подпись файла можно задать в сценарии ("files"); иначе — по псевдониму
            const title = fileTitles[alias]
                ? escapeHtml(String(fileTitles[alias]))
                : escapeHtml(alias === 'template' ? 'Шаблон' : 'Файл данных') + ' (' + escapeHtml(alias) + ')';
            html += '<div><label>' + title + '</label>'
                + '<input type="file" data-alias="' + escapeHtml(alias) + '" accept=".xlsx,.xls,.csv"></div>';
        }
        html += '</div>';
    }

    if (params.length) {
        html += '<h3>Параметры</h3><div class="row">';
        for (const param of params) {
            html += '<div><label>' + escapeHtml(param.label || param.name) + '</label>'
                + '<input type="text" data-param="' + escapeHtml(param.name) + '" value="' + escapeHtml(param.default ?? '') + '"></div>';
        }
        html += '</div>';
    }

    html += '<div class="actions"><button class="primary" id="btn-start-run">'
        + (dryRun ? 'Проверить' : 'Запустить') + '</button>'
        + '<button class="ghost" id="btn-close-run">Закрыть</button></div>'
        + '<div class="progress"><div></div></div>'
        + '<div class="progress-label hint"></div>'
        + '<div class="log" id="run-log"></div>'
        + '<div id="run-result"></div></div>';

    area.innerHTML = html;
    area.scrollIntoView({ behavior: 'smooth' });

    el('btn-close-run').addEventListener('click', () => {
        if (state.pollTimer) {
            clearInterval(state.pollTimer);
            state.pollTimer = null;
        }
        area.innerHTML = '';
    });

    el('btn-start-run').addEventListener('click', () => startRun(recipeId, dryRun));
}

async function startRun(recipeId, dryRun) {
    const area = el('run-area');
    const form = new FormData();
    form.append('recipe_id', recipeId);
    if (dryRun) {
        form.append('dry_run', '1');
    }

    area.querySelectorAll('[data-alias]').forEach((input) => {
        if (input.files && input.files[0]) {
            form.append('file_' + input.dataset.alias, input.files[0]);
        }
    });

    const params = {};
    area.querySelectorAll('[data-param]').forEach((input) => {
        params[input.dataset.param] = input.value;
    });
    form.append('params', JSON.stringify(params));

    el('run-log').innerHTML = '';
    el('run-result').innerHTML = '';
    showProgress(2, 'Задание отправлено…');

    let data;
    try {
        data = await api('run.start', { form });
    } catch (error) {
        showProgress(0, '');
        notice(el('run-result'), 'error', 'Не удалось запустить', escapeHtml(error.message));
        return;
    }

    state.runJob = data.job_id;
    pollRun(data.job_id, dryRun);
}

function pollRun(jobId, dryRun) {
    if (state.pollTimer) {
        clearInterval(state.pollTimer);
    }

    const tick = async () => {
        let data;
        try {
            data = await api('run.status', { query: { job_id: jobId } });
        } catch (error) {
            return;
        }

        const status = data.status || {};
        const progress = status.progress || {};
        showProgress(progress.percent || (status.state === 'done' ? 100 : 0), progress.message || status.state || '');

        const log = el('run-log');
        if (log) {
            log.innerHTML = (status.log || [])
                .map((entry) => '<div class="' + escapeHtml(entry.level) + '">[' + escapeHtml(entry.time || '') + '] ' + escapeHtml(entry.message) + '</div>')
                .join('');
            log.scrollTop = log.scrollHeight;
        }

        if (status.state === 'done' || status.state === 'error' || status.state === 'missing') {
            clearInterval(state.pollTimer);
            state.pollTimer = null;
            showProgress(status.state === 'error' ? 0 : 100, status.state === 'done' ? 'Готово' : '');
            await showRunResult(jobId, status, dryRun);
        }
    };

    state.pollTimer = setInterval(tick, 1200);
    tick();
}

async function showRunResult(jobId, status, dryRun) {
    const result = el('run-result');
    if (!result) {
        return;
    }

    if (status.state === 'error') {
        notice(result, 'error', 'Обработка остановлена', escapeHtml(status.error || 'Неизвестная ошибка'));
        return;
    }

    const summary = status.summary || {};
    let html = '<div class="notice ok"><strong>Готово за ' + escapeHtml(status.duration ?? '?') + ' с</strong>';
    html += 'Строк в результате: ' + escapeHtml(summary.rows ?? 0)
        + ', файлов: ' + escapeHtml(summary.files ?? 0)
        + ', ошибок: ' + escapeHtml(summary.errors ?? 0) + '</div>';

    result.innerHTML = html;

    const files = await api('job.files', { query: { job_id: jobId } });
    if (files.total > 0) {
        let list = '<h3>Файлы результата (' + files.total + ')</h3><ul class="file-list">';
        for (const file of files.files.slice(0, 200)) {
            list += '<li><span>' + escapeHtml(file.relative) + '</span><span>' + escapeHtml(file.size_human)
                + ' <a href="#" data-download="' + escapeHtml(file.path) + '">скачать</a></span></li>';
        }
        list += '</ul><div class="actions"><button class="ghost" id="btn-download-zip">Скачать всё архивом</button></div>';
        result.insertAdjacentHTML('beforeend', list);

        result.querySelectorAll('[data-download]').forEach((link) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();
                downloadFile('/api/?action=file.download&path=' + encodeURIComponent(link.dataset.download));
            });
        });
        el('btn-download-zip').addEventListener('click', () => downloadFile('/api/?action=job.zip&job_id=' + encodeURIComponent(jobId)));
    }

    if (dryRun && status.outputs) {
        result.insertAdjacentHTML('beforeend', '<p class="hint">Это проверка: файлы не создавались.</p>');
    }
}

// ------------------------------------------------------------------ новая задача

el('btn-analyze').addEventListener('click', async () => {
    const file = el('sample-file').files[0];
    if (!file) {
        notice(el('analyze-status'), 'warn', 'Выберите файл', 'Нужен файл-образец для анализа.');
        return;
    }

    const lookupFile = el('lookup-file').files[0];
    notice(el('analyze-status'), 'info', 'Читаю файл…', '');

    const form = new FormData();
    form.append('sample', file);
    if (lookupFile) {
        form.append('lookup', lookupFile);
    }

    try {
        const data = await api('session.create', { form });
        state.session = data.session_id;
        state.profile = data.profile;
        state.lookupProfile = data.lookup_profile || null;
        renderProfile(data.profile);
        renderPrivacyNotice(data.privacy, data.provider_configured);
        el('profile-panel').classList.remove('hidden');
        el('chat-panel').classList.remove('hidden');

        // Второй файл: показываем его структуру, чтобы было видно, что он прочитан
        if (state.lookupProfile) {
            renderProfile(state.lookupProfile, {
                summary: 'lookup-summary',
                columns: 'lookup-columns',
                samples: 'lookup-samples',
            });
            el('lookup-panel').classList.remove('hidden');
        } else {
            el('lookup-panel').classList.add('hidden');
        }

        notice(el('analyze-status'), 'ok', 'Файл прочитан', state.lookupProfile
            ? 'Структура определена. Файл-справочник тоже прочитан — его колонки можно использовать в задаче.'
            : 'Структура определена, можно описывать задачу.');
    } catch (error) {
        notice(el('analyze-status'), 'error', 'Не удалось прочитать файл', escapeHtml(error.message));
    }
});

function renderProfile(profile, containers = {}) {
    const summaryId = containers.summary || 'profile-summary';
    const columnsId = containers.columns || 'profile-columns';
    const samplesId = containers.samples || 'profile-samples';

    const summary = [
        'Файл: <b>' + escapeHtml(profile.file.name) + '</b> (' + escapeHtml(profile.file.size_human) + ')',
        'Листов: ' + (profile.sheets || []).length,
        'Основной лист: <b>' + escapeHtml(profile.main_sheet) + '</b>',
        'Строка заголовков: <b>' + escapeHtml(profile.header_row) + '</b>',
        'Данные с строки: <b>' + escapeHtml(profile.data_from_row) + '</b>',
    ];
    el(summaryId).innerHTML = '<p class="hint">' + summary.join(' · ') + '</p>';

    let columns = '<tr><th>Колонка</th><th>Заголовок</th><th>Тип</th><th>Заполнено</th><th>Уникальных</th><th>Примеры</th></tr>';
    for (const column of profile.columns || []) {
        const extras = [];
        if (column.urls) {
            extras.push('ссылок: ' + column.urls);
        }
        for (const [separator, count] of Object.entries(column.separators || {})) {
            extras.push('разделитель «' + separator + '»: ' + count);
        }
        columns += '<tr><td>' + escapeHtml(column.letter) + '</td><td>' + escapeHtml(column.header) + '</td><td>'
            + escapeHtml(column.type) + (extras.length ? '<br><span class="badge">' + escapeHtml(extras.join(', ')) + '</span>' : '')
            + '</td><td>' + escapeHtml(column.filled) + '</td><td>' + escapeHtml(column.distinct) + '</td><td>'
            + escapeHtml((column.sample || []).join(' | ')) + '</td></tr>';
    }
    el(columnsId).innerHTML = columns;

    const rows = profile.sample_rows || [];
    const letters = [...new Set(rows.flatMap((row) => Object.keys(row)))].sort();
    let samples = '<tr><th>#</th>' + letters.map((letter) => '<th>' + escapeHtml(letter) + '</th>').join('') + '</tr>';
    rows.forEach((row, index) => {
        samples += '<tr><td>' + (index + 1) + '</td>' + letters.map((letter) => '<td>' + escapeHtml(row[letter] || '') + '</td>').join('') + '</tr>';
    });
    el(samplesId).innerHTML = samples;
}

function renderPrivacyNotice(privacy, providerConfigured) {
    const container = el('privacy-notice');

    if (!providerConfigured) {
        notice(container, 'warn', 'Не настроено подключение к нейросети',
            'Откройте вкладку «Настройки», укажите адрес сервиса и модель.');
        el('btn-generate').disabled = true;
        return;
    }

    el('btn-generate').disabled = false;

    if (privacy.level === 'local') {
        notice(container, 'ok', 'Данные не покидают компьютер',
            'Модель работает локально (' + escapeHtml(privacy.host) + '). Образец никуда не отправляется.');
    } else if (privacy.level === 'private') {
        notice(container, 'info', 'Данные остаются во внутренней сети',
            'Адрес ' + escapeHtml(privacy.host) + ' находится во внутренней сети компании.');
    } else {
        notice(container, 'warn', 'Внимание: внешний сервис',
            'Для составления сценария структура и примеры строк образца будут отправлены в <b>'
            + escapeHtml(privacy.host) + '</b>. Документ целиком не передаётся, но отправляемые значения вы увидите перед подтверждением.');
    }
}

el('btn-restart').addEventListener('click', () => {
    state.session = null;
    state.profile = null;
    state.lookupProfile = null;
    state.recipe = null;
    el('profile-panel').classList.add('hidden');
    el('lookup-panel').classList.add('hidden');
    el('chat-panel').classList.add('hidden');
    el('recipe-panel').classList.add('hidden');
    el('save-panel').classList.add('hidden');
    el('analyze-status').innerHTML = '';
    el('chat').innerHTML = '';
    el('prompt').value = '';
    el('sample-file').value = '';
    el('lookup-file').value = '';
    setPromptMode('task');
});

const DEFAULT_PROMPT_LABEL = 'Описание задачи';
const DEFAULT_PROMPT_PLACEHOLDER = 'Например: сохрани все изображения из колонок G и H в папки по артикулу из колонки B. Ссылок в ячейке может быть несколько через точку с запятой.';

/**
 * Переключение поля ввода между «описанием задачи» и «ответом на вопросы модели».
 * Без этого после вопроса модели пользователю негде ответить.
 */
function setPromptMode(mode) {
    const label = el('prompt-label');
    const prompt = el('prompt');
    const hint = el('prompt-hint');
    const button = el('btn-generate');

    if (mode === 'answer') {
        label.textContent = 'Ваш ответ';
        prompt.placeholder = 'Ответьте на вопросы выше — например: «код товара в колонке A, ссылку ставь в B»';
        prompt.value = '';
        prompt.focus();
        button.textContent = 'Отправить уточнение';
        notice(hint, 'warn', 'Модели нужны уточнения',
            'Напишите ответ в поле ниже и нажмите «Отправить уточнение». Описание задачи сохранится — уточнение отправится вместе с ним.');
        return;
    }

    label.textContent = DEFAULT_PROMPT_LABEL;
    prompt.placeholder = DEFAULT_PROMPT_PLACEHOLDER;
    button.textContent = 'Составить сценарий';
    hint.innerHTML = '';
}

function chatMessage(role, text) {
    const chat = el('chat');
    const div = document.createElement('div');
    div.className = 'msg ' + role;
    div.textContent = text;
    chat.appendChild(div);
    chat.scrollTop = chat.scrollHeight;
}

el('btn-generate').addEventListener('click', () => generate(false));

async function generate(confirmed) {
    const prompt = el('prompt').value.trim();
    if (!prompt) {
        chatMessage('error', 'Опишите задачу текстом.');
        return;
    }

    if (!confirmed) {
        chatMessage('user', prompt);
    }
    chatMessage('assistant', 'Составляю сценарий…');
    el('btn-generate').disabled = true;

    try {
        const data = await api('session.generate', {
            body: { session_id: state.session, prompt, confirm_external: !!confirmed },
        });

        if (data.need_confirmation) {
            state.pendingPrompt = prompt;
            showConfirmModal(data.privacy);
            return;
        }

        const chat = el('chat');
        chat.removeChild(chat.lastChild);

        if (data.recipe === null) {
            chatMessage('assistant', 'Нужны уточнения, чтобы составить сценарий.');
            for (const question of data.questions || []) {
                chatMessage('assistant', '— ' + question);
            }
            // Переключаем поле ввода в режим ответа, иначе отвечать негде
            setPromptMode('answer');
            return;
        }

        state.recipe = data.recipe;
        state.validation = data.validation;
        chatMessage('assistant', data.explanation || 'Сценарий составлен.');
        for (const question of data.questions || []) {
            chatMessage('assistant', '— ' + question);
        }
        setPromptMode('task');

        renderRecipe(data);
        el('recipe-panel').classList.remove('hidden');
        el('dryrun-result').innerHTML = '';
        el('save-panel').classList.add('hidden');
    } catch (error) {
        const chat = el('chat');
        if (chat.lastChild && chat.lastChild.textContent === 'Составляю сценарий…') {
            chat.removeChild(chat.lastChild);
        }
        chatMessage('error', error.message);
    } finally {
        el('btn-generate').disabled = false;
    }
}

function showConfirmModal(privacy) {
    el('confirm-body').innerHTML =
        '<div class="notice warn"><strong>Куда уйдут данные</strong>' + escapeHtml(privacy.host || '') + '</div>' +
        '<p class="hint">Будет отправлено: строка заголовков, список колонок с типами и значениями-примерами, '
        + 'количество строк. Сам файл не передаётся.</p>' +
        '<details open><summary>Что именно отправится</summary><pre class="json">'
        + escapeHtml(state.profile ? JSON.stringify({
            columns: (state.profile.columns || []).map((c) => c.letter + ' «' + c.header + '»: ' + (c.sample || []).join(' | ')),
            sample_rows: state.profile.sample_rows || [],
        }, null, 2) : '') + '</pre></details>';
    el('confirm-modal').classList.remove('hidden');
}

el('btn-cancel-send').addEventListener('click', () => {
    el('confirm-modal').classList.add('hidden');
    const chat = el('chat');
    if (chat.lastChild && chat.lastChild.textContent === 'Составляю сценарий…') {
        chat.removeChild(chat.lastChild);
    }
});

el('btn-confirm-send').addEventListener('click', async () => {
    el('confirm-modal').classList.add('hidden');
    await generate(true);
});

function renderRecipe(data) {
    el('recipe-explanation').innerHTML = '<div class="notice info"><strong>Что будет сделано</strong>'
        + escapeHtml(data.explanation || '') + '</div>';

    const validation = data.validation;
    if (validation) {
        const parts = [];
        for (const error of validation.errors || []) {
            parts.push('Ошибка: ' + error);
        }
        for (const warning of validation.warnings || []) {
            parts.push('Предупреждение: ' + warning);
        }
        if (validation.uses_network) {
            parts.push('Сценарий обращается к сети.');
        }
        if (parts.length) {
            notice(el('recipe-validation'), validation.ok ? 'warn' : 'error',
                validation.ok ? 'Замечания' : 'Сценарий требует исправления', escapeHtml(parts.join(' ')));
        } else {
            notice(el('recipe-validation'), 'ok', 'Проверка пройдена', 'Сценарий корректен.');
        }
    }

    el('recipe-json').textContent = JSON.stringify(data.recipe, null, 2);

    if (data.recipe) {
        el('save-name').value = data.recipe.name || '';
        el('save-description').value = data.recipe.description || '';
    }
}

el('btn-dryrun').addEventListener('click', async () => {
    const container = el('dryrun-result');
    notice(container, 'info', 'Проверяю на образце…', '');
    el('btn-dryrun').disabled = true;

    try {
        const data = await api('session.dryrun', { body: { session_id: state.session, recipe: state.recipe } });

        if (!data.ok) {
            notice(container, 'error', 'Проверка не прошла', escapeHtml(data.error || ''));
            return;
        }

        const summary = data.summary || {};
        let html = '<div class="notice ok"><strong>Проверка выполнена за ' + escapeHtml(data.duration) + ' с</strong>'
            + 'Строк: ' + escapeHtml(summary.rows ?? 0) + ', файлов: ' + escapeHtml(summary.files ?? 0)
            + ', ошибок: ' + escapeHtml(summary.errors ?? 0) + '</div>';

        const preview = data.preview || {};
        if ((preview.rows || []).length) {
            html += '<h3>Результат на образце (первые ' + preview.shown_rows + ' из ' + preview.total_rows + ')</h3>';
            html += '<div class="table-wrap"><table><tr><th>#</th>'
                + (preview.columns || []).map((column) => '<th>' + escapeHtml(column) + '</th>').join('') + '</tr>';
            preview.rows.forEach((row, index) => {
                html += '<tr><td>' + (index + 1) + '</td>'
                    + (preview.columns || []).map((column) => '<td>' + escapeHtml(row[column] || '') + '</td>').join('') + '</tr>';
            });
            html += '</table></div>';
        }

        if ((preview.planned || []).length) {
            html += '<h3>Будет создано (' + preview.planned_total + ')</h3><ul class="file-list">';
            for (const item of preview.planned) {
                html += '<li><span>' + escapeHtml(item.path) + '</span><span>' + escapeHtml(item.kind) + '</span></li>';
            }
            html += '</ul>';
        }

        if ((data.logs || []).length) {
            html += '<details><summary>Журнал проверки</summary><div class="log">'
                + data.logs.map((entry) => '<div class="' + escapeHtml(entry.level) + '">[' + escapeHtml(entry.time) + '] '
                    + escapeHtml(entry.message) + '</div>').join('') + '</div></details>';
        }

        container.innerHTML = html;
        el('save-panel').classList.remove('hidden');
    } catch (error) {
        notice(container, 'error', 'Проверка не выполнена', escapeHtml(error.message));
    } finally {
        el('btn-dryrun').disabled = false;
    }
});

el('btn-save').addEventListener('click', async () => {
    const status = el('save-status');
    const name = el('save-name').value.trim();
    if (!name) {
        notice(status, 'warn', 'Укажите название', '');
        return;
    }

    el('btn-save').disabled = true;
    try {
        const data = await api('session.save', {
            body: {
                session_id: state.session,
                recipe: state.recipe,
                name,
                description: el('save-description').value.trim(),
                tags: el('save-tags').value.split(',').map((tag) => tag.trim()).filter(Boolean),
                created_by_ai: true,
                provider: state.privacy.host || '',
            },
        });
        state.recipes = data.recipes;
        renderLibrary();
        notice(status, 'ok', 'Сценарий сохранён', 'Он появился в библиотеке и готов к запуску на реальных файлах.');
    } catch (error) {
        notice(status, 'error', 'Не удалось сохранить', escapeHtml(error.message));
    } finally {
        el('btn-save').disabled = false;
    }
});

// ------------------------------------------------------------------ настройки

function renderSettings() {
    const provider = state.settings.provider || {};
    el('provider-url').value = provider.base_url || '';
    el('provider-model').value = provider.model || '';
    el('provider-timeout').value = provider.timeout || 600;
    el('provider-maxtokens').value = provider.max_tokens || 16384;
    el('provider-key').placeholder = provider.api_key_set
        ? 'ключ сохранён: ' + provider.api_key_hint
        : 'ключ хранится только на этом компьютере';

    const privacy = state.settings.privacy || {};
    el('privacy-rows').value = privacy.sample_rows ?? 3;
    el('privacy-confirm').value = privacy.confirm_external ? '1' : '0';
    el('privacy-mask').value = privacy.mask_values ? '1' : '0';

    renderPrivacyBanner();
}

function renderPrivacyBanner() {
    const level = state.privacy.level;
    const container = el('privacy-banner');
    const text = 'Адрес: <b>' + escapeHtml(state.privacy.host || 'не задан') + '</b>';

    if (level === 'local') {
        notice(container, 'ok', 'Локальная модель', text + '. Данные не покидают компьютер.');
    } else if (level === 'private') {
        notice(container, 'info', 'Внутренняя сеть', text + '. Данные остаются в компании.');
    } else {
        notice(container, 'warn', 'Внешний сервис', text + '. При составлении сценария образец будет отправлен третьей стороне — приложение предупредит об этом.');
    }
}

/** Текущие значения полей подключения (могут быть ещё не сохранены). */
function currentProvider() {
    return {
        base_url: el('provider-url').value.trim(),
        model: el('provider-model').value.trim(),
        api_key: el('provider-key').value.trim(),
        timeout: parseInt(el('provider-timeout').value, 10) || 600,
        max_tokens: parseInt(el('provider-maxtokens').value, 10) || 16384,
    };
}

el('btn-save-settings').addEventListener('click', async () => {
    const status = el('settings-status');
    try {
        const data = await api('settings.save', { body: { provider: currentProvider() } });
        state.settings = data.settings;
        state.privacy = data.privacy;
        el('provider-key').value = '';
        renderSettings();
        notice(status, 'ok', 'Настройки сохранены', 'Ключ остаётся на этом компьютере.');
    } catch (error) {
        notice(status, 'error', 'Не удалось сохранить', escapeHtml(error.message));
    }
});

el('btn-models').addEventListener('click', async () => {
    const status = el('settings-status');
    notice(status, 'info', 'Запрашиваю список моделей…', '');
    el('btn-models').disabled = true;

    try {
        const data = await api('settings.models', { body: { provider: currentProvider() } });

        if (!data.ok) {
            notice(status, 'error', 'Не удалось получить список моделей', escapeHtml(data.error || ''));
            return;
        }

        const list = el('model-options');
        list.innerHTML = '';
        for (const model of data.models) {
            const option = document.createElement('option');
            option.value = model;
            list.appendChild(option);
        }

        const usedBase = data.base_url && data.base_url !== el('provider-url').value.trim()
            ? ' Рабочий адрес: <b>' + escapeHtml(data.base_url) + '</b> — его стоит указать в поле адреса.'
            : '';

        notice(status, 'ok', 'Найдено моделей: ' + data.models.length,
            'Начните вводить название модели в поле — появятся подсказки.' + usedBase);
    } catch (error) {
        notice(status, 'error', 'Ошибка запроса', escapeHtml(error.message));
    } finally {
        el('btn-models').disabled = false;
    }
});

el('btn-test').addEventListener('click', async () => {
    const status = el('settings-status');
    notice(status, 'info', 'Проверяю подключение…', '');
    el('btn-test').disabled = true;

    try {
        const data = await api('settings.test', { body: { provider: currentProvider() } });

        if (data.ok) {
            const usedBase = data.base_url && data.base_url !== el('provider-url').value.trim()
                ? ' Рабочий адрес: <b>' + escapeHtml(data.base_url) + '</b> — сохраните его в поле адреса, чтобы не проверять заново.'
                : '';

            notice(status, 'ok', 'Подключение работает',
                'Модель ' + escapeHtml(data.model || '') + ' ответила: '
                + escapeHtml((data.content || '').slice(0, 200)) + '.' + usedBase);
        } else {
            notice(status, 'error', 'Подключение не работает', escapeHtml(data.error || ''));
        }
    } catch (error) {
        notice(status, 'error', 'Ошибка проверки', escapeHtml(error.message));
    } finally {
        el('btn-test').disabled = false;
    }
});

el('btn-save-privacy').addEventListener('click', async () => {
    const status = el('privacy-status');
    try {
        await api('settings.save', {
            body: {
                privacy: {
                    sample_rows: parseInt(el('privacy-rows').value, 10) || 0,
                    confirm_external: el('privacy-confirm').value === '1',
                    mask_values: el('privacy-mask').value === '1',
                },
            },
        });
        const data = await api('bootstrap');
        state.settings = data.settings;
        state.privacy = data.privacy;
        renderPrivacyBanner();
        notice(status, 'ok', 'Сохранено', '');
    } catch (error) {
        notice(status, 'error', 'Не удалось сохранить', escapeHtml(error.message));
    }
});

function renderStorage() {
    const paths = state.paths;
    el('storage-details').innerHTML =
        '<p class="hint">Все данные приложения — сценарии, образцы, результаты, журналы — хранятся в одном каталоге. '
        + 'За его пределами приложение ничего не создаёт.</p>'
        + '<table class="kv"><tr><th>Параметр</th><th>Значение</th></tr>'
        + '<tr><td>Каталог программы</td><td>' + escapeHtml(paths.root) + '</td></tr>'
        + '<tr><td>Каталог данных</td><td>' + escapeHtml(paths.data) + '</td></tr>'
        + '<tr><td>Режим</td><td>' + (paths.portable ? 'портативный — внутри каталога программы' : 'резервный — в профиле пользователя') + '</td></tr>'
        + '</table>'
        + '<div class="notice info"><strong>Перенос программы</strong>'
        + 'Чтобы перенести приложение на другой компьютер, скопируйте каталог программы целиком вместе с папкой данных.'
        + '</div>'
        + '<div class="notice warn"><strong>Что учесть</strong>'
        + 'Не размещайте программу в папке, синхронизируемой с облаком (например, OneDrive в «Документах»), '
        + 'если не хотите, чтобы данные уходили в облако. Не размещайте её в «Program Files» — запись туда запрещена.'
        + '</div>';
}

el('search').addEventListener('input', renderLibrary);

el('btn-import').addEventListener('click', () => el('import-file').click());
el('import-file').addEventListener('change', async () => {
    const file = el('import-file').files[0];
    if (!file) {
        return;
    }
    const form = new FormData();
    form.append('archive', file);
    try {
        const data = await api('recipe.import', { form });
        state.recipes = data.recipes;
        renderLibrary();
        alert('Сценарий «' + (data.recipe_entry.meta?.name || '') + '» добавлен в библиотеку.');
    } catch (error) {
        alert('Не удалось загрузить сценарий: ' + error.message);
    }
    el('import-file').value = '';
});

// ------------------------------------------------------------------ о программе

function diagnosticsLines() {
    const runtime = state.runtime || {};
    const paths = state.paths || {};

    return [
        'Программа: Макросыч ' + (runtime.program || '—'),
        'PHP: ' + (runtime.php || '—') + (runtime.bits ? ' (' + runtime.bits + ' бит)' : ''),
        'Система: ' + (runtime.system || '—'),
        'Каталог программы: ' + (paths.root || '—'),
        'Каталог данных: ' + (paths.data || '—') + (paths.portable ? ' (портативный режим)' : ' (резервный режим)'),
        'Сценариев в библиотеке: ' + (state.recipes || []).length,
        'Дата и время: ' + new Date().toLocaleString('ru-RU'),
    ];
}

function diagnosticsText() {
    return diagnosticsLines().join('\n');
}

function renderAbout() {
    const runtime = state.runtime || {};
    const paths = state.paths || {};

    el('about-version').textContent = runtime.program || '—';

    const rows = [
        ['Программа', 'Макросыч ' + (runtime.program || '—')],
        ['PHP', (runtime.php || '—') + (runtime.bits ? ' · ' + runtime.bits + ' бит' : '')],
        ['Система', runtime.system || '—'],
        ['Каталог программы', paths.root || '—'],
        ['Каталог данных', (paths.data || '—') + (paths.portable ? ' · портативный режим' : ' · резервный режим')],
        ['Сценариев в библиотеке', String((state.recipes || []).length)],
    ];

    el('about-diagnostics').innerHTML = '<table class="kv">'
        + rows.map(([title, value]) => '<tr><th>' + escapeHtml(title) + '</th><td>' + escapeHtml(value) + '</td></tr>').join('')
        + '</table>';
}

async function copyToClipboard(text) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch (error) {
        const area = document.createElement('textarea');
        area.value = text;
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();

        let copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (ignored) {
            copied = false;
        }
        document.body.removeChild(area);

        return copied;
    }
}

el('btn-about-copy-diag').addEventListener('click', async () => {
    const copied = await copyToClipboard(diagnosticsText());
    notice(el('about-status'), copied ? 'ok' : 'warn', copied ? 'Сведения скопированы' : 'Не удалось скопировать', '');
});

el('btn-about-backup').addEventListener('click', async () => {
    const status = el('about-backup-status');
    notice(status, 'info', 'Собираю архив…', 'Если данных много, это займёт несколько секунд.');
    el('btn-about-backup').disabled = true;

    try {
        await downloadFile('/api/?action=data.backup', { strict: true });
        notice(status, 'ok', 'Копия сохранена',
            'Архив со сценариями, настройками и готовыми файлами скачан в папку загрузок. '
            + 'В нём есть и файл настроек с ключом доступа к нейросети — храните архив отдельно от папки программы.');
    } catch (error) {
        notice(status, 'error', 'Не удалось создать копию', escapeHtml(error.message));
    } finally {
        el('btn-about-backup').disabled = false;
    }
});

el('btn-about-open').addEventListener('click', async () => {
    try {
        const data = await api('data.open', { body: {} });
        notice(el('about-backup-status'), 'info', 'Папка открыта', escapeHtml(data.data || ''));
    } catch (error) {
        notice(el('about-backup-status'), 'error', 'Не удалось открыть папку', escapeHtml(error.message));
    }
});

bootstrap().catch((error) => {
    document.querySelector('main').innerHTML =
        '<div class="panel"><h2>Не удалось запустить приложение</h2><div class="notice error">'
        + escapeHtml(error.message) + '</div></div>';
});
