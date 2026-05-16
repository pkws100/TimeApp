function themeColor(name, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    return value || fallback;
}

function renderChartFallback(canvas, payload) {
    const context = canvas.getContext('2d');

    if (!context) {
        return;
    }

    const ink = themeColor('--page-ink', '#14213d');
    const muted = themeColor('--page-muted', '#5b6473');
    const width = canvas.width || canvas.clientWidth || 640;
    const height = canvas.height || canvas.clientHeight || 220;

    canvas.width = width;
    canvas.height = height;

    context.clearRect(0, 0, width, height);
    context.font = '16px sans-serif';
    context.fillStyle = ink;
    context.fillText('Chart.js ist lokal noch nicht eingebunden.', 20, 34);
    context.fillStyle = muted;
    context.fillText((payload && payload.message) || 'Die Grafik wartet auf belastbare Live-Daten.', 20, 64);
    context.fillText('Quelle: /api/v1/dashboard/charts', 20, 94);
}

function chartOptions() {
    const ink = themeColor('--page-ink', '#14213d');
    const line = themeColor('--page-line-strong', 'rgba(20, 33, 61, 0.18)');

    return {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: {
                ticks: { color: ink },
                grid: { color: line }
            },
            y: {
                ticks: { color: ink },
                grid: { color: line }
            }
        },
        plugins: {
            legend: {
                labels: {
                    color: ink
                }
            }
        }
    };
}

async function adminJson(url) {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' }
    });

    if (response.redirected && response.url.includes('/admin/login')) {
        window.location.href = response.url;
        throw new Error('Bitte erneut anmelden.');
    }

    if (response.status === 401) {
        window.location.href = '/admin/login?next=' + encodeURIComponent(window.location.pathname + window.location.search);
        throw new Error('Bitte erneut anmelden.');
    }

    if (response.status === 403) {
        throw new Error('Keine Berechtigung fuer diese Dashboard-Daten.');
    }

    const contentType = response.headers.get('Content-Type') || '';

    if (!contentType.includes('application/json')) {
        throw new Error('Ungueltige Serverantwort.');
    }

    const payload = await response.json();

    if (!response.ok) {
        throw new Error(payload.message || payload.error || 'Dashboard-Daten konnten nicht geladen werden.');
    }

    return payload;
}

async function bootDashboard() {
    const chartTarget = document.getElementById('headcountChart');

    if (!chartTarget) {
        return;
    }

    try {
        const payload = await adminJson('/api/v1/dashboard/charts');

        if (window.Chart) {
            const chart = new window.Chart(chartTarget, {
                type: 'bar',
                data: payload.headcount,
                options: chartOptions()
            });

            window.addEventListener('app-theme-change', () => {
                chart.options = chartOptions();
                chart.update();
            });

            return;
        }

        renderChartFallback(chartTarget, payload);
        window.addEventListener('app-theme-change', () => renderChartFallback(chartTarget, payload));
    } catch (error) {
        console.error('Dashboarddaten konnten nicht geladen werden.', error);
        renderChartFallback(chartTarget, {
            message: 'Die Dashboard-Daten konnten gerade nicht geladen werden.'
        });
    }
}

bootDashboard();
