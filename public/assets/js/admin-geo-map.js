(function () {
    function parseCoordinate(value) {
        const parsed = Number.parseFloat(String(value ?? '').replace(',', '.'));

        return Number.isFinite(parsed) ? parsed : null;
    }

    function formatCoordinate(value) {
        return Number(value).toFixed(7);
    }

    function setMessage(panel, message, isError) {
        const node = panel.querySelector('[data-geo-map-message]');

        if (!node) {
            return;
        }

        node.textContent = message;
        node.classList.toggle('is-error', Boolean(isError));
    }

    function addressFor(panel) {
        return (panel.dataset.address ?? '').trim();
    }

    async function geoJson(url) {
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json'
            }
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
            throw new Error('Keine Berechtigung fuer diese GEO-Daten.');
        }

        if (!response.ok) {
            throw new Error('Geocoding fehlgeschlagen.');
        }

        return response.json();
    }

    function updateFields(fields, latLng, label) {
        fields.latitude.value = formatCoordinate(latLng.lat);
        fields.longitude.value = formatCoordinate(latLng.lng);

        if (label !== undefined && fields.label) {
            fields.label.value = label;
        }

        fields.latitude.dispatchEvent(new Event('input', { bubbles: true }));
        fields.longitude.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function bootGeoMap(panel) {
        if (typeof L === 'undefined') {
            setMessage(panel, 'Kartenbibliothek konnte nicht geladen werden.', true);
            return;
        }

        const canvas = panel.querySelector('[data-geo-map-canvas]');
        const form = panel.closest('form');

        if (!canvas || !form) {
            return;
        }

        const fields = {
            latitude: form.querySelector('[data-geo-latitude]'),
            longitude: form.querySelector('[data-geo-longitude]'),
            label: form.querySelector('[data-geo-label]')
        };

        if (!fields.latitude || !fields.longitude) {
            return;
        }

        const initialLat = parseCoordinate(fields.latitude.value);
        const initialLng = parseCoordinate(fields.longitude.value);
        const hasInitialPosition = initialLat !== null && initialLng !== null;
        const initialCenter = hasInitialPosition ? [initialLat, initialLng] : [51.1657, 10.4515];
        const initialZoom = hasInitialPosition ? 15 : 6;
        const map = L.map(canvas, {
            scrollWheelZoom: false
        }).setView(initialCenter, initialZoom);
        let marker = null;

        L.tileLayer(panel.dataset.tileUrl || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: panel.dataset.tileAttribution || '&copy; OpenStreetMap-Mitwirkende',
            maxZoom: 19
        }).addTo(map);

        function placeMarker(latLng, label) {
            if (marker === null) {
                marker = L.marker(latLng, { draggable: true }).addTo(map);
                marker.on('dragend', function () {
                    updateFields(fields, marker.getLatLng());
                    setMessage(panel, 'Marker verschoben. Bitte GEO speichern.', false);
                });
            } else {
                marker.setLatLng(latLng);
            }

            updateFields(fields, latLng, label);
        }

        if (hasInitialPosition) {
            placeMarker({ lat: initialLat, lng: initialLng });
        }

        map.on('click', function (event) {
            placeMarker(event.latlng);
            setMessage(panel, 'Standort gesetzt. Bitte GEO speichern.', false);
        });

        const searchButton = panel.querySelector('[data-geo-search]');

        if (searchButton) {
            searchButton.addEventListener('click', function () {
                const address = addressFor(panel);

                if (address === '') {
                    setMessage(panel, 'Bitte zuerst Firmenadresse speichern.', true);
                    return;
                }

                const geocodeUrl = panel.dataset.geocodeUrl || 'https://nominatim.openstreetmap.org/search';
                const url = geocodeUrl
                    + '?format=json&limit=1&q='
                    + encodeURIComponent(address);

                searchButton.disabled = true;
                setMessage(panel, 'Adresse wird gesucht...', false);

                geoJson(url)
                    .then((results) => {
                        const first = Array.isArray(results) ? results[0] : null;

                        if (!first || first.lat === undefined || first.lon === undefined) {
                            throw new Error('Adresse wurde nicht gefunden.');
                        }

                        const latLng = {
                            lat: Number.parseFloat(first.lat),
                            lng: Number.parseFloat(first.lon)
                        };

                        map.setView(latLng, 16);
                        placeMarker(latLng, first.display_name || address);
                        setMessage(panel, 'Adresse gefunden. Marker pruefen und GEO speichern.', false);
                    })
                    .catch((error) => {
                        setMessage(panel, error.message || 'Adresse konnte nicht gesucht werden.', true);
                    })
                    .finally(() => {
                        searchButton.disabled = false;
                    });
            });
        }

        setTimeout(function () {
            map.invalidateSize();
        }, 120);
    }

    document.querySelectorAll('[data-company-geo-map]').forEach(bootGeoMap);
}());
