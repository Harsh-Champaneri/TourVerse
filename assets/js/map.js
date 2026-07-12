/**
 * =============================================================================
 * map.js — Leaflet.js + OpenStreetMap Interactive Map
 * =============================================================================
 * Exposes:
 *   window.initTravelMap(destination, lat, lon)  — called from app.js
 *   window.travelMap                             — public map instance
 *
 * Features:
 *   - Auto-geocodes destination via Nominatim if no lat/lon available
 *   - Street + Satellite tile layers with toggle buttons
 *   - Custom pin-drop marker with popup
 *   - Dark/light mode tile inversion
 *   - invalidateSize() helper for tab switching
 * =============================================================================
 */

/* ── Tile layer URLs ─────────────────────────────────────────────────────── */
const TILE_LAYERS = {
  street: {
    url: "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
    attr: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
  },
  satellite: {
    url: "https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}",
    attr: "Tiles &copy; Esri &mdash; Source: Esri, DigitalGlobe, GeoEye, Earthstar Geographics",
  },
};

/* ── Global map instance (null until initialised) ────────────────────────── */
window.travelMap = null;

/**
 * Initialise the destination map.
 * Called automatically by app.js after itinerary generation.
 *
 * @param {string}      destination  - City / place name
 * @param {number|null} lat          - Latitude  (from weather API, optional)
 * @param {number|null} lon          - Longitude (from weather API, optional)
 */
window.initTravelMap = async function (destination, lat, lon) {
  const mapEl = document.getElementById("destinationMap");
  if (!mapEl || window.travelMap) return; // Guard: only init once

  // 1. Resolve coordinates
  let coords =
    lat && lon && !isNaN(lat) && !isNaN(lon)
      ? { lat: parseFloat(lat), lon: parseFloat(lon) }
      : null;

  if (!coords) {
    coords = await geocodeDestination(destination);
  }

  // Final fallback: centre of India
  if (!coords) {
    coords = { lat: 20.5937, lon: 78.9629 };
  }

  // 2. Create Leaflet map
  const map = L.map("destinationMap", {
    center: [coords.lat, coords.lon],
    zoom: 12,
    zoomControl: true,
    scrollWheelZoom: true,
    attributionControl: true,
  });

  // 3. Base tile layers
  const streetLayer = L.tileLayer(TILE_LAYERS.street.url, {
    attribution: TILE_LAYERS.street.attr,
    maxZoom: 19,
  });

  const satelliteLayer = L.tileLayer(TILE_LAYERS.satellite.url, {
    attribution: TILE_LAYERS.satellite.attr,
    maxZoom: 19,
  });

  streetLayer.addTo(map);
  let activeLayer = "street";

  // 4. Custom animated marker
  const markerIcon = L.divIcon({
    className: "",
    html: `
      <div style="
        width: 36px;
        height: 36px;
        background: linear-gradient(135deg, #3b82d4, #7c5cd8);
        border-radius: 50% 50% 50% 0;
        transform: rotate(-45deg);
        border: 3px solid white;
        box-shadow: 0 4px 14px rgba(59,130,212,0.55);
        display: flex; align-items: center; justify-content: center;
      ">
        <span style="transform:rotate(45deg); font-size:15px; margin-top:-2px; display:block;">📍</span>
      </div>
    `,
    iconSize: [36, 36],
    iconAnchor: [18, 36],
    popupAnchor: [0, -38],
  });

  L.marker([coords.lat, coords.lon], { icon: markerIcon })
    .addTo(map)
    .bindPopup(
      `
      <div style="font-family:Inter,sans-serif; min-width:160px;">
        <strong style="font-size:14px;">📍 ${destination}</strong><br>
        <span style="font-size:12px; color:#57606a;">
          ${coords.lat.toFixed(4)}°, ${coords.lon.toFixed(4)}°
        </span>
      </div>
    `,
      { maxWidth: 220 },
    )
    .openPopup();

  // 5. Layer toggle buttons
  document.getElementById("mapLayerStreet")?.addEventListener("click", () => {
    if (activeLayer === "street") return;
    map.removeLayer(satelliteLayer);
    streetLayer.addTo(map);
    activeLayer = "street";
    document.getElementById("mapLayerStreet")?.classList.add("active");
    document.getElementById("mapLayerSat")?.classList.remove("active");
    applyDarkTiles(mapEl, activeLayer);
  });

  document.getElementById("mapLayerSat")?.addEventListener("click", () => {
    if (activeLayer === "satellite") return;
    map.removeLayer(streetLayer);
    satelliteLayer.addTo(map);
    activeLayer = "satellite";
    document.getElementById("mapLayerSat")?.classList.add("active");
    document.getElementById("mapLayerStreet")?.classList.remove("active");
    mapEl.style.filter = "none"; // Satellite looks better without inversion
  });

  // 6. Apply dark mode tile filter
  function applyDarkTiles(el, layer) {
    if (layer !== "street") return;
    const theme =
      document.documentElement.getAttribute("data-bs-theme") || "dark";
    el.style.filter =
      theme === "dark"
        ? "invert(1) hue-rotate(200deg) brightness(0.85) contrast(1.05)"
        : "none";
  }

  applyDarkTiles(mapEl, activeLayer);

  // 7. Expose public interface to app.js
  window.travelMap = {
    map,
    invalidateSize: () => map.invalidateSize(),
    applyTheme: (theme) => {
      if (activeLayer !== "street") return;
      mapEl.style.filter =
        theme === "dark"
          ? "invert(1) hue-rotate(200deg) brightness(0.85) contrast(1.05)"
          : "none";
    },
  };
};

/**
 * Geocode a destination name via Nominatim (OpenStreetMap, free, no key).
 *
 * @param   {string} destination
 * @returns {Promise<{lat: number, lon: number}|null>}
 */
async function geocodeDestination(destination) {
  try {
    const q = encodeURIComponent(destination);
    const url = `https://nominatim.openstreetmap.org/search?q=${q}&format=json&limit=1`;
    const res = await fetch(url, {
      headers: {
        "Accept-Language": "en",
        "User-Agent": "AITravelPlanner/1.0 (educational project)",
      },
    });
    const data = await res.json();
    if (Array.isArray(data) && data.length > 0) {
      return {
        lat: parseFloat(data[0].lat),
        lon: parseFloat(data[0].lon),
      };
    }
  } catch (err) {
    console.warn("Geocoding failed:", err.message);
  }
  return null;
}
