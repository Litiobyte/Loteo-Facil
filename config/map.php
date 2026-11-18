<?php

return [
    // Proveedor de teselas raster. Evaluar licencias: Esri World Imagery es gratuita
    // con atribucion obligatoria; el uso comercial de servicios gratuitos puede tener
    // restricciones, configurar un proveedor pagado si se requiere redistribucion.
    'satellite_url' => env('MAP_SATELLITE_URL', 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'),
    'satellite_attribution' => env('MAP_SATELLITE_ATTRIBUTION', 'Esri, Maxar, Earthstar Geographics'),
    'streets_url' => env('MAP_STREETS_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
    'streets_attribution' => env('MAP_STREETS_ATTRIBUTION', '© OpenStreetMap contributors'),
    'glyphs_url' => env('MAP_GLYPHS_URL', 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf'),
    'max_upload_kb' => (int) env('MAP_MAX_UPLOAD_KB', 20480),
    'allowed_extensions' => ['kmz', 'kml', 'geojson', 'json'],
];
