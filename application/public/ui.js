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
    const toggle = navigation.querySelector('.menu-toggle');
    const menu = navigation.querySelector('.sidebar');
    const close = () => {
        toggle.setAttribute('aria-expanded', 'false');
        menu.classList.remove('is-open');
    };
    navigation.classList.add('enhanced');
    toggle.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', String(open));
        menu.classList.toggle('is-open', open);
    });
    navigation.addEventListener('keydown', event => {
        if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            close();
            toggle.focus();
        }
    });
    document.addEventListener('click', event => {
        if (!navigation.contains(event.target)) close();
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
