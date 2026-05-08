(function () {
    var DEFAULT_PAGE_SIZE = 25;
    var PAGE_SIZES = [10, 25, 50, 'all'];

    function textOf(element) {
        return (element ? element.textContent : '').replace(/\s+/g, ' ').trim();
    }

    function normalize(value) {
        return String(value || '').replace(/\s+/g, ' ').trim().toLocaleLowerCase('de-DE');
    }

    function createElement(tag, className, text) {
        var element = document.createElement(tag);

        if (className) {
            element.className = className;
        }

        if (text !== undefined) {
            element.textContent = text;
        }

        return element;
    }

    function sortValue(row, index, type) {
        var cell = row.cells[index];
        var raw = cell ? (cell.dataset.sortValue || textOf(cell)) : '';

        if (type === 'number') {
            var number = Number(String(raw).replace(/\./g, '').replace(',', '.').replace(/[^\d.-]/g, ''));

            return Number.isFinite(number) ? number : 0;
        }

        return normalize(raw);
    }

    function sortableHeaders(table) {
        return Array.prototype.slice.call(table.querySelectorAll('thead th')).filter(function (header) {
            return header.dataset.sort !== 'false';
        });
    }

    function isEmptyRow(row) {
        return row.classList.contains('table-empty')
            || (row.cells.length === 1 && row.cells[0].classList.contains('table-empty'));
    }

    function searchableIndexes(headers) {
        return headers.reduce(function (indexes, header, index) {
            if (header.dataset.search !== 'false') {
                indexes.push(index);
            }

            return indexes;
        }, []);
    }

    function buildRows(table, indexes) {
        return Array.prototype.slice.call(table.querySelectorAll('tbody tr'))
            .filter(function (row) {
                return !isEmptyRow(row);
            })
            .map(function (row, index) {
                var searchText = indexes.map(function (cellIndex) {
                    var cell = row.cells[cellIndex];

                    if (!cell || cell.dataset.search === 'false') {
                        return '';
                    }

                    return textOf(cell);
                }).join(' ');

                return {
                    element: row,
                    initialIndex: index,
                    searchText: normalize(searchText)
                };
            });
    }

    function matchesQuery(searchText, query) {
        if (query === '') {
            return true;
        }

        return query.split(' ').every(function (part) {
            return part === '' || searchText.indexOf(part) !== -1;
        });
    }

    function createSortButtons(table, state) {
        sortableHeaders(table).forEach(function (header) {
            var index = header.cellIndex;
            var button = createElement('button', 'admin-table-sort');
            var label = textOf(header);
            var indicator = createElement('span', 'admin-table-sort__indicator', '');

            button.type = 'button';
            button.setAttribute('aria-label', label + ' sortieren');
            button.textContent = label;
            button.appendChild(indicator);
            header.textContent = '';
            header.appendChild(button);
            header.setAttribute('aria-sort', 'none');

            button.addEventListener('click', function () {
                var current = state.sort.index === index ? state.sort.direction : 'none';

                state.sort = {
                    index: index,
                    direction: current === 'asc' ? 'desc' : 'asc',
                    type: header.dataset.sortType || 'text'
                };
                state.page = 1;
                render(state);
            });
        });
    }

    function createControls(table, state) {
        var label = state.label;
        var toolbar = createElement('div', 'admin-table-toolbar');
        var searchLabel = createElement('label', 'admin-table-search');
        var searchText = createElement('span', null, label + ' durchsuchen');
        var searchInput = document.createElement('input');
        var meta = createElement('div', 'admin-table-meta');
        var sizeLabel = createElement('label', 'admin-table-size');
        var sizeText = createElement('span', null, 'Zeilen');
        var sizeSelect = document.createElement('select');
        var summary = createElement('p', 'admin-table-summary');
        var pager = createElement('div', 'admin-table-pager');
        var previous = createElement('button', 'admin-table-page-button', 'Zurueck');
        var pageInfo = createElement('span', 'admin-table-page-info');
        var next = createElement('button', 'admin-table-page-button', 'Weiter');

        searchInput.type = 'search';
        searchInput.placeholder = 'Nummer, Name, Kunde, Status, Ort';
        searchInput.autocomplete = 'off';
        summary.setAttribute('aria-live', 'polite');

        PAGE_SIZES.forEach(function (size) {
            var option = document.createElement('option');

            option.value = String(size);
            option.textContent = size === 'all' ? 'Alle' : String(size);
            option.selected = size === DEFAULT_PAGE_SIZE;
            sizeSelect.appendChild(option);
        });

        previous.type = 'button';
        next.type = 'button';

        searchInput.addEventListener('input', function () {
            state.query = normalize(searchInput.value);
            state.page = 1;
            render(state);
        });

        sizeSelect.addEventListener('change', function () {
            state.pageSize = sizeSelect.value === 'all' ? 'all' : Number(sizeSelect.value);
            state.page = 1;
            render(state);
        });

        previous.addEventListener('click', function () {
            state.page = Math.max(1, state.page - 1);
            render(state);
        });

        next.addEventListener('click', function () {
            state.page += 1;
            render(state);
        });

        searchLabel.appendChild(searchText);
        searchLabel.appendChild(searchInput);
        sizeLabel.appendChild(sizeText);
        sizeLabel.appendChild(sizeSelect);
        pager.appendChild(previous);
        pager.appendChild(pageInfo);
        pager.appendChild(next);
        meta.appendChild(sizeLabel);
        meta.appendChild(summary);
        meta.appendChild(pager);
        toolbar.appendChild(searchLabel);
        toolbar.appendChild(meta);

        state.controls = {
            summary: summary,
            previous: previous,
            next: next,
            pageInfo: pageInfo
        };

        return toolbar;
    }

    function createEmptyRow(state) {
        var row = document.createElement('tr');
        var cell = document.createElement('td');

        cell.colSpan = state.headers.length || 1;
        cell.className = 'table-empty';
        cell.textContent = state.query ? 'Keine passenden Eintraege gefunden.' : state.emptyMessage;
        row.appendChild(cell);

        return row;
    }

    function render(state) {
        var table = state.table;
        var tbody = table.tBodies[0];
        var filtered = state.rows.filter(function (row) {
            return matchesQuery(row.searchText, state.query);
        });
        var sorted = filtered.slice();
        var pageSize = state.pageSize;
        var totalPages = pageSize === 'all' ? 1 : Math.max(1, Math.ceil(sorted.length / pageSize));
        var start = 0;
        var visible = sorted;

        if (state.sort.index !== null) {
            sorted.sort(function (left, right) {
                var leftValue = sortValue(left.element, state.sort.index, state.sort.type);
                var rightValue = sortValue(right.element, state.sort.index, state.sort.type);
                var result = 0;

                if (leftValue < rightValue) {
                    result = -1;
                } else if (leftValue > rightValue) {
                    result = 1;
                } else {
                    return left.initialIndex - right.initialIndex;
                }

                return state.sort.direction === 'desc' ? result * -1 : result;
            });
        }

        if (pageSize !== 'all') {
            state.page = Math.min(Math.max(1, state.page), totalPages);
            start = (state.page - 1) * pageSize;
            visible = sorted.slice(start, start + pageSize);
        } else {
            state.page = 1;
        }

        tbody.textContent = '';

        if (visible.length === 0) {
            tbody.appendChild(createEmptyRow(state));
        } else {
            visible.forEach(function (row) {
                tbody.appendChild(row.element);
            });
        }

        sortableHeaders(table).forEach(function (header) {
            var active = state.sort.index === header.cellIndex;

            header.setAttribute('aria-sort', active ? (state.sort.direction === 'asc' ? 'ascending' : 'descending') : 'none');
            header.classList.toggle('is-sorted-asc', active && state.sort.direction === 'asc');
            header.classList.toggle('is-sorted-desc', active && state.sort.direction === 'desc');
        });

        state.controls.summary.textContent = String(filtered.length) + ' von ' + String(state.rows.length) + ' ' + state.label;
        state.controls.pageInfo.textContent = 'Seite ' + String(state.page) + ' / ' + String(totalPages);
        state.controls.previous.disabled = state.page <= 1 || pageSize === 'all';
        state.controls.next.disabled = state.page >= totalPages || pageSize === 'all';
    }

    function initTable(table) {
        if (table.dataset.adminTableReady === '1') {
            return;
        }

        var headers = Array.prototype.slice.call(table.querySelectorAll('thead th'));
        var indexes = searchableIndexes(headers);
        var state = {
            table: table,
            headers: headers,
            rows: buildRows(table, indexes),
            label: table.dataset.tableLabel || 'Eintraege',
            emptyMessage: textOf(table.querySelector('tbody .table-empty')) || 'Keine Eintraege vorhanden.',
            query: '',
            page: 1,
            pageSize: DEFAULT_PAGE_SIZE,
            sort: {
                index: null,
                direction: 'asc',
                type: 'text'
            },
            controls: {}
        };
        var scroll = table.parentNode && table.parentNode.classList.contains('table-scroll')
            ? table.parentNode
            : createElement('div', 'table-scroll');
        var parent = scroll.parentNode || table.parentNode;
        var shell = createElement('div', 'admin-table');

        table.dataset.adminTableReady = '1';
        table.classList.add('admin-data-table');

        if (scroll.parentNode) {
            parent.insertBefore(shell, scroll);
        } else {
            parent.insertBefore(shell, table);
            scroll.appendChild(table);
        }

        shell.appendChild(createControls(table, state));
        shell.appendChild(scroll);

        createSortButtons(table, state);
        render(state);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('table[data-admin-table]').forEach(initTable);
    });
}());
