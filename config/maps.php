<?php

declare(strict_types=1);

return [
    'tile_url' => (string) env('MAP_TILE_URL', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'),
    'tile_attribution' => (string) env('MAP_TILE_ATTRIBUTION', '&copy; OpenStreetMap-Mitwirkende'),
    'geocode_url' => (string) env('MAP_GEOCODE_URL', 'https://nominatim.openstreetmap.org/search'),
];
