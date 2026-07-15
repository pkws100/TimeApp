(function () {
    function themeColor(name, fallback) {
        var value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

        return value || fallback;
    }

    function chartOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: themeColor('--page-ink', '#14213d'),
                        padding: 14,
                    },
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            var total = context.dataset.data.reduce(function (sum, value) { return sum + Number(value || 0); }, 0);
                            var value = Number(context.raw || 0);
                            var percent = total > 0 ? (value / total) * 100 : 0;

                            return context.label + ': ' + value + ' (' + percent.toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' %)';
                        },
                    },
                },
            },
        };
    }

    function showFallback(canvas, message) {
        var chartContainer = canvas ? canvas.closest('.attendance-status-chart') : null;
        if (chartContainer) {
            chartContainer.hidden = true;
        } else if (canvas) {
            canvas.hidden = true;
        }

        var fallback = document.getElementById('attendanceStatusChartFallback');
        if (fallback) {
            fallback.textContent = message;
            fallback.hidden = false;
        }
    }

    function hideFallback(canvas) {
        var chartContainer = canvas ? canvas.closest('.attendance-status-chart') : null;
        if (chartContainer) {
            chartContainer.hidden = false;
        }

        if (canvas) {
            canvas.hidden = false;
        }

        var fallback = document.getElementById('attendanceStatusChartFallback');
        if (fallback) {
            fallback.hidden = true;
        }
    }

    function bootAttendanceChart() {
        var canvas = document.getElementById('attendanceStatusChart');
        var dataNode = document.getElementById('attendanceStatusChartData');

        if (!canvas || !dataNode) {
            return;
        }

        var payload;
        try {
            payload = JSON.parse(dataNode.textContent || '{}');
        } catch (error) {
            showFallback(canvas, 'Die Statusdaten für das Kreisdiagramm sind ungültig.');
            return;
        }

        if (!window.Chart) {
            showFallback(canvas, 'Chart.js konnte nicht lokal geladen werden.');
            return;
        }

        var values = Array.isArray(payload.data) ? payload.data.map(function (value) { return Number(value || 0); }) : [];
        if (values.reduce(function (sum, value) { return sum + value; }, 0) === 0) {
            showFallback(canvas, 'Für heute liegen noch keine Belegschaftsstatus vor.');
            return;
        }

        var chart;
        try {
            chart = new window.Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels: Array.isArray(payload.labels) ? payload.labels : [],
                    datasets: [{
                        data: values,
                        backgroundColor: ['#15803d', '#2563eb', '#dc2626', '#7c3aed', '#d97706', '#b91c1c', '#64748b'],
                        borderColor: themeColor('--page-surface', '#ffffff'),
                        borderWidth: 2,
                    }],
                },
                options: chartOptions(),
            });
        } catch (error) {
            showFallback(canvas, 'Das Kreisdiagramm konnte nicht dargestellt werden.');
            return;
        }

        hideFallback(canvas);

        window.addEventListener('app-theme-change', function () {
            chart.data.datasets[0].borderColor = themeColor('--page-surface', '#ffffff');
            chart.options = chartOptions();
            chart.update();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootAttendanceChart);
    } else {
        bootAttendanceChart();
    }
}());
