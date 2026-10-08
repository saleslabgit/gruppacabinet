// Only the response to an authorized payment start POST carries this marker.
document.querySelectorAll('form[data-webpay-auto-submit]').forEach(form => {
    HTMLFormElement.prototype.submit.call(form);
});

document.querySelectorAll('form[data-prototype-form]').forEach(form => {
    form.addEventListener('submit', event => event.preventDefault());
});
document.querySelectorAll('[data-noop]').forEach(button => {
    button.addEventListener('click', () => {
        const feedback = document.getElementById('prototype-feedback');
        if (feedback) feedback.textContent = 'Прототип: действие показано для примера. Данные не изменены.';
        const modal = button.closest('.modal');
        if (modal) bootstrap.Modal.getInstance(modal)?.hide();
    });
});
document.querySelectorAll('[data-copy]').forEach(button => {
    button.addEventListener('click', async () => {
        const value = document.getElementById(button.dataset.copy).textContent.trim();
        const feedback = document.getElementById('copy-feedback');
        try {
            await navigator.clipboard.writeText(value);
            feedback.textContent = 'ID скопирован';
        } catch {
            feedback.textContent = 'Не удалось скопировать автоматически. Выделите ID и скопируйте его вручную.';
        }
    });
});
document.querySelectorAll('[data-prototype-open]').forEach(modal => new bootstrap.Modal(modal).show());

// Keep navigation available without JavaScript; collapse it only after enhancement.
document.querySelectorAll('.admin-navigation').forEach(navigation => {
    const toggle = document.getElementById('admin-menu-toggle');
    const menu = navigation.querySelector('.sidebar');
    const close = () => {
        toggle.setAttribute('aria-expanded', 'false');
        menu.classList.remove('is-open');
        navigation.classList.remove('is-open');
    };
    navigation.classList.add('enhanced');
    toggle.classList.add('enhanced');
    toggle.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', String(open));
        menu.classList.toggle('is-open', open);
        navigation.classList.toggle('is-open', open);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            close();
            toggle.focus();
        }
    });
    document.addEventListener('click', event => {
        if (!navigation.contains(event.target) && !toggle.contains(event.target)) close();
    });
});

