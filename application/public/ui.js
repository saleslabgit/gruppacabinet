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
