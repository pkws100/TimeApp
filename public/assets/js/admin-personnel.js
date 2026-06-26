(function () {
    function css(name, fallback) {
        var value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

        return value || fallback;
    }

    function payload(canvas) {
        try {
            return JSON.parse(canvas.getAttribute('data-chart-payload') || '{}');
        } catch (error) {
            return {};
        }
    }

    function resize(canvas) {
        var rect = canvas.getBoundingClientRect();
        var ratio = window.devicePixelRatio || 1;
        var width = Math.max(320, Math.floor(rect.width || 640));
        var height = Math.max(240, Math.floor(rect.height || 240));

        canvas.width = Math.floor(width * ratio);
        canvas.height = Math.floor(height * ratio);
        canvas.style.height = height + 'px';

        var context = canvas.getContext('2d');

        if (context) {
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
        }

        return { width: width, height: height, context: context };
    }

    function drawEmpty(context, width, message) {
        context.fillStyle = css('--page-muted', '#64748b');
        context.font = '14px sans-serif';
        context.fillText(message, 18, Math.max(36, width > 420 ? 48 : 36));
    }

    function truncateLabel(context, label, maxWidth) {
        var value = String(label || 'Eintrag');

        if (context.measureText(value).width <= maxWidth) {
            return value;
        }

        while (value.length > 4 && context.measureText(value + '...').width > maxWidth) {
            value = value.slice(0, -1);
        }

        return value + '...';
    }

    function drawDoughnut(canvas, data) {
        var box = resize(canvas);
        var context = box.context;

        if (!context) {
            return;
        }

        context.clearRect(0, 0, box.width, box.height);

        var dataset = (data.datasets || [])[0] || {};
        var values = Array.isArray(dataset.data) ? dataset.data.map(Number) : [];
        var labels = Array.isArray(data.labels) ? data.labels : [];
        var colors = Array.isArray(dataset.backgroundColor) ? dataset.backgroundColor : [];
        var total = values.reduce(function (sum, value) { return sum + Math.max(0, value || 0); }, 0);

        if (total <= 0) {
            drawEmpty(context, box.width, 'Noch keine Daten fuer diese Grafik.');
            return;
        }

        var radius = Math.min(box.width, box.height) * 0.28;
        var centerX = Math.min(box.width * 0.36, radius + 40);
        var centerY = box.height * 0.5;
        var start = -Math.PI / 2;

        values.forEach(function (value, index) {
            var angle = (Math.max(0, value) / total) * Math.PI * 2;

            context.beginPath();
            context.moveTo(centerX, centerY);
            context.arc(centerX, centerY, radius, start, start + angle);
            context.closePath();
            context.fillStyle = colors[index] || ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed'][index % 5];
            context.fill();
            start += angle;
        });

        context.globalCompositeOperation = 'destination-out';
        context.beginPath();
        context.arc(centerX, centerY, radius * 0.58, 0, Math.PI * 2);
        context.fill();
        context.globalCompositeOperation = 'source-over';

        var legendX = Math.min(box.width - 180, centerX + radius + 28);
        var legendY = Math.max(28, centerY - Math.min(values.length, 7) * 13);

        context.font = '13px sans-serif';
        context.textBaseline = 'middle';
        values.slice(0, 8).forEach(function (value, index) {
            var y = legendY + index * 26;
            context.fillStyle = colors[index] || ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed'][index % 5];
            context.fillRect(legendX, y - 6, 12, 12);
            context.fillStyle = css('--page-ink', '#14213d');
            context.fillText(truncateLabel(context, labels[index], Math.max(90, box.width - legendX - 72)) + ': ' + String(value), legendX + 20, y);
        });
    }

    function boot() {
        var canvases = Array.prototype.slice.call(document.querySelectorAll('[data-personnel-chart]'));

        function renderAll() {
            canvases.forEach(function (canvas) {
                drawDoughnut(canvas, payload(canvas));
            });
        }

        renderAll();
        window.addEventListener('resize', renderAll);
        window.addEventListener('app-theme-change', renderAll);
    }

    boot();
}());