// Native selects remain the form values and the no-JavaScript fallback.
document.querySelectorAll('select[data-custom-select]').forEach(select => {
    if (select.multiple || select.size > 1) return;
    const wrapper = document.createElement('div');
    wrapper.className = 'custom-select';
    select.before(wrapper);
    wrapper.append(select);
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.id = `${select.id}-trigger`;
    trigger.className = 'form-select select-trigger';
    trigger.setAttribute('role', 'combobox');
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.setAttribute('aria-labelledby', `${select.id}-label`);
    trigger.setAttribute('aria-describedby', select.getAttribute('aria-describedby'));
    const label = document.createElement('span');
    const arrow = document.createElement('i');
    arrow.className = 'bi bi-chevron-down';
    arrow.setAttribute('aria-hidden', 'true');
    trigger.append(label, arrow);
    const list = document.createElement('ul');
    list.id = `${select.id}-options`;
    list.className = 'select-options';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-labelledby', `${select.id}-label`);
    list.hidden = true;
    trigger.setAttribute('aria-controls', list.id);
    wrapper.append(trigger, list);
    const options = Array.from(select.options);
    const items = options.map((option, index) => {
        const item = document.createElement('li');
        item.id = `${select.id}-option-${index}`;
        item.setAttribute('role', 'option');
        item.setAttribute('aria-disabled', String(option.disabled));
        item.textContent = option.text;
        list.append(item);
        item.addEventListener('pointerdown', event => event.preventDefault());
        item.addEventListener('click', () => {
            if (!option.disabled) choose(index);
        });
        item.addEventListener('pointermove', () => {
            if (!option.disabled) highlight(index, false);
        });
        return item;
    });
    const validation = document.createElement('div');
    validation.id = `${select.id}-native-error`;
    validation.className = 'invalid-feedback';
    validation.hidden = true;
    validation.setAttribute('role', 'alert');
    wrapper.append(validation);
    let active = select.selectedIndex;
    let search = '';
    let searchTimer;
    const sync = () => {
        label.textContent = select.selectedOptions[0]?.text || '';
        validation.hidden = true;
        validation.textContent = '';
        trigger.setAttribute('aria-describedby', select.getAttribute('aria-describedby'));
        trigger.disabled = select.disabled;
        trigger.setAttribute('aria-required', String(select.required));
        trigger.setAttribute('aria-invalid', select.getAttribute('aria-invalid') || 'false');
        trigger.classList.toggle('is-invalid', select.classList.contains('is-invalid'));
        items.forEach((item, index) => item.setAttribute('aria-selected', String(index === select.selectedIndex)));
    };
    const close = () => {
        list.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        trigger.removeAttribute('aria-activedescendant');
    };
    const highlight = (index, scroll = true) => {
        active = index;
        items.forEach((item, position) => item.classList.toggle('is-highlighted', position === active));
        if (items[active]) {
            trigger.setAttribute('aria-activedescendant', items[active].id);
            if (scroll) items[active].scrollIntoView({block: 'nearest'});
        }
    };
    const open = () => {
        if (select.disabled) return;
        list.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        const bounds = trigger.getBoundingClientRect();
        const below = window.innerHeight - bounds.bottom;
        list.classList.toggle('opens-up', below < Math.min(list.scrollHeight, 240) && bounds.top > below);
        highlight(select.selectedIndex);
    };
    const choose = index => {
        select.selectedIndex = index;
        select.dispatchEvent(new Event('input', {bubbles: true}));
        select.dispatchEvent(new Event('change', {bubbles: true}));
        sync();
        close();
        trigger.focus();
    };
    trigger.addEventListener('click', () => list.hidden ? open() : close());
    trigger.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            if (!list.hidden) { event.preventDefault(); event.stopPropagation(); close(); }
        } else if (event.key === 'Tab') {
            close();
        } else if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            event.preventDefault();
            if (list.hidden) { open(); return; }
            const enabled = options.map((option, index) => option.disabled ? -1 : index).filter(index => index >= 0);
            const position = enabled.indexOf(active);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? enabled.length - 1 : Math.max(0, Math.min(enabled.length - 1, position + (event.key === 'ArrowDown' ? 1 : -1)));
            if (enabled.length) highlight(enabled[next]);
        } else if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            if (list.hidden) open();
            else if (active >= 0 && !options[active].disabled) choose(active);
        } else if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
            event.preventDefault();
            clearTimeout(searchTimer);
            search += event.key.toLocaleLowerCase();
            searchTimer = setTimeout(() => { search = ''; }, 700);
            const index = options.findIndex(option => !option.disabled && option.text.toLocaleLowerCase().startsWith(search));
            if (index >= 0) { if (list.hidden) open(); highlight(index); }
        }
    });
    document.addEventListener('click', event => { if (!wrapper.contains(event.target)) close(); });
    wrapper.addEventListener('focusout', event => { if (!wrapper.contains(event.relatedTarget)) close(); });
    select.addEventListener('change', sync);
    select.addEventListener('invalid', event => {
        event.preventDefault();
        trigger.setAttribute('aria-invalid', 'true');
        trigger.classList.add('is-invalid');
        validation.textContent = select.validationMessage;
        validation.hidden = false;
        trigger.setAttribute('aria-describedby', `${select.getAttribute('aria-describedby')} ${validation.id}`);
        trigger.focus();
    });
    select.form?.addEventListener('reset', () => setTimeout(() => { sync(); close(); }, 0));
    document.getElementById(`${select.id}-label`)?.setAttribute('for', trigger.id);
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');
    select.classList.add('custom-select-native');
    sync();
});

