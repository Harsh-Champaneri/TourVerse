/**
 * =============================================================================
 * app.js — AI Travel Planner · Two-Column Dashboard Frontend
 * =============================================================================
 * [Index page]
 *   - Multi-step form stepper (4 steps)
 *   - Pill / budget / interest selectors
 *   - Form validation & submit → sessionStorage → itinerary.php
 *
 * [Itinerary page]
 *   - Calls api/generate.php (watsonx.ai Granite) — NO backend changes
 *   - Calls api/weather.php  — NO backend changes
 *   - Parses AI markdown into structured two-column sections:
 *       Left:  Overview · Day Accordion · Budget Cards · Tips/Safety · Eco
 *       Right: Weather · Map · Checklist · Attraction Cards · Food Cards
 *   - Sticky action bar (PDF, Email, Print, Copy, New Trip)
 *   - Theme toggle (dark/light)
 *   - Toast notifications
 * =============================================================================
 */

"use strict";

/* ─────────────────────────────────────────────────────────────────────────
   HELPERS
───────────────────────────────────────────────────────────────────────── */
const $ = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

function formatDate(ds) {
  if (!ds) return "";
  const d = new Date(ds + "T00:00:00");
  return d.toLocaleDateString("en-IN", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

function daysBetween(a, b) {
  if (!a || !b) return 3;
  const diff = new Date(b) - new Date(a);
  return Math.max(1, Math.ceil(diff / 86400000));
}

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

// Strip ALL Markdown syntax and return clean plain text
function mdToText(md) {
  if (!md) return "";
  return (
    md
      // Fenced code blocks
      .replace(/```[\s\S]*?```/g, "")
      // Inline code
      .replace(/`([^`]+)`/g, "$1")
      // Bold + italic (handle unpaired ** or * too)
      .replace(/\*\*\*(.+?)\*\*\*/g, "$1")
      .replace(/\*\*(.+?)\*\*/g, "$1")
      .replace(/\*(.+?)\*/g, "$1")
      .replace(/___(.+?)___/g, "$1")
      .replace(/__(.+?)__/g, "$1")
      .replace(/_(.+?)_/g, "$1")
      // Headings
      .replace(/^#{1,6}\s+/gm, "")
      // Links and images
      .replace(/!\[.*?\]\(.*?\)/g, "")
      .replace(/\[(.+?)\]\(.+?\)/g, "$1")
      // Blockquotes
      .replace(/^>\s*/gm, "")
      // Remaining stray asterisks / underscores (unpaired)
      .replace(/\*+/g, "")
      .replace(/_{2,}/g, "")
      // Em-dashes and multiple dashes used as separators
      .replace(/\s*—\s*/g, " — ")
      // Trim each line and collapse multiple blank lines
      .split("\n")
      .map((l) => l.trim())
      .filter((l) => l !== "")
      .join(" ")
      .replace(/\s{2,}/g, " ")
      .trim()
  );
}

/* ─────────────────────────────────────────────────────────────────────────
   THEME
───────────────────────────────────────────────────────────────────────── */
(function () {
  const saved = localStorage.getItem("tp-theme") || "dark";
  document.documentElement.setAttribute("data-bs-theme", saved);
  updateThemeIcon(saved);
})();

function updateThemeIcon(theme) {
  const icon = $("#themeToggle i");
  if (!icon) return;
  icon.className = theme === "dark" ? "fa-solid fa-sun" : "fa-solid fa-moon";
}

$("#themeToggle")?.addEventListener("click", () => {
  const cur = document.documentElement.getAttribute("data-bs-theme") || "dark";
  const next = cur === "dark" ? "light" : "dark";
  document.documentElement.setAttribute("data-bs-theme", next);
  localStorage.setItem("tp-theme", next);
  updateThemeIcon(next);
  window.travelMap?.applyTheme(next);
});

/* ─────────────────────────────────────────────────────────────────────────
   PAGE DETECTION
───────────────────────────────────────────────────────────────────────── */
const IS_INDEX = !!$("#travelForm");
const IS_ITINERARY = !!$("#loadingOverlay");

/* ═══════════════════════════════════════════════════════════════════════════
   INDEX PAGE — Multi-step Form
═══════════════════════════════════════════════════════════════════════════ */
if (IS_INDEX) {
  // Date minimums
  const today = new Date().toISOString().split("T")[0];
  const startI = $("#startDate");
  const endI = $("#endDate");
  if (startI) startI.min = today;
  if (endI) endI.min = today;
  startI?.addEventListener("change", () => {
    if (endI) {
      endI.min = startI.value;
      if (endI.value && endI.value < startI.value) endI.value = "";
    }
  });

  // ── Pill selector (Travel type) ──────────────────────────────────────
  $$("#travelTypeSelector .tp-pill").forEach((btn) => {
    btn.addEventListener("click", () => {
      $$("#travelTypeSelector .tp-pill").forEach((b) =>
        b.classList.remove("active"),
      );
      btn.classList.add("active");
      $("#travelType").value = btn.dataset.value;
    });
  });

  // ── Budget selector ──────────────────────────────────────────────────
  $$("#budgetSelector .tp-budget-card").forEach((card) => {
    card.addEventListener("click", () => {
      $$("#budgetSelector .tp-budget-card").forEach((c) =>
        c.classList.remove("active"),
      );
      card.classList.add("active");
      $("#budgetType").value = card.dataset.value;
    });
  });

  // ── Step navigation ──────────────────────────────────────────────────
  window.stepNext = function (step) {
    if (step === 1) {
      const dest = $("#destination");
      const start = $("#startDate");
      const end = $("#endDate");
      let ok = true;
      if (!dest?.value.trim()) {
        markInvalid(dest);
        ok = false;
      }
      if (!start?.value) {
        markInvalid(start);
        ok = false;
      }
      if (!end?.value || end.value < start?.value) {
        markInvalid(end);
        ok = false;
      }
      if (!ok) return;
    }
    goToStep(step + 1);
  };

  window.stepBack = function (step) {
    goToStep(step - 1);
  };

  function goToStep(n) {
    $$(".tp-form-step").forEach((el) => el.classList.remove("active"));
    $(`#formStep${n}`)?.classList.add("active");
    $$(".tp-step").forEach((el, i) => {
      el.classList.remove("active", "done");
      if (i + 1 < n) el.classList.add("done");
      if (i + 1 === n) el.classList.add("active");
    });
    const target = $("#plannerForm");
    if (target)
      window.scrollTo({ top: target.offsetTop - 80, behavior: "smooth" });
  }

  function markInvalid(el) {
    if (!el) return;
    el.classList.add("is-invalid");
    el.addEventListener("input", () => el.classList.remove("is-invalid"), {
      once: true,
    });
  }

  // ── Form submit ──────────────────────────────────────────────────────
  $("#travelForm")?.addEventListener("submit", (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const interests = $$('input[name="interests[]"]:checked').map(
      (c) => c.value,
    );
    if (!interests.length) interests.push("General sightseeing");

    const payload = {
      destination: fd.get("destination")?.trim() || "",
      origin: fd.get("origin")?.trim() || "India",
      start_date: fd.get("start_date") || "",
      end_date: fd.get("end_date") || "",
      travellers: fd.get("travellers") || "2",
      travel_type: fd.get("travel_type") || "Couple",
      budget: fd.get("budget") || "Mid-range",
      budget_amount: fd.get("budget_amount")?.trim() || "",
      currency: fd.get("currency") || "INR",
      interests,
      diet: fd.get("diet") || "No preference",
      mobility: fd.get("mobility") || "No restrictions",
      language: fd.get("language") || "English",
      special_req: fd.get("special_req")?.trim() || "",
    };

    sessionStorage.setItem("travelPayload", JSON.stringify(payload));

    const btn = $("#generateBtn");
    if (btn) {
      btn.disabled = true;
      btn.querySelector(".btn-content")?.classList.add("d-none");
      btn.querySelector(".btn-loading")?.classList.remove("d-none");
    }

    window.location.href = "itinerary.php";
  });
}

/* ═══════════════════════════════════════════════════════════════════════════
   ITINERARY PAGE — Two-Column Dashboard
═══════════════════════════════════════════════════════════════════════════ */
if (IS_ITINERARY) {
  const stored = sessionStorage.getItem("travelPayload");
  if (!stored) {
    showError(
      "No travel data found. Please go back and fill in the planning form.",
    );
  } else {
    runGeneration(JSON.parse(stored));
  }
}

/* ─────────────────────────────────────────────────────────────────────────
   GENERATION PIPELINE
───────────────────────────────────────────────────────────────────────── */
async function runGeneration(payload) {
  try {
    // Step 1 — connect & generate
    activateLoadStep("step1");
    const genRes = await fetch("api/generate.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const genData = await genRes.json();
    if (!genData.success)
      throw new Error(genData.error || "Generation failed.");
    completeLoadStep("step1");

    activateLoadStep("step2");
    await sleep(180);
    completeLoadStep("step2");

    // Step 3 — weather (optional, non-fatal)
    activateLoadStep("step3");
    let weather = null;
    try {
      const wRes = await fetch(
        `api/weather.php?city=${encodeURIComponent(payload.destination)}`,
      );
      const wData = await wRes.json();
      if (wData.success || wData.mock) weather = wData.data || wData;
    } catch (_) {
      /* weather is optional */
    }
    completeLoadStep("step3");

    // Step 4 — render dashboard
    activateLoadStep("step4");
    renderDashboard(genData, payload, weather);
    completeLoadStep("step4");

    await sleep(380);

    // Hide overlay, reveal page
    const overlay = $("#loadingOverlay");
    if (overlay) {
      overlay.style.opacity = "0";
      overlay.style.transition = "opacity .5s ease";
      setTimeout(() => overlay.classList.add("d-none"), 500);
    }

    $("#itineraryHero")?.classList.remove("d-none");
    $("#actionToolbar")?.classList.remove("d-none");
    $("#itineraryMain")?.classList.remove("d-none");

    // Init map after a short delay so the container is visible
    setTimeout(() => {
      window.initTravelMap?.(payload.destination, weather?.lat, weather?.lon);
    }, 600);
  } catch (err) {
    showError(err.message || "An unexpected error occurred.");
  }
}

function activateLoadStep(id) {
  const el = $(`#${id}`);
  if (el) {
    el.classList.add("active");
    el.classList.remove("done");
  }
}
function completeLoadStep(id) {
  const el = $(`#${id}`);
  if (el) {
    el.classList.remove("active");
    el.classList.add("done");
  }
}

/* ─────────────────────────────────────────────────────────────────────────
   RENDER DASHBOARD — two-column, no tabs
───────────────────────────────────────────────────────────────────────── */
function renderDashboard(genData, payload, weather) {
  const { itinerary, travel_data: td, days, model } = genData;

  // Expose globals for PDF / email APIs
  window.__itineraryText = itinerary;
  window.__travelData = td;

  // Update model badge(s)
  $$("#modelBadge, #modelBadge2").forEach((el) => {
    if (el) el.textContent = model || "ibm/granite-3-3-8b-instruct";
  });

  // ── Hero section ──────────────────────────────────────────────────────
  const destEl = $("#itineraryDestination");
  if (destEl) destEl.textContent = td.destination;

  const metaEl = $("#itineraryMeta");
  if (metaEl) metaEl.innerHTML = buildMetaChips(td, days);

  // ── Parse raw AI markdown → structured sections ───────────────────
  const parsed = parseItinerary(itinerary);

  console.log("Eco:", parsed.eco);
  console.log("Length:", parsed.eco.length);

  // ── LEFT COLUMN ───────────────────────────────────────────────────────
  renderTripStats(td, days);
  renderOverview(parsed);
  renderAccordion(parsed.days, days);
  renderHotels(parsed); // ← Hotel Recommendations (after itinerary, before budget)
  renderBudget(parsed, td);
  renderAlerts(parsed);
  renderEco(parsed);

  // ── RIGHT COLUMN ──────────────────────────────────────────────────────
  if (weather) renderWeather(weather);
  renderChecklist(parsed);
  renderAttractions(parsed);
  renderFood(parsed);

  // ── Action bar ────────────────────────────────────────────────────────
  setupActions();

  // ── Email modal ───────────────────────────────────────────────────────
  setupEmail();
}

/* ─────────────────────────────────────────────────────────────────────────
   META CHIPS (hero)
───────────────────────────────────────────────────────────────────────── */
function buildMetaChips(td, days) {
  const chips = [
    [
      "fa-calendar-range",
      `${formatDate(td.start_date)} → ${formatDate(td.end_date)}`,
    ],
    ["fa-sun", `${days} Day${days > 1 ? "s" : ""}`],
    ["fa-users", `${td.travellers} Traveller${td.travellers > 1 ? "s" : ""}`],
    ["fa-id-badge", td.travel_type],
    ["fa-coins", td.budget],
    td.origin ? ["fa-plane-up", `From ${td.origin}`] : null,
  ].filter(Boolean);
  return chips
    .map(
      ([icon, text]) =>
        `<span class="tp-meta-chip"><i class="fa-solid ${icon}"></i> ${text}</span>`,
    )
    .join("");
}

/* ─────────────────────────────────────────────────────────────────────────
   WEATHER (right column)
───────────────────────────────────────────────────────────────────────── */
function renderWeather(wd) {
  const mockNote = wd.mock
    ? `<p style="font-size:11px;color:var(--tp-muted);margin-top:8px;"><i class="fa-solid fa-circle-info"></i> Demo data — add OpenWeather API key for live weather.</p>`
    : "";

  const html = `
    <div class="tp-weather-main">
      <div>
        <div class="tp-weather-city">${wd.city}${wd.country ? ", " + wd.country : ""}</div>
        <div class="tp-weather-temp">${wd.temperature}°C</div>
        <div class="tp-weather-desc">${wd.description}</div>
      </div>
      <img src="${wd.icon_url}" alt="${wd.description}" width="54" />
    </div>
    <div class="tp-weather-grid">
      ${wStat("💧 Humidity", wd.humidity + "%")}
      ${wStat("💨 Wind", wd.wind_speed + " km/h")}
      ${wStat("🌡️ Feels", wd.feels_like + "°C")}
      ${wStat("👁️ Visibility", wd.visibility)}
      ${wStat("🔼 High", wd.temp_max + "°C")}
      ${wStat("🔽 Low", wd.temp_min + "°C")}
      ${wStat("🌅 Sunrise", wd.sunrise)}
      ${wStat("🌇 Sunset", wd.sunset)}
    </div>
    ${mockNote}`;

  const side = $("#weatherSidebar");
  const det = $("#weatherDetails");
  if (side && det) {
    det.innerHTML = html;
    side.classList.remove("d-none");
  }
}

function wStat(label, value) {
  return `<div class="tp-weather-stat"><div class="tp-ws-label">${label}</div><div class="tp-ws-value">${value}</div></div>`;
}

/* ─────────────────────────────────────────────────────────────────────────
   TRIP STAT CARDS (left column top)
───────────────────────────────────────────────────────────────────────── */
const STAT_CONFIGS = [
  {
    icon: "fa-location-dot",
    color: "#3b82f6",
    bg: "rgba(59,130,246,.14)",
    key: "destination",
    label: "Destination",
  },
  {
    icon: "fa-calendar-days",
    color: "#e3b341",
    bg: "rgba(227,179,65,.14)",
    fn: (td, d) => d + " Days",
    label: "Duration",
  },
  {
    icon: "fa-users",
    color: "#3fb950",
    bg: "rgba(63,185,80,.14)",
    key: "travellers",
    label: "Travellers",
  },
  {
    icon: "fa-id-badge",
    color: "#7c5cd8",
    bg: "rgba(124,92,216,.14)",
    key: "travel_type",
    label: "Trip Type",
  },
  {
    icon: "fa-coins",
    color: "#ff8c42",
    bg: "rgba(255,140,66,.14)",
    key: "budget",
    label: "Budget",
  },
  {
    icon: "fa-utensils",
    color: "#58a6ff",
    bg: "rgba(88,166,255,.14)",
    key: "diet",
    label: "Diet",
  },
];

function renderTripStats(td, days) {
  const el = $("#tripStatCards");
  if (!el) return;
  el.innerHTML = STAT_CONFIGS.map((cfg) => {
    const val = cfg.fn ? cfg.fn(td, days) : td[cfg.key] || "—";
    return `
      <div class="col-6 col-sm-4 col-lg-4 col-xl-2">
        <div class="tp-stat-card">
          <div class="tp-stat-icon" style="background:${cfg.bg};color:${cfg.color};">
            <i class="fa-solid ${cfg.icon}"></i>
          </div>
          <div class="tp-stat-label">${cfg.label}</div>
          <div class="tp-stat-value">${val}</div>
        </div>
      </div>`;
  }).join("");
}

/* ─────────────────────────────────────────────────────────────────────────
   MARKDOWN PARSER — converts raw AI markdown into structured sections
   Handles the new concise AGENT_INSTRUCTIONS format (no emoji in headings)
───────────────────────────────────────────────────────────────────────── */
function parseItinerary(raw) {
  const sections = {
    overview: "",
    days: [], // [{title, segments: [{name, bullets:[]}]}]
    hotels: [], // [{name, desc, price, location, website, bestFor}]
    food: [], // [{name, desc}]
    attractions: [], // [{name, desc}]
    checklist: [], // [{category, items:[]}]
    budget: [], // [{icon, cat, amount, note}]
    budgetRaw: "",
    tips: [],
    safety: [],
    eco: [],
  };

  const lines = raw.split("\n");
  let section = "other";
  let curDay = null;
  let curSeg = null;
  let curCat = null;
  let curHotel = null; // {name, desc, price, location, website, bestFor}

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i].trim();
    const lower = line.toLowerCase();
    if (!line) continue;

    if (/^#{1,4}\s*day\s*\d+/i.test(line) || /^\*\*day\s*\d+/i.test(line)) {
      if (curDay) sections.days.push(curDay);
      curDay = {
        title: line
          .replace(/^#+\s*/, "")
          .replace(/\*\*/g, "")
          .trim(),
        segments: [],
      };
      curSeg = null;
      section = "itinerary";
      continue;
    }

    // ── Section detection (## or # headings) ──────────────────────────
    // if (/^#{1,2}\s/.test(line) && !/^###/.test(line)) {
    if (/^#{1,6}\s/.test(line)) {
      const headLower = mdToText(line).replace(/^#+/, "").trim().toLowerCase();

      // const headLower = lower
      //   .replace(/[#*_🌍📅🍽️🏛️🎒💰💡⚠️🌱✈️🏨]/g, "")
      //   .trim();

      // if (/overview|about|destination/i.test(headLower)) {
      //   section = "overview";
      //   continue;
      // } else if (/day.?wise|itinerary|schedule|plan/i.test(headLower)) {
      //   section = "itinerary";
      //   continue;
      // } else if (
      //   /hotel.?recom|recommend.*hotel|accommodation/i.test(headLower)
      // ) {
      //   section = "hotel";
      //   curHotel = null;
      //   continue;
      // } else if (/food|cuisine|eat|dish|restaurant/i.test(headLower)) {
      //   section = "food";
      //   continue;
      // } else if (/attract|landmark|visit|tourist|sight/i.test(headLower)) {
      //   section = "attraction";
      //   continue;
      // } else if (/packing|checklist|pack|bring/i.test(headLower)) {
      //   section = "checklist";
      //   curCat = null;
      //   continue;
      // } else if (/budget|cost|expense|breakdown|spend/i.test(headLower)) {
      //   section = "budget";
      //   continue;
      // } else if (/tip|advice|suggest|recommend/i.test(headLower)) {
      //   section = "tips";
      //   continue;
      // } else if (/safety|safe|precaution|emergency|warning/i.test(headLower)) {
      //   section = "safety";
      //   continue;
      // } else if (/eco|green|sustain|environment/i.test(headLower)) {
      //   section = "eco";
      //   continue;
      // } else {
      //   section = "other";
      // }
      // continue;

      // if (/^destination overview$/i.test(headLower)) {
      //   section = "overview";
      // } else if (
      //   /^day.?wise itinerary$/i.test(headLower) ||
      //   /^itinerary$/i.test(headLower)
      // ) {
      //   section = "itinerary";
      // } else if (/^hotel recommendations$/i.test(headLower)) {
      //   section = "hotel";
      //   curHotel = null;
      // } else if (/^top tourist attractions$/i.test(headLower)) {
      //   section = "attraction";
      // } else if (/^local food.*guide$/i.test(headLower)) {
      //   section = "food";
      // } else if (/^packing checklist$/i.test(headLower)) {
      //   section = "checklist";
      // } else if (/^budget breakdown$/i.test(headLower)) {
      //   section = "budget";
      // } else if (/^travel tips$/i.test(headLower)) {
      //   section = "tips";
      // } else if (/^safety guidelines$/i.test(headLower)) {
      //   section = "safety";
      // } else if (/^eco.?friendly suggestions$/i.test(headLower)) {
      //   section = "eco";
      // } else {
      //   section = "other";
      // }
      // continue;

      if (headLower.includes("destination overview")) {
        section = "overview";
      } else if (
        headLower.includes("day-wise itinerary") ||
        headLower === "itinerary"
      ) {
        section = "itinerary";
      } else if (headLower.includes("hotel recommendations")) {
        section = "hotel";
        curHotel = null;
      } else if (headLower.includes("top tourist attractions")) {
        section = "attraction";
      } else if (headLower.includes("local food")) {
        section = "food";
      } else if (headLower.includes("packing checklist")) {
        section = "checklist";
      } else if (headLower.includes("budget breakdown")) {
        section = "budget";
      } else if (headLower.includes("travel tips")) {
        section = "tips";
      } else if (headLower.includes("safety guidelines")) {
        section = "safety";
      } else if (headLower.includes("eco")) {
        section = "eco";
      } else {
        section = "other";
      }
      continue;
    }

    // ── Day heading (### Day 1 … or **Day 1 …) ────────────────────────
    // if (/^#{1,4}\s*day\s*\d+/i.test(line) || /^\*\*day\s*\d+/i.test(line)) {
    //   if (curDay) sections.days.push(curDay);
    //   curDay  = {
    //     title: line.replace(/^#+\s*/, '').replace(/\*\*/g, '').trim(),
    //     segments: [],
    //   };
    //   curSeg  = null;
    //   section = 'itinerary';
    //   continue;
    // }

    // ── Time-of-day sub-headings inside a day ─────────────────────────
    if (
      curDay &&
      /morning|afternoon|evening|night|lunch|dinner|breakfast/i.test(line)
    ) {
      // Could be "- **Morning:**", "#### Morning", "**Morning:**", "Morning:"
      const isHeading =
        line.startsWith("#") ||
        line.startsWith("**") ||
        /^-\s*\*\*/.test(line) ||
        /^[-*]\s+(morning|afternoon|evening|night)/i.test(line);
      if (isHeading) {
        const segName = line
          .replace(/^#+\s*/, "")
          .replace(/^[-*]\s*/, "")
          .replace(/\*\*/g, "")
          .replace(/:.*$/, "") // strip colon and everything after for the label
          .trim();

        // If this bullet contains content after the colon, treat it as a bullet too
        const colonIdx = line.indexOf(":");
        const afterColon =
          colonIdx !== -1 ? mdToText(line.slice(colonIdx + 1)).trim() : "";

        curSeg = { name: segName, bullets: [] };
        curDay.segments.push(curSeg);
        if (afterColon) curSeg.bullets.push(afterColon);
        continue;
      }
    }

    // ── Bullet / list item ───────────────────────────────────────────
    const bulletMatch =
      line.match(/^[-*•]\s+(.+)$/) || line.match(/^\d+\.\s+(.+)$/);
    const bulletText = bulletMatch ? mdToText(bulletMatch[1]) : "";

    // Itinerary day content
    if (section === "itinerary" && curDay) {
      if (!curSeg) {
        curSeg = { name: "Activities", bullets: [] };
        curDay.segments.push(curSeg);
      }
      if (bulletText) curSeg.bullets.push(bulletText);
      else if (!line.startsWith("#") && !line.startsWith("**Day")) {
        const txt = mdToText(line);
        if (txt) curSeg.bullets.push(txt);
      }
      continue;
    }

    // Food items
    if (section === "food" && bulletText) {
      const parts = bulletText.split(/[:\-–]/);
      sections.food.push({
        name: parts[0].trim(),
        desc: parts.slice(1).join(" ").trim() || "A local speciality.",
      });
      continue;
    }

    // Attraction items
    if (section === "attraction" && bulletText) {
      const parts = bulletText.split(/[:\-–]/);
      sections.attractions.push({
        name: parts[0].trim(),
        desc: parts.slice(1).join(" ").trim() || "Popular tourist spot.",
      });
      continue;
    }

    // Tips / safety / eco
    // Collect both bullet lines AND plain-text lines so no content is dropped
    // when the AI omits the leading "- " prefix on some suggestions.
    if (section === "tips") {
      const t = bulletText || (!line.startsWith("#") ? mdToText(line) : "");
      if (t) {
        sections.tips.push(t);
        continue;
      }
    }
    if (section === "safety") {
      const t = bulletText || (!line.startsWith("#") ? mdToText(line) : "");
      if (t) {
        sections.safety.push(t);
        continue;
      }
    }

    // if (section === "eco") {
    //   const t = bulletText || (!line.startsWith("#") ? mdToText(line) : "");
    //   if (t) {
    //     sections.eco.push(t);
    //     continue;
    //   }
    // }

    if (section === "eco") {
      if (line.startsWith("#")) continue;

      const text = mdToText(
        bulletText ? bulletText : line.replace(/^[-*•]\s*/, ""),
      ).trim();

      if (text && !sections.eco.includes(text)) {
        sections.eco.push(text);
      }

      continue;
    }

    // Overview (plain text lines, no heading)
    if (section === "overview" && !line.startsWith("#")) {
      sections.overview += mdToText(line) + " ";
      continue;
    }

    // Checklist
    if (section === "checklist") {
      // Category heading
      if (
        line.startsWith("#") ||
        (line.startsWith("**") && line.endsWith("**"))
      ) {
        const catName = line
          .replace(/^#+\s*|\*\*/g, "")
          .replace(/:$/, "")
          .trim();
        curCat = { category: catName, items: [] };
        sections.checklist.push(curCat);
      } else if (bulletText) {
        if (!curCat) {
          curCat = { category: "Essentials", items: [] };
          sections.checklist.push(curCat);
        }
        curCat.items.push(bulletText);
      }
      continue;
    }

    // Budget — use raw line so ** markers are still intact for label extraction
    if (section === "budget") {
      sections.budgetRaw += line + "\n";
      const entry = parseBudgetLine(line);
      if (entry) sections.budget.push(entry);
      continue;
    }

    // ── Hotel Recommendations ─────────────────────────────────────────
    // The AI produces:
    //   - **Hotel Name**           ← bold bullet = new hotel entry
    //   - Description: …
    //   - Estimated Price: …
    //   - Location: …
    //   - Website/Map: …
    //   - Best For: …
    if (section === "hotel") {
      // New hotel: bold-bullet at the top level  "- **Name**"
      const hotelNameMatch =
        line.match(/^[-*•]\s+\*\*(.+?)\*\*\s*$/) ||
        line.match(/^[-*•]\s+\*\*(.+?)\*\*/);

      // const hotelNameMatch =
      //   line.match(/^[-*•]?\s*\*\*(.+?)\*\*/) || line.match(/^[-*•]?\s*(.+)$/);

      if (hotelNameMatch) {
        if (curHotel) sections.hotels.push(curHotel);
        curHotel = {
          name: mdToText(hotelNameMatch[1]).trim(),
          desc: "",
          price: "",
          location: "",
          website: "",
          bestFor: "",
        };
        continue;
      }
      // Sub-fields indented under current hotel
      if (curHotel) {
        // Strip leading list markers and whitespace:  "  - Description: …"
        const sub = line.replace(/^[\s]*[-*•]\s*/, "").trim();
        const fieldMatch = sub.match(
          /^(Description|Estimated\s*Price|Price|Location|Website\/Map|Website|Map|Best\s*For)\s*[:–\-]\s*(.+)$/i,
        );
        if (fieldMatch) {
          const key = fieldMatch[1].toLowerCase().replace(/[\s\/]+/g, "");
          const val = mdToText(fieldMatch[2]).trim();
          if (key.startsWith("desc")) curHotel.desc = val;
          else if (key.includes("price")) curHotel.price = val;
          else if (key.includes("loc")) curHotel.location = val;
          else if (key.includes("web") || key.includes("map"))
            curHotel.website = val;
          else if (key.includes("best") || key.includes("for"))
            curHotel.bestFor = val;
        }
      }
      continue;
    }
  }

  if (curDay) sections.days.push(curDay);
  if (curHotel) sections.hotels.push(curHotel);
  sections.overview = sections.overview.trim();
  return sections;
}

/* ─────────────────────────────────────────────────────────────────────────
   parseBudgetLine — extract one {cat, amount, icon, note} from a raw line
   Handles every variant the AI produces:
     - **Transport:** 2500 INR
     - **Hotel:** ₹6,000
     - **Food:** INR 3000
     - Transport: 2500
     - Transport – 2500 INR
     - 1. Transport: INR 2,500
   Works on the RAW line (before mdToText) so ** markers are still present.
───────────────────────────────────────────────────────────────────────── */
function parseBudgetLine(rawLine) {
  const CATEGORY_MAP = [
    {
      key: "transport",
      aliases: ["transport", "flight", "train", "bus", "cab", "taxi", "travel"],
      icon: "🚗",
      color: "#3b82f6",
    },
    // { key: 'hotel',     aliases: ['hotel','accommod','stay','hostel','resort','lodge','room'], icon: '🏨', color: '#7c5cd8' },
    {
      key: "hotel",
      aliases: [
        "hotel",
        "hotels",
        "accommodation",
        "accommod",
        "stay",
        "stays",
        "hostel",
        "hostels",
        "guesthouse",
        "guesthouses",
        "guest house",
        "guest houses",
        "resort",
        "resorts",
        "lodge",
        "lodges",
        "room",
        "rooms",
      ],
      icon: "🏨",
      color: "#7c5cd8",
    },
    {
      key: "food",
      aliases: ["food", "meal", "dining", "restaurant", "eat", "cuisine"],
      icon: "🍽️",
      color: "#ff8c42",
    },
    {
      key: "activity",
      aliases: ["activit", "entertain", "ticket", "tour", "excursion", "sight"],
      icon: "🎟️",
      color: "#3fb950",
    },
    {
      key: "misc",
      aliases: ["misc", "other", "shopping", "personal", "extra", "sundry"],
      icon: "🧳",
      color: "#58a6ff",
    },
    {
      key: "total",
      aliases: ["total", "grand", "overall", "sum", "budget"],
      icon: "💰",
      color: "#e3b341",
    },
  ];

  // Strip list/bullet prefix: leading "- ", "* ", "• ", "1. " etc.
  let line = rawLine
    .trim()
    .replace(/^[-*•]\s+/, "")
    .replace(/^\d+\.\s+/, "");

  // Strip bold markers (**…**) — but keep the text and the colon after
  // Pattern: **SomeLabel:** → SomeLabel:
  line = line.replace(/\*\*([^*]+)\*\*/g, "$1");
  // Also strip any remaining lone * or _
  line = line.replace(/\*+/g, "").replace(/_{2,}/g, "").trim();

  // Must contain at least one digit to be a valid budget entry
  if (!/\d/.test(line)) return null;

  // ── Extract category label ──────────────────────────────────────────
  // Strategy: text before the first colon, dash/en-dash/em-dash, or digit
  // let cat = '';
  // const colonMatch = line.match(/^([^:\d₹$€£]+)[:\-–—]/);
  // if (colonMatch) {
  //   cat = colonMatch[1].trim();
  // } else {
  //   // No separator — take all text before the first digit
  //   const beforeDigit = line.match(/^([^\d₹$€£]+)/);
  //   cat = beforeDigit ? beforeDigit[1].trim() : '';
  // }
  // cat = cat.replace(/[*_\-–—•]/g, '').trim();

  // if (!cat) return null;  // Can't identify the category; skip

  let cat = "";

  const m = line.match(/^([^:]+?)\s*:/);

  if (m) {
    cat = m[1].trim();
  } else {
    const m2 = line.match(/^([A-Za-z ]+)/);
    cat = m2 ? m2[1].trim() : "";
  }

  cat = cat.toLowerCase();

  // ── Extract numeric amount ──────────────────────────────────────────
  // Supports: 2500, 2,500, 2500.00, INR 2500, ₹2500, 2500 INR
  const amtMatch =
    line.match(
      /(?:INR|USD|EUR|GBP|AED|SGD|THB|JPY|AUD|₹|\$|€|£)\s*([\d,]+(?:\.\d+)?)/i,
    ) ||
    line.match(
      /([\d,]+(?:\.\d+)?)\s*(?:INR|USD|EUR|GBP|AED|SGD|THB|JPY|AUD|₹|\$|€|£)/i,
    ) ||
    line.match(/([\d,]+(?:\.\d+)?)/);
  const amount = amtMatch
    ? amtMatch[1].replace(/,/g, "").replace(/\.\d+$/, "")
    : "";

  // ── Match to a canonical category ──────────────────────────────────
  const lowerCat = cat.toLowerCase();
  const lowerLine = line.toLowerCase();
  // const matched = CATEGORY_MAP.find(c =>
  //   c.aliases.some(a => lowerCat.includes(a) || lowerLine.includes(a))
  // );

  // const matched = CATEGORY_MAP.find((c) =>
  //   c.aliases.some((alias) => cat.includes(alias)),
  // );

  const matched = CATEGORY_MAP.find((c) =>
    c.aliases.some(
      (alias) => cat.includes(alias) || line.toLowerCase().includes(alias),
    ),
  );

  if (!matched) return null; // Unknown category; skip
  return {
    key: matched.key,
    cat: cat,
    amount: amount,
    icon: matched.icon,
    color: matched.color,
    note: mdToText(rawLine), // clean version for the tooltip/subtitle
  };
}

/* ─────────────────────────────────────────────────────────────────────────
   OVERVIEW (left column)
───────────────────────────────────────────────────────────────────────── */
function renderOverview(parsed) {
  const el = $("#overviewContent");
  if (!el) return;
  el.textContent =
    parsed.overview ||
    "Your AI-generated itinerary is ready. Explore the sections below for your day-wise plan, budget, attractions, and more.";
}

/* ─────────────────────────────────────────────────────────────────────────
   ALERTS — Safety & Tips (left column)
───────────────────────────────────────────────────────────────────────── */
function renderAlerts(parsed) {
  renderAlertBlock(
    "#safetyContent",
    parsed.safety,
    "No safety tips found. Review the full itinerary text for safety guidance.",
    "warning",
  );
  renderAlertBlock(
    "#tipsContent",
    parsed.tips,
    "No travel tips found. Check the itinerary for destination-specific advice.",
    "info",
  );
}

function renderAlertBlock(sel, items, fallback, type) {
  const el = $(sel);
  if (!el) return;
  if (!items || !items.length) {
    el.innerHTML = `<span class="tp-muted">${fallback}</span>`;
    el.className = `tp-alert-block tp-alert-${type}`;
    return;
  }
  el.innerHTML = `<ul>${items.map((t) => `<li>${t}</li>`).join("")}</ul>`;
  el.className = `tp-alert-block tp-alert-${type}`;
}

/* ─────────────────────────────────────────────────────────────────────────
   ECO-FRIENDLY (left column)
───────────────────────────────────────────────────────────────────────── */
function renderEco(parsed) {
  renderAlertBlock(
    "#ecoContent",
    parsed.eco,
    "Be mindful of your environmental impact. Choose public transport where possible.",
    "success",
  );
}

/* ─────────────────────────────────────────────────────────────────────────
   HOTEL RECOMMENDATIONS (left column — below Day-wise Itinerary)
───────────────────────────────────────────────────────────────────────── */
function renderHotels(parsed) {
  const section = $("#hotelSection");
  const el = $("#hotelCards");
  if (!el || !section) return;

  const hotels = parsed.hotels || [];

  // Hide the whole card if the AI returned nothing
  if (!hotels.length) {
    section.classList.add("d-none");
    return;
  }

  // Accent colours cycled across the three cards — matches existing UI palette
  const ACCENT_COLORS = [
    {
      icon: "#3b82f6",
      border: "rgba(59,130,246,0.25)",
      bg: "rgba(59,130,246,0.10)",
    },
    {
      icon: "#7c5cd8",
      border: "rgba(124,92,216,0.25)",
      bg: "rgba(124,92,216,0.10)",
    },
    {
      icon: "#059669",
      border: "rgba(5,150,105,0.25)",
      bg: "rgba(5,150,105,0.10)",
    },
  ];

  el.innerHTML = hotels
    .map((h, idx) => {
      const ac = ACCENT_COLORS[idx % ACCENT_COLORS.length];

      // Build the "View / Search" link button
      const website = (h.website || "").trim();
      const isRealUrl = /^https?:\/\//i.test(website);
      const isSearch = /search on google/i.test(website) || website === "";
      let linkBtn = "";
      if (isRealUrl) {
        linkBtn = `<a href="${website}" target="_blank" rel="noopener noreferrer" class="tp-hotel-link-btn">
                   <i class="fa-solid fa-arrow-up-right-from-square"></i> Visit Website
                 </a>`;
      } else if (!isSearch && website) {
        // AI gave something that isn't a URL — treat as a search query
        const q = encodeURIComponent(h.name + " " + website);
        linkBtn = `<a href="https://www.google.com/maps/search/?api=1&query=${q}" target="_blank" rel="noopener noreferrer" class="tp-hotel-link-btn">
                   <i class="fa-solid fa-map-location-dot"></i> View on Map
                 </a>`;
      } else {
        // "Search on Google Maps" fallback
        const q = encodeURIComponent(h.name);
        linkBtn = `<a href="https://www.google.com/maps/search/?api=1&query=${q}" target="_blank" rel="noopener noreferrer" class="tp-hotel-link-btn">
                   <i class="fa-solid fa-magnifying-glass-location"></i> Search on Maps
                 </a>`;
      }

      const priceHtml = h.price
        ? `<div class="tp-hotel-price" style="color:${ac.icon};">${h.price}</div>`
        : "";
      const locationHtml = h.location
        ? `<div class="tp-hotel-location"><i class="fa-solid fa-location-dot" style="color:${ac.icon};"></i> ${h.location}</div>`
        : "";
      const bestForHtml = h.bestFor
        ? `<div class="tp-hotel-bestfor"><i class="fa-solid fa-star" style="color:${ac.icon};"></i> Best For: ${h.bestFor}</div>`
        : "";

      return `
      <div class="col-12 col-md-6 col-lg-4 d-flex">
        <div class="tp-hotel-card w-100" style="border-color:${ac.border};">
          <div class="tp-hotel-icon-wrap" style="background:${ac.bg};border-color:${ac.border};">
            <i class="fa-solid fa-hotel" style="color:${ac.icon};"></i>
          </div>
          <div class="tp-hotel-name">${h.name}</div>
          ${h.desc ? `<div class="tp-hotel-desc">${h.desc}</div>` : ""}
          ${priceHtml}
          ${locationHtml}
          ${bestForHtml}
          <div class="tp-hotel-footer">${linkBtn}</div>
        </div>
      </div>`;
    })
    .join("");

  section.classList.remove("d-none");
}

/* ─────────────────────────────────────────────────────────────────────────
   DAY ACCORDION (left column)
───────────────────────────────────────────────────────────────────────── */
const SEG_ICONS = {
  morning: "fa-sun",
  afternoon: "fa-cloud-sun",
  evening: "fa-moon",
  night: "fa-star",
  lunch: "fa-bowl-food",
  dinner: "fa-wine-glass",
  breakfast: "fa-mug-hot",
  default: "fa-circle-dot",
};

function renderAccordion(days, totalDays) {
  const container = $("#itineraryAccordion");
  if (!container) return;

  if (!days || !days.length) {
    container.innerHTML = `<div class="tp-muted p-3 text-center">
      <i class="fa-solid fa-circle-info"></i> Day-wise breakdown not available. View raw itinerary in the Budget section.
    </div>`;
    return;
  }

  container.innerHTML = days
    .map((day, idx) => {
      const id = `day-acc-${idx}`;
      const isFirst = idx === 0;

      const segments = day.segments.length
        ? day.segments
            .map((seg) => {
              const iconKey =
                Object.keys(SEG_ICONS).find((k) =>
                  seg.name.toLowerCase().includes(k),
                ) || "default";
              const icon = SEG_ICONS[iconKey];
              const bullets = seg.bullets
                .slice(0, 6)
                .map(
                  (b) =>
                    `<div class="tp-day-bullet"><i class="fa-solid fa-circle-right"></i><span>${b}</span></div>`,
                )
                .join("");
              return `
            <div class="tp-day-segment">
              <div class="tp-day-segment-title"><i class="fa-solid ${icon}"></i> ${seg.name}</div>
              ${bullets || '<div class="tp-muted" style="font-size:12px;">See full itinerary for details.</div>'}
            </div>`;
            })
            .join("")
        : '<div class="tp-muted" style="font-size:13px;">Day details in full itinerary text.</div>';

      // Strip "Day N:" prefix from title for cleaner display
      const displayTitle = day.title.replace(/^day\s*\d+[:.]?\s*/i, "").trim();

      return `
      <div class="accordion-item">
        <h2 class="accordion-header">
          <button class="accordion-button ${isFirst ? "" : "collapsed"}"
                  type="button"
                  data-bs-toggle="collapse"
                  data-bs-target="#${id}"
                  aria-expanded="${isFirst}">
            <span class="tp-acc-day-badge">
              <i class="fa-solid fa-calendar-day"></i> Day ${idx + 1}
            </span>
            ${displayTitle || `Day ${idx + 1}`}
          </button>
        </h2>
        <div id="${id}" class="accordion-collapse collapse ${isFirst ? "show" : ""}"
             data-bs-parent="#itineraryAccordion">
          <div class="accordion-body">${segments}</div>
        </div>
      </div>`;
    })
    .join("");
}

/* ─────────────────────────────────────────────────────────────────────────
   BUDGET CARDS (left column)
   Uses parseBudgetLine() results — six fixed canonical slots with
   per-category colour, auto-calculated Total when AI omits it.
───────────────────────────────────────────────────────────────────────── */

// Six canonical slots displayed in the UI (order matters)
const BUDGET_SLOTS = [
  { key: "transport", label: "Transport", icon: "🚗", color: "#3b82f6" },
  { key: "hotel", label: "Hotel", icon: "🏨", color: "#7c5cd8" },
  { key: "food", label: "Food", icon: "🍽️", color: "#ff8c42" },
  { key: "activity", label: "Activities", icon: "🎟️", color: "#3fb950" },
  { key: "misc", label: "Miscellaneous", icon: "🧳", color: "#58a6ff" },
  { key: "total", label: "Total", icon: "💰", color: "#e3b341" },
];

function renderBudget(parsed, td) {
  const el = $("#budgetCards");
  if (!el) return;

  const sym = td.currency || "INR";
  const items = parsed.budget || []; // [{key, cat, amount, icon, color, note}]

  // ── Step 1: build a key → amount lookup from parsed items ──────────
  // If the AI returns the same category twice, take the first occurrence.
  const amtByKey = {};
  items.forEach((item) => {
    if (item.key && item.amount && !(item.key in amtByKey)) {
      amtByKey[item.key] = parseInt(item.amount, 10) || 0;
    }
  });

  // ── Step 2: auto-calculate Total when missing ───────────────────────
  if (!amtByKey.total) {
    const nonTotalKeys = ["transport", "hotel", "food", "activity", "misc"];
    const calculatedTotal = nonTotalKeys.reduce(
      (sum, k) => sum + (amtByKey[k] || 0),
      0,
    );
    if (calculatedTotal > 0) amtByKey.total = calculatedTotal;
  }

  // ── Step 3: check if we actually extracted anything usable ──────────
  const hasData = Object.keys(amtByKey).length > 0;

  if (!hasData && parsed.budgetRaw.trim()) {
    // Absolute fallback — show "Not Available" cards + the raw AI text below
    el.innerHTML = BUDGET_SLOTS.map(
      (s) => `
      <div class="col-6 col-md-4 d-flex">
        <div class="tp-budget-stat w-100">
          <div class="tp-budget-icon-wrap" style="background:${s.color}18;border-color:${s.color}33;">
            <span>${s.icon}</span>
          </div>
          <div class="tp-budget-cat">${s.label}</div>
          <div class="tp-budget-amt" style="color:${s.color};">Not Available</div>
        </div>
      </div>`,
    ).join("");

    const rawEl = $("#budgetTable");
    if (rawEl) {
      rawEl.innerHTML = `
        <div class="tp-alert-block tp-alert-info mt-2">
          <pre style="font-size:12px;line-height:1.75;white-space:pre-wrap;margin:0;">${parsed.budgetRaw.trim()}</pre>
        </div>`;
    }
    return;
  }

  // ── Step 4: render six canonical cards — show "Not Available" only
  //            for the individual categories that are genuinely missing ─
  el.innerHTML = BUDGET_SLOTS.map((slot) => {
    const amt = amtByKey[slot.key];
    const fmtAmt = amt
      ? `${sym}\u00A0${amt.toLocaleString("en-IN")}`
      : "Not Available";

    // Find a matching parsed item for its subtitle/note — full text, no truncation
    const matched = items.find((i) => i.key === slot.key);
    const note = matched?.note ? matched.note.trim() : "";

    return `
      <div class="col-6 col-md-4 d-flex">
        <div class="tp-budget-stat w-100">
          <div class="tp-budget-icon-wrap" style="background:${slot.color}18;border-color:${slot.color}33;">
            <span>${slot.icon}</span>
          </div>
          <div class="tp-budget-cat">${slot.label}</div>
          <div class="tp-budget-amt" style="color:${slot.color};">${fmtAmt}</div>
          ${note ? `<div class="tp-budget-note">${note}</div>` : ""}
        </div>
      </div>`;
  }).join("");
}

/* ─────────────────────────────────────────────────────────────────────────
   ATTRACTION CARDS (right column — stacked list)
───────────────────────────────────────────────────────────────────────── */
const ATTR_ICONS = [
  "🏛️",
  "🗺️",
  "🌊",
  "🏔️",
  "🌳",
  "🎭",
  "🕌",
  "⛩️",
  "🏰",
  "🌅",
  "🎡",
  "🏖️",
];

function renderAttractions(parsed) {
  const el = $("#attractionsGrid");
  if (!el) return;

  const items = parsed.attractions;
  if (!items || !items.length) {
    el.innerHTML = `<p class="tp-muted" style="font-size:13px;">No attractions extracted. Check the Day-wise Itinerary for sightseeing details.</p>`;
    return;
  }

  el.innerHTML = items
    .slice(0, 5)
    .map(
      (attr, i) => `
    <div class="tp-attraction-card">
      <div class="tp-attr-num">${ATTR_ICONS[i % ATTR_ICONS.length]}</div>
      <div>
        <div class="tp-attr-name">${attr.name}</div>
        <div class="tp-attr-desc">${attr.desc || "Must-visit spot at this destination."}</div>
      </div>
    </div>`,
    )
    .join("");
}

/* ─────────────────────────────────────────────────────────────────────────
   FOOD CARDS (right column — stacked list)
───────────────────────────────────────────────────────────────────────── */
const FOOD_EMOJIS = [
  "🍛",
  "🍜",
  "🥘",
  "🍣",
  "🥗",
  "🍲",
  "🥙",
  "🍝",
  "🧆",
  "🍤",
  "🥩",
  "🍱",
];

function renderFood(parsed) {
  const el = $("#foodCards");
  if (!el) return;

  const items = parsed.food;
  if (!items || !items.length) {
    el.innerHTML = `<p class="tp-muted" style="font-size:13px;">No food recommendations extracted. Check the itinerary for local cuisine tips.</p>`;
    return;
  }

  el.innerHTML = items
    .slice(0, 5)
    .map(
      (f, i) => `
    <div class="tp-food-card">
      <div class="tp-food-emoji">${FOOD_EMOJIS[i % FOOD_EMOJIS.length]}</div>
      <div>
        <div class="tp-food-name">${f.name}</div>
        <div class="tp-food-desc">${f.desc || "A local favourite worth trying."}</div>
      </div>
    </div>`,
    )
    .join("");
}

/* ─────────────────────────────────────────────────────────────────────────
   PACKING CHECKLIST (right column)
───────────────────────────────────────────────────────────────────────── */
const DEFAULT_CHECKLIST = [
  {
    category: "📄 Documents",
    items: [
      "Passport / Aadhaar / ID",
      "Visa (if required)",
      "Booking confirmations",
      "Travel insurance",
    ],
  },
  {
    category: "👕 Clothing",
    items: [
      "Comfortable walking shoes",
      "Weather-appropriate outfits",
      "Lightweight jacket",
    ],
  },
  {
    category: "🔌 Gadgets",
    items: ["Phone + charger", "Power bank", "Universal adapter"],
  },
  {
    category: "💊 Health",
    items: ["Personal medications", "Sunscreen SPF 50+", "Hand sanitiser"],
  },
];

function renderChecklist(parsed) {
  const container = $("#checklistContent");
  if (!container) return;

  // Use AI-parsed checklist if available, else default
  let cats = parsed.checklist?.length ? parsed.checklist : null;

  // If AI gave a flat list (no categories), wrap in one category
  if (!cats && parsed.checklist?.length === 0) {
    cats = DEFAULT_CHECKLIST;
  }
  if (!cats) cats = DEFAULT_CHECKLIST;

  const totalItems = cats.reduce((a, c) => a + c.items.length, 0);

  container.innerHTML = cats
    .map(
      (cat, ci) => `
    <div class="tp-checklist-cat">
      <div class="tp-checklist-cat-title">${cat.category}</div>
      ${cat.items
        .map((item, ii) => {
          const id = `chk-${ci}-${ii}`;
          return `
          <div class="tp-check-item">
            <input type="checkbox" id="${id}" onchange="updateChecklistProgress()" />
            <label for="${id}">${item}</label>
          </div>`;
        })
        .join("")}
    </div>`,
    )
    .join("");

  // Initialise progress label
  const label = $("#checkLabel");
  if (label) label.textContent = `0 / ${totalItems} items packed`;

  // Reset button
  $("#resetChecklistBtn")?.addEventListener("click", () => {
    $$('#checklistContent input[type="checkbox"]').forEach((c) => {
      c.checked = false;
    });
    updateChecklistProgress();
  });
}

window.updateChecklistProgress = function () {
  const all = $$('#checklistContent input[type="checkbox"]');
  const checked = all.filter((c) => c.checked).length;
  const pct = all.length ? Math.round((checked / all.length) * 100) : 0;
  const bar = $("#checkProgress");
  const label = $("#checkLabel");
  if (bar) bar.style.width = pct + "%";
  if (label) label.textContent = `${checked} / ${all.length} items packed`;
};

/* ─────────────────────────────────────────────────────────────────────────
   ACTION BAR BUTTONS
───────────────────────────────────────────────────────────────────────── */
function setupActions() {
  // PDF download via hidden form POST
  $("#downloadPdfBtn")?.addEventListener("click", () => {
    if (!window.__itineraryText) {
      showToast("Itinerary not ready yet.", "error");
      return;
    }
    const form = document.createElement("form");
    form.method = "POST";
    form.action = "api/generate-pdf.php";
    form.target = "_blank";
    const addField = (k, v) => {
      const i = document.createElement("input");
      i.type = "hidden";
      i.name = k;
      i.value = v;
      form.appendChild(i);
    };
    addField("itinerary", window.__itineraryText);
    addField("travel_data", JSON.stringify(window.__travelData || {}));
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
    showToast("Opening PDF…", "info");
  });

  // Print
  $("#printBtn")?.addEventListener("click", () => window.print());

  // Copy to clipboard
  $("#copyBtn")?.addEventListener("click", () => {
    if (!window.__itineraryText) {
      showToast("Nothing to copy yet.", "error");
      return;
    }
    navigator.clipboard
      .writeText(window.__itineraryText)
      .then(() => showToast("Itinerary copied to clipboard!", "success"))
      .catch(() =>
        showToast("Copy failed. Please select and copy manually.", "error"),
      );
  });
}

/* ─────────────────────────────────────────────────────────────────────────
   EMAIL MODAL
───────────────────────────────────────────────────────────────────────── */
function setupEmail() {
  const sendBtn = $("#sendEmailBtn");
  if (!sendBtn) return;

  sendBtn.addEventListener("click", async () => {
    const emailEl = $("#emailInput");
    const email = emailEl?.value?.trim();
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setEmailStatus("Please enter a valid email address.", "danger");
      return;
    }
    if (!window.__itineraryText) {
      setEmailStatus("Itinerary not ready.", "danger");
      return;
    }

    sendBtn.disabled = true;
    sendBtn.querySelector(".btn-content")?.classList.add("d-none");
    sendBtn.querySelector(".btn-loading")?.classList.remove("d-none");
    setEmailStatus("Sending your itinerary…", "secondary");

    try {
      const res = await fetch("api/send-email.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          email,
          itinerary: window.__itineraryText,
          travel_data: window.__travelData || {},
        }),
      });
      const data = await res.json();
      if (data.success) {
        setEmailStatus("✅ " + data.message, "success");
        showToast("Itinerary emailed successfully!", "success");
        setTimeout(() => {
          bootstrap.Modal.getInstance($("#emailModal"))?.hide();
        }, 2000);
      } else {
        setEmailStatus("❌ " + data.error, "danger");
      }
    } catch (_) {
      setEmailStatus("Network error. Please try again.", "danger");
    } finally {
      sendBtn.disabled = false;
      sendBtn.querySelector(".btn-content")?.classList.remove("d-none");
      sendBtn.querySelector(".btn-loading")?.classList.add("d-none");
    }
  });
}

