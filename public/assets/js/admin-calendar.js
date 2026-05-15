(function () {
    var monthRequestId = 0;
    var dayRequestId = 0;

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatMinutes(minutes) {
        minutes = Number(minutes || 0);

        if (minutes <= 0) {
            return '0:00 h';
        }

        return String(Math.floor(minutes / 60)) + ':' + String(minutes % 60).padStart(2, '0') + ' h';
    }

    function setLoading(root, loading) {
        root.classList.toggle('is-loading', Boolean(loading));
    }

    function renderMonth(root, payload) {
        var grid = root.querySelector('[data-calendar-grid]');
        var title = root.querySelector('[data-calendar-title]');
        var selectedDate = root.dataset.selectedDate || '';

        if (!grid || !payload || !Array.isArray(payload.days)) {
            return;
        }

        if (title) {
            title.textContent = payload.label || payload.month || 'Kalender';
        }

        root.dataset.month = payload.month || '';
        root.dataset.previousMonth = payload.previous_month || '';
        root.dataset.nextMonth = payload.next_month || '';

        grid.innerHTML = payload.days.map(function (day) {
            var classes = [
                'calendar-day',
                'is-' + (day.status || 'empty'),
                day.is_current_month ? 'is-current-month' : 'is-outside-month',
                day.date === selectedDate ? 'is-selected' : '',
                day.date === payload.today ? 'is-today' : ''
            ].filter(Boolean).join(' ');
            var meta = [];

            if (Number(day.active_booking_count || 0) > 0) {
                meta.push(String(day.active_booking_count) + ' Buch.');
            }

            if (Number(day.net_minutes || 0) > 0) {
                meta.push(formatMinutes(day.net_minutes));
            }

            if (Number(day.issue_count || 0) > 0) {
                meta.push(String(day.issue_count) + ' offen');
            }

            return '<button type="button" class="' + escapeHtml(classes) + '" data-calendar-date="' + escapeHtml(day.date || '') + '" aria-pressed="' + (day.date === selectedDate ? 'true' : 'false') + '">'
                + '<span class="calendar-day__number">' + escapeHtml(day.day_number || '') + '</span>'
                + '<span class="calendar-day__status">' + escapeHtml(day.status_label || '') + '</span>'
                + '<span class="calendar-day__meta">' + escapeHtml(meta.join(' · ')) + '</span>'
                + '</button>';
        }).join('');
    }

    async function loadMonth(root, month) {
        var requestId = ++monthRequestId;

        dayRequestId++;
        setLoading(root, true);

        try {
            var response = await fetch('/admin/calendar/month?month=' + encodeURIComponent(month), {
                headers: {'Accept': 'application/json'}
            });
            var payload = await response.json();

            if (!response.ok || !payload.data) {
                throw new Error('Monat konnte nicht geladen werden.');
            }

            if (requestId !== monthRequestId) {
                return;
            }

            root.dataset.selectedDate = String(payload.data.today || '').indexOf(payload.data.month || '') === 0
                ? payload.data.today
                : (payload.data.month || month) + '-01';
            renderMonth(root, payload.data);
            await loadDay(root, root.dataset.selectedDate);
        } catch (error) {
            if (requestId === monthRequestId) {
                showPanelError(root, 'Der Monat konnte gerade nicht geladen werden.');
            }
        } finally {
            if (requestId === monthRequestId) {
                setLoading(root, false);
            }
        }
    }

    async function loadDay(root, date) {
        var requestId = ++dayRequestId;
        var panel = root.querySelector('[data-calendar-day-panel]');

        if (!panel || !date) {
            return;
        }

        root.dataset.selectedDate = date;
        root.querySelectorAll('[data-calendar-date]').forEach(function (button) {
            var selected = button.getAttribute('data-calendar-date') === date;
            button.classList.toggle('is-selected', selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
        panel.classList.add('is-loading');

        try {
            var response = await fetch('/admin/calendar/day?date=' + encodeURIComponent(date), {
                headers: {'Accept': 'application/json'}
            });
            var payload = await response.json();

            if (!response.ok || !payload.data || typeof payload.data.html !== 'string') {
                throw new Error('Tag konnte nicht geladen werden.');
            }

            if (requestId !== dayRequestId) {
                return;
            }

            panel.innerHTML = payload.data.html;
            updateUrl(root);
        } catch (error) {
            if (requestId === dayRequestId) {
                showPanelError(root, 'Der Tag konnte gerade nicht geladen werden.');
            }
        } finally {
            if (requestId === dayRequestId) {
                panel.classList.remove('is-loading');
            }
        }
    }

    function showPanelError(root, message) {
        var panel = root.querySelector('[data-calendar-day-panel]');

        if (panel) {
            panel.innerHTML = '<p class="notice error">' + escapeHtml(message) + '</p>';
        }
    }

    function updateUrl(root) {
        var month = root.dataset.month || '';
        var date = root.dataset.selectedDate || '';
        var url = new URL(window.location.href);

        if (month) {
            url.searchParams.set('month', month);
        }

        if (date) {
            url.searchParams.set('date', date);
        }

        url.searchParams.delete('notice');
        url.searchParams.delete('error');
        url.searchParams.delete('booking_id');
        url.searchParams.delete('modal');
        window.history.replaceState({month: month, date: date}, '', url.pathname + url.search);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-admin-calendar]');

        if (!root) {
            return;
        }

        try {
            renderMonth(root, JSON.parse(root.dataset.monthPayload || '{}'));
        } catch (error) {
            // Server-rendered markup remains usable.
        }

        root.addEventListener('click', function (event) {
            var previous = event.target.closest('[data-calendar-prev]');
            var next = event.target.closest('[data-calendar-next]');
            var day = event.target.closest('[data-calendar-date]');

            if (previous) {
                loadMonth(root, root.dataset.previousMonth || root.dataset.month || '');
                return;
            }

            if (next) {
                loadMonth(root, root.dataset.nextMonth || root.dataset.month || '');
                return;
            }

            if (day) {
                loadDay(root, day.getAttribute('data-calendar-date') || '');
            }
        });
    });
}());