// The submitted list order is also the persisted training order.
document.querySelectorAll('[data-trainings-editor]').forEach(editor => {
    const list = editor.querySelector('[data-training-list]');
    const add = editor.querySelector('[data-training-add]');
    const renumber = () => {
        Array.from(list.children).forEach((row, index, rows) => {
            row.id = `trainings[${index}]`;
            row.querySelector('[data-training-heading]').textContent = `Обучение ${index + 1}`;
            row.querySelectorAll('*').forEach(element => {
                ['name', 'id', 'for', 'aria-describedby'].forEach(attribute => {
                    if (element.hasAttribute(attribute)) {
                        element.setAttribute(attribute, element.getAttribute(attribute).replace(/trainings\[[^\]]+\]/g, `trainings[${index}]`));
                    }
                });
            });
            row.querySelector('[data-training-up]').disabled = index === 0;
            row.querySelector('[data-training-down]').disabled = index === rows.length - 1;
        });
    };
    add.addEventListener('click', () => {
        list.append(editor.querySelector('[data-training-template]').content.cloneNode(true));
        renumber();
        list.lastElementChild.querySelector('input:not([type="hidden"])').focus();
    });
    list.addEventListener('click', event => {
        const button = event.target.closest('button');
        const row = button?.closest('[data-training]');
        if (!row) return;
        if (button.hasAttribute('data-training-remove')) {
            row.remove();
            add.focus();
        } else if (button.hasAttribute('data-training-up') && row.previousElementSibling) {
            list.insertBefore(row, row.previousElementSibling);
        } else if (button.hasAttribute('data-training-down') && row.nextElementSibling) {
            list.insertBefore(row.nextElementSibling, row);
        }
        renumber();
        if (row.isConnected) row.querySelector('input:not([type="hidden"])').focus();
    });
    renumber();
});

// Searchable checkbox lists keep real local dictionary IDs in the native select.
document.querySelectorAll('[data-multi-select]').forEach(wrapper => {
    const select = wrapper.querySelector('select');
    const search = document.createElement('input');
    search.type = 'search';
    search.id = `${select.id}-search`;
    search.className = 'form-control';
    search.placeholder = 'Поиск вариантов';
    search.setAttribute('aria-label', `Поиск: ${document.getElementById(`${select.id}-label`).textContent}`);
    const summary = document.createElement('p');
    summary.className = 'form-text';
    summary.setAttribute('role', 'status');
    const list = document.createElement('div');
    list.className = 'multi-options';
    list.setAttribute('role', 'group');
    list.setAttribute('aria-labelledby', `${select.id}-label`);
    const rows = Array.from(select.options).map(option => {
        const label = document.createElement('label');
        label.className = 'form-check';
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'form-check-input';
        checkbox.disabled = option.disabled;
        label.append(checkbox, document.createTextNode(` ${option.text}`));
        list.append(label);
        checkbox.addEventListener('change', () => {
            option.selected = checkbox.checked;
            select.dispatchEvent(new Event('change', {bubbles: true}));
        });
        return {option, checkbox, label};
    });
    const sync = () => {
        rows.forEach(row => { row.checkbox.checked = row.option.selected; });
        summary.textContent = `Выбрано: ${select.selectedOptions.length}`;
    };
    search.addEventListener('input', () => {
        const term = search.value.trim().toLocaleLowerCase('ru');
        rows.forEach(row => { row.label.hidden = !row.option.text.toLocaleLowerCase('ru').includes(term); });
        summary.textContent = `Выбрано: ${select.selectedOptions.length}. Найдено: ${rows.filter(row => !row.label.hidden).length}`;
    });
    select.addEventListener('change', sync);
    select.addEventListener('invalid', event => {
        event.preventDefault();
        summary.textContent = select.validationMessage;
        search.focus();
    });
    select.form?.addEventListener('reset', () => setTimeout(sync, 0));
    select.after(search, summary, list);
    select.classList.add('custom-select-native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');
    document.getElementById(`${select.id}-label`).setAttribute('for', search.id);
    sync();
});