function setEmailStatus(msg, type) {
  const el = $("#emailStatus");
  if (!el) return;
  el.className = `alert alert-${type} py-2 px-3 mt-2`;
  el.style.fontSize = "13px";
  el.textContent = msg;
  el.classList.remove("d-none");
}

/* ─────────────────────────────────────────────────────────────────────────
   ERROR STATE
───────────────────────────────────────────────────────────────────────── */
function showError(message) {
  $("#loadingOverlay")?.classList.add("d-none");
  $("#errorState")?.classList.remove("d-none");
  const msgEl = $("#errorMessage");
  if (msgEl) msgEl.textContent = message;
}

/* ─────────────────────────────────────────────────────────────────────────
   TOAST NOTIFICATIONS
───────────────────────────────────────────────────────────────────────── */
function showToast(message, type = "info", duration = 3500) {
  let container = $(".tp-toast-container");
  if (!container) {
    container = document.createElement("div");
    container.className = "tp-toast-container";
    document.body.appendChild(container);
  }
  const icons = { success: "✅", error: "❌", info: "ℹ️", warning: "⚠️" };
  const toast = document.createElement("div");
  toast.className = `tp-toast ${type}`;
  toast.innerHTML = `<span>${icons[type] || "ℹ️"}</span><span>${message}</span>`;
  container.appendChild(toast);
  setTimeout(() => {
    toast.style.opacity = "0";
    toast.style.transition = "opacity .4s ease";
    setTimeout(() => toast.remove(), 400);
  }, duration);
}
