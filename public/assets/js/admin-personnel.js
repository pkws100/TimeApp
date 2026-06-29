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

    function resize(canvas, preferredHeight) {
        var rect = canvas.getBoundingClientRect();
        var ratio = window.devicePixelRatio || 1;
        var width = Math.max(260, Math.floor(rect.width || 640));
        var height = Math.max(240, Math.floor(preferredHeight || rect.height || 240));

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

    function chartDescription(legendItems, maxItems) {
        var visibleLabels = legendItems.slice(0, maxItems).map(function (item) {
            return item.label + ': ' + String(item.value);
        });
        var hiddenCount = Math.max(0, legendItems.length - maxItems);

        if (hiddenCount > 0) {
            var hiddenTotal = legendItems.slice(maxItems).reduce(function (sum, item) {
                return sum + item.value;
            }, 0);

            visibleLabels.push('Weitere ' + String(hiddenCount) + ': ' + String(hiddenTotal));
        }

        return visibleLabels.join(', ');
    }

    function drawDoughnut(canvas, data) {
        var dataset = (data.datasets || [])[0] || {};
        var values = Array.isArray(dataset.data) ? dataset.data.map(Number) : [];
        var labels = Array.isArray(data.labels) ? data.labels : [];
        var colors = Array.isArray(dataset.backgroundColor) ? dataset.backgroundColor : [];
        var total = values.reduce(function (sum, value) { return sum + Math.max(0, value || 0); }, 0);
        var maxLegendItems = 8;
        var legendItems = values.map(function (value, index) {
            return {
                label: String(labels[index] || 'Eintrag'),
                value: Math.max(0, value || 0),
                color: colors[index] || ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed'][index % 5]
            };
        }).filter(function (item) {
            return item.value > 0;
        });
        var rect = canvas.getBoundingClientRect();
        var measuredWidth = Math.max(260, Math.floor(rect.width || 640));
        var hiddenLegendItems = Math.max(0, legendItems.length - maxLegendItems);
        var legendRows = Math.min(legendItems.length, maxLegendItems) + (hiddenLegendItems > 0 ? 1 : 0);
        var isCompact = measuredWidth < 520;
        var compactRadius = Math.min(78, Math.max(54, measuredWidth * 0.22));
        var preferredHeight = isCompact && total > 0
            ? Math.max(300, Math.ceil(44 + compactRadius * 2 + legendRows * 28))
            : 240;
        var box = resize(canvas, preferredHeight);
        var context = box.context;

        if (!context) {
            return;
        }

        context.clearRect(0, 0, box.width, box.height);
        canvas.setAttribute('role', 'img');

        if (total <= 0) {
            canvas.setAttribute('aria-label', 'Noch keine Daten fuer diese Grafik.');
            drawEmpty(context, box.width, 'Noch keine Daten fuer diese Grafik.');
            return;
        }

        canvas.setAttribute('aria-label', chartDescription(legendItems, maxLegendItems));

        var radius = isCompact
            ? Math.min(78, Math.max(54, box.width * 0.22))
            : Math.min(box.width, box.height) * 0.28;
        var centerX = isCompact ? box.width * 0.5 : Math.min(box.width * 0.36, radius + 40);
        var centerY = isCompact ? radius + 20 : box.height * 0.5;
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

        var legendX = isCompact ? 18 : Math.min(box.width - 180, centerX + radius + 28);
        var legendY = isCompact ? centerY + radius + 30 : Math.max(28, centerY - Math.min(values.length, 7) * 13);

        context.font = '13px sans-serif';
        context.textBaseline = 'middle';
        legendItems.slice(0, maxLegendItems).forEach(function (item, index) {
            var y = legendY + index * (isCompact ? 28 : 26);
            context.fillStyle = item.color;
            context.fillRect(legendX, y - 6, 12, 12);
            context.fillStyle = css('--page-ink', '#14213d');
            context.fillText(truncateLabel(context, item.label, Math.max(90, box.width - legendX - 56)) + ': ' + String(item.value), legendX + 20, y);
        });

        if (hiddenLegendItems > 0) {
            var hiddenY = legendY + maxLegendItems * (isCompact ? 28 : 26);
            var hiddenTotal = legendItems.slice(maxLegendItems).reduce(function (sum, item) {
                return sum + item.value;
            }, 0);

            context.fillStyle = css('--page-muted', '#64748b');
            context.fillText('Weitere ' + String(hiddenLegendItems) + ': ' + String(hiddenTotal), legendX + 20, hiddenY);
        }
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