// Enhancement only. Server-side GroupHtmlSanitizer is the storage authority.
document.querySelectorAll('[data-rich-editor]').forEach(wrapper => {
    if (typeof document.execCommand !== 'function') return;
    const textarea = wrapper.querySelector('textarea');
    const allowed = new Set(['P', 'BR', 'STRONG', 'EM', 'UL', 'OL', 'LI', 'H2', 'H3', 'BLOCKQUOTE', 'A']);
    const drop = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'FORM', 'INPUT', 'BUTTON', 'SVG', 'MATH', 'TEMPLATE', 'NOSCRIPT', 'TEXTAREA', 'SELECT', 'TITLE', 'HEAD', 'XMP', 'PLAINTEXT']);
    const safeHref = href => {
        if (!href || /[\s\u0000-\u001f\u007f\\]/u.test(href)) return false;
        return (href.startsWith('/') && !href.startsWith('//')) || /^(https?:\/\/|mailto:).+/i.test(href);
    };
    const clean = html => {
        // Template content is inert, including old input returned after validation.
        const template = document.createElement('template');
        template.innerHTML = html;
        const result = document.createElement('div');
        const append = (source, target) => {
            Array.from(source.childNodes).forEach(node => {
                if (node.nodeType === Node.TEXT_NODE) {
                    target.append(document.createTextNode(node.textContent));
                } else if (node.nodeType === Node.ELEMENT_NODE && !drop.has(node.tagName)) {
                    const tag = node.tagName === 'B' ? 'STRONG' : (node.tagName === 'I' ? 'EM' : node.tagName);
                    if (!allowed.has(tag)) {
                        append(node, target);
                        return;
                    }
                    const element = document.createElement(tag.toLowerCase());
                    if (tag === 'A' && safeHref(node.getAttribute('href'))) element.setAttribute('href', node.getAttribute('href'));
                    append(node, element);
                    target.append(element);
                }
            });
        };
        append(template.content, result);
        return result.innerHTML;
    };
    const toolbar = document.createElement('div');
    toolbar.className = 'rich-toolbar';
    toolbar.setAttribute('role', 'group');
    toolbar.setAttribute('aria-label', 'Форматирование полного описания');
    const editor = document.createElement('div');
    editor.id = `${textarea.id}-editor`;
    editor.className = 'form-control rich-editor';
    editor.contentEditable = 'true';
    editor.setAttribute('role', 'textbox');
    editor.setAttribute('aria-multiline', 'true');
    editor.setAttribute('aria-labelledby', `${textarea.id}-label`);
    editor.setAttribute('aria-describedby', textarea.getAttribute('aria-describedby'));
    editor.setAttribute('aria-required', String(textarea.required));
    editor.setAttribute('aria-invalid', textarea.getAttribute('aria-invalid'));
    editor.innerHTML = clean(textarea.value);
    let savedRange = null;
    const remember = () => {
        const selection = window.getSelection();
        if (selection.rangeCount && editor.contains(selection.anchorNode)) savedRange = selection.getRangeAt(0).cloneRange();
    };
    const sync = () => { textarea.value = clean(editor.innerHTML); };
    const restore = () => {
        editor.focus();
        if (savedRange && editor.contains(savedRange.commonAncestorContainer)) {
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(savedRange);
        }
    };
    const controls = [
        ['Жирный', 'bold'], ['Курсив', 'italic'], ['Список', 'insertUnorderedList'],
        ['Нумерация', 'insertOrderedList'], ['Убрать форматирование', 'removeFormat']
    ];
    controls.forEach(([label, command, value]) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-secondary';
        button.textContent = label;
        button.addEventListener('pointerdown', event => event.preventDefault());
        button.addEventListener('click', () => {
            restore();
            document.execCommand('styleWithCSS', false, false);
            document.execCommand(command, false, value);
            if (command === 'removeFormat') {
                document.execCommand('unlink');
                document.execCommand('formatBlock', false, 'p');
            }
            remember();
            sync();
        });
        toolbar.append(button);
    });
    editor.addEventListener('input', sync);
    editor.addEventListener('keyup', remember);
    editor.addEventListener('pointerup', remember);
    editor.addEventListener('focus', () => document.execCommand('defaultParagraphSeparator', false, 'p'));
    editor.addEventListener('blur', remember);
    editor.addEventListener('paste', event => {
        event.preventDefault();
        const clipboard = event.clipboardData;
        const text = document.createElement('div');
        text.textContent = clipboard.getData('text/plain');
        document.execCommand('insertHTML', false, clean(clipboard.getData('text/html') || text.innerHTML));
        sync();
    });
    // Do not insert dropped HTML/files into the editing surface.
    editor.addEventListener('drop', event => event.preventDefault());
    textarea.form?.addEventListener('submit', sync);
    textarea.form?.addEventListener('reset', () => setTimeout(() => { editor.innerHTML = clean(textarea.value); }, 0));
    textarea.addEventListener('invalid', event => {
        event.preventDefault();
        editor.setAttribute('aria-invalid', 'true');
        editor.focus();
    });
    textarea.before(toolbar, editor);
    textarea.classList.add('custom-select-native');
    textarea.tabIndex = -1;
    textarea.setAttribute('aria-hidden', 'true');
    document.getElementById(`${textarea.id}-label`).setAttribute('for', editor.id);
});
