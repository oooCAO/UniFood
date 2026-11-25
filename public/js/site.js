/**
 * Общий JavaScript сайта UniFood.
 *
 * Без сторонних библиотек и сборки, ES5-совместимый синтаксис (var,
 * обычные function). Прогрессивное улучшение: если этот файл не
 * загрузился, все формы отправляются как обычный POST, а подтверждения
 * просто не спрашиваются — сервер всё равно проверяет данные заново,
 * поэтому без JS сайт остаётся полностью рабочим.
 */
(function () {
    'use strict';

    // Сколько миллисекунд показывать уведомление (контракт C5).
    var TOAST_DURATION_MS = 2500;

    /**
     * Находит контейнер уведомлений в подвале страницы.
     *
     * @return {Element|null} Элемент #site-toast или null, если его нет.
     */
    function getToastElement() {
        return document.getElementById('site-toast');
    }

    /**
     * Показывает уведомление на 2500 мс, затем прячет его снова.
     *
     * @param {string} message Текст уведомления.
     * @param {boolean} isError true — показать как ошибку (site-toast--error).
     * @return {void}
     */
    function showToast(message, isError) {
        var toast = getToastElement();

        if (!toast) {
            return;
        }

        toast.textContent = message;
        toast.classList.add('site-toast--visible');

        if (isError) {
            toast.classList.add('site-toast--error');
        } else {
            toast.classList.remove('site-toast--error');
        }

        // Повторное уведомление до того, как спряталось предыдущее,
        // не должно обрезаться старым таймером раньше времени.
        if (toast.hideTimerId) {
            window.clearTimeout(toast.hideTimerId);
        }

        toast.hideTimerId = window.setTimeout(function () {
            toast.classList.remove('site-toast--visible');
            toast.classList.remove('site-toast--error');
        }, TOAST_DURATION_MS);
    }

    /**
     * Обновляет все счётчики корзины в шапке: только текст и атрибут
     * hidden, сам элемент не трогает — иначе шапка «дёргается».
     *
     * @param {number} count Текущее число порций в корзине.
     * @return {void}
     */
    function updateCartCount(count) {
        var counters = document.querySelectorAll('[data-cart-count]');
        var i;

        for (i = 0; i < counters.length; i++) {
            counters[i].textContent = String(count);

            if (count > 0) {
                counters[i].removeAttribute('hidden');
            } else {
                counters[i].setAttribute('hidden', 'hidden');
            }
        }
    }

    /**
     * Отправляет форму «В корзину» по контракту /cart.php (раздел 2.2
     * договорённостей): без перезагрузки страницы, с JSON-ответом.
     *
     * @param {HTMLFormElement} form Форма с атрибутом data-cart-add.
     * @return {void}
     */
    function submitCartAddForm(form) {
        var submitButton = form.querySelector('button[type="submit"]');
        var formData = new FormData(form);

        // src/cart.php (isAjaxRequest()) узнаёт AJAX-запрос по полю ajax
        // в теле POST, а не по строке запроса — добавляем его в данные формы.
        formData.set('ajax', '1');

        // Защита от двойного клика на время запроса.
        if (submitButton) {
            submitButton.disabled = true;
        }

        fetch('/cart.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { status: response.status, data: data };
                });
            })
            .then(function (result) {
                var data = result.data;

                if (result.status === 401) {
                    window.location.href = data.loginUrl || '/login.php';
                    return;
                }

                if (data.ok) {
                    updateCartCount(data.cartCount || 0);
                    showToast(data.message || 'Добавлено в корзину', false);
                } else {
                    showToast(data.message || 'Не удалось добавить блюдо.', true);
                }
            })
            .catch(function () {
                // Сервер недоступен или ответил не JSON (например, упал
                // с ошибкой 500) — показываем общий понятный текст.
                showToast('Не удалось добавить блюдо. Проверьте соединение и попробуйте ещё раз.', true);
            })
            .then(function () {
                if (submitButton) {
                    submitButton.disabled = false;
                }
            });
    }

    /**
     * Простая проверка количества перед отправкой формы: число должно
     * быть больше нуля. Не заменяет серверную проверку — только
     * избавляет от лишней перезагрузки страницы с очевидной ошибкой.
     *
     * @param {HTMLFormElement} form Отправляемая форма.
     * @return {boolean} true, если в форме есть некорректное количество.
     */
    function hasInvalidQuantity(form) {
        var fields = form.querySelectorAll('input[name="quantity"]');
        var i;
        var value;

        for (i = 0; i < fields.length; i++) {
            value = parseInt(fields[i].value, 10);

            if (!value || value < 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Находит источник data-confirm для отправляемой формы: саму форму
     * или кнопку, которой её отправили (C6 — подтверждение на форме
     * или кнопке).
     *
     * @param {HTMLFormElement} form Отправляемая форма.
     * @param {SubmitEvent} event Событие submit.
     * @return {Element|null} Элемент с data-confirm или null.
     */
    function findConfirmSource(form, event) {
        if (form.hasAttribute('data-confirm')) {
            return form;
        }

        if (event.submitter && event.submitter.hasAttribute('data-confirm')) {
            return event.submitter;
        }

        return null;
    }

    /**
     * Общий обработчик отправки форм на сайте: подтверждение (C6),
     * проверка количества и, если форма помечена data-cart-add,
     * AJAX-добавление в корзину (П.7).
     *
     * @param {SubmitEvent} event Событие submit, всплывшее до document.
     * @return {void}
     */
    function handleSubmit(event) {
        var form = event.target;

        if (!form || form.tagName !== 'FORM') {
            return;
        }

        var confirmSource = findConfirmSource(form, event);

        if (confirmSource) {
            var question = confirmSource.getAttribute('data-confirm') || 'Подтвердите действие.';

            if (!window.confirm(question)) {
                event.preventDefault();
                return;
            }
        }

        if (hasInvalidQuantity(form)) {
            event.preventDefault();
            showToast('Укажите количество больше нуля.', true);
            return;
        }

        if (form.hasAttribute('data-cart-add')) {
            event.preventDefault();
            submitCartAddForm(form);
        }
    }

    document.addEventListener('submit', handleSubmit);
})();
