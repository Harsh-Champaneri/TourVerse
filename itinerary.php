<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>TourVerse — AI Travel Planner</title>
  <meta name="description" content="Your personalised AI-generated travel itinerary powered by IBM watsonx.ai Granite." />
  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
  <!-- Font Awesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <!-- Leaflet.js CSS -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <!-- Marked.js (Markdown → HTML) -->
  <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
  <!-- Custom CSS -->
  <link rel="stylesheet" href="assets/css/style.css" />
</head>

<body class="itinerary-page">

  <!-- ── NAVBAR ──────────────────────────────────────────────────────────── -->
  <nav class="tp-navbar">
    <div class="container-fluid px-4 d-flex align-items-center justify-content-between" style="height:60px;">
      <a href="index.php" class="tp-brand">
        <span class="tp-brand-icon"><i class="fa-solid fa-map-location-dot"></i></span>
        <span class="tp-brand-name"><span>Tour</span>Verse</span>
      </a>
      <div class="d-flex align-items-center gap-2">
        <a href="index.php" class="tp-nav-link-btn d-none d-sm-inline-flex"><i class="fa-solid fa-plus"></i> New Trip</a>
        <span class="tp-ibm-pill d-none d-md-inline-flex"><i class="fa-solid fa-atom"></i> IBM Granite</span>
        <button class="tp-icon-btn" id="themeToggle" title="Toggle theme"><i class="fa-solid fa-moon"></i></button>
      </div>
    </div>
  </nav>

  <!-- ── LOADING OVERLAY ─────────────────────────────────────────────────── -->
  <div id="loadingOverlay" class="tp-loading-overlay">
    <div class="tp-loading-box">
      <div class="tp-loading-globe">🌍</div>
      <div class="tp-loading-ring"></div>
      <h4 class="mt-4 mb-1 fw-700">Crafting Your Itinerary</h4>
      <p class="tp-muted mb-4" id="loadingStatus">Connecting to IBM watsonx.ai…</p>
      <div class="tp-loading-steps">
        <div class="tp-load-step active" id="step1"><i class="fa-solid fa-cloud"></i> Connecting to watsonx.ai</div>
        <div class="tp-load-step" id="step2"><i class="fa-solid fa-microchip"></i> Generating itinerary with Granite</div>
        <div class="tp-load-step" id="step3"><i class="fa-solid fa-cloud-sun"></i> Fetching weather data</div>
        <div class="tp-load-step" id="step4"><i class="fa-solid fa-map"></i> Loading destination map</div>
      </div>
    </div>
  </div>

  <!-- ── STICKY ACTION BAR ─────────────────────────────────────────────── -->
  <div class="tp-action-bar d-none" id="actionToolbar">
    <div class="container-fluid px-4">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <button class="tp-action-btn tp-action-primary" id="downloadPdfBtn">
            <i class="fa-solid fa-file-pdf"></i><span class="d-none d-sm-inline"> PDF</span>
          </button>
          <button class="tp-action-btn tp-action-primary" id="emailBtn" data-bs-toggle="modal" data-bs-target="#emailModal">
            <i class="fa-solid fa-envelope"></i><span class="d-none d-sm-inline"> Email</span>
          </button>
          <button class="tp-action-btn tp-action-secondary" id="printBtn">
            <i class="fa-solid fa-print"></i><span class="d-none d-sm-inline"> Print</span>
          </button>
          <button class="tp-action-btn tp-action-secondary" id="copyBtn">
            <i class="fa-solid fa-copy"></i><span class="d-none d-sm-inline"> Copy</span>
          </button>
          <a href="index.php" class="tp-action-btn tp-action-secondary">
            <i class="fa-solid fa-arrow-left"></i><span class="d-none d-sm-inline"> New Trip</span>
          </a>
        </div>
        <div class="tp-ibm-pill tp-ibm-sm d-none d-md-inline-flex">
          <i class="fa-solid fa-atom"></i>
          <span id="modelBadge">ibm/granite-3-3-8b-instruct</span>
        </div>
      </div>
    </div>
  </div>

  <!-- ── HERO BANNER ──────────────────────────────────────────────────────── -->
  <section class="tp-itin-hero d-none" id="itineraryHero">
    <div class="tp-itin-hero-bg">
      <div class="tp-orb tp-orb-1"></div>
      <div class="tp-orb tp-orb-2"></div>
    </div>
    <div class="container position-relative">
      <div class="tp-hero-badge mb-3"><i class="fa-solid fa-sparkles"></i> AI-Generated Itinerary · IBM watsonx.ai Granite</div>
      <h1 class="tp-itin-title" id="itineraryDestination">Your Destination</h1>
      <div class="tp-meta-chips mt-2" id="itineraryMeta"><!-- JS fills this --></div>
    </div>
  </section>

  <!-- ── MAIN CONTENT — Two-column layout ─────────────────────────────────── -->
  <main class="tp-dashboard d-none" id="itineraryMain">
    <div class="container-fluid px-3 px-md-4 py-4">
      <div class="row g-4">

        <!-- ═══════════════════════════════
             LEFT COLUMN
             Trip summary · Itinerary accordion
             Budget cards · Tips · Safety
        ════════════════════════════════ -->
        <div class="col-lg-7 col-xl-8">

          <!-- Trip Summary stat bar -->
          <div class="row g-3 mb-4" id="tripStatCards"><!-- JS --></div>

          <!-- Destination Overview -->
          <div class="tp-card mb-4">
            <div class="tp-card-title"><i class="fa-solid fa-earth-asia"></i> Destination Overview</div>
            <div id="overviewContent" class="tp-overview-text"><!-- JS --></div>
          </div>

          <!-- Day-wise Accordion -->
          <div class="tp-card mb-4">
            <div class="tp-card-title mb-3"><i class="fa-solid fa-route"></i> Day-wise Itinerary</div>
            <div class="accordion tp-accordion" id="itineraryAccordion"><!-- JS --></div>
          </div>

          <!-- Hotel Recommendations — JS-rendered, hidden until populated -->
          <div class="tp-card mb-4 d-none" id="hotelSection">
            <div class="tp-card-title mb-3">
              <i class="fa-solid fa-hotel"></i> Hotel Recommendations
            </div>
            <div class="row g-3" id="hotelCards"><!-- JS fills this --></div>
          </div>

          <!-- Budget Breakdown -->
          <div class="tp-card mb-4">
            <div class="tp-card-title mb-3"><i class="fa-solid fa-wallet"></i> Budget Breakdown</div>
            <div class="row g-3" id="budgetCards"><!-- JS --></div>
            <div class="mt-3" id="budgetTable"><!-- JS raw fallback --></div>
          </div>

          <!-- Tips & Safety side by side -->
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <div class="tp-card h-100">
                <div class="tp-card-title"><i class="fa-solid fa-lightbulb"></i> Travel Tips</div>
                <div id="tipsContent" class="tp-alert-block tp-alert-info">
                  <i class="fa-solid fa-spinner fa-spin"></i> Loading…
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="tp-card h-100">
                <div class="tp-card-title"><i class="fa-solid fa-triangle-exclamation"></i> Safety Tips</div>
                <div id="safetyContent" class="tp-alert-block tp-alert-warning">
                  <i class="fa-solid fa-spinner fa-spin"></i> Loading…
                </div>
              </div>
            </div>
          </div>

          <!-- Eco-Friendly Tips -->
          <div class="tp-card mb-4">
            <div class="tp-card-title"><i class="fa-solid fa-leaf"></i> Eco-Friendly Suggestions</div>
            <div id="ecoContent" class="tp-alert-block tp-alert-success"><!-- JS --></div>
          </div>

        </div><!-- /left -->

        <!-- ═══════════════════════════════
             RIGHT COLUMN
             Weather · Map · Checklist
             Attraction cards · Food cards
        ════════════════════════════════ -->
        <div class="col-lg-5 col-xl-4">

          <!-- Weather Card -->
          <div class="tp-card mb-4 d-none" id="weatherSidebar">
            <div class="tp-card-title"><i class="fa-solid fa-cloud-sun"></i> Current Weather</div>
            <div id="weatherDetails"><!-- JS --></div>
          </div>

          <!-- Interactive Map -->
          <div class="tp-card mb-4 p-0 overflow-hidden">
            <div class="tp-card-inner-header"><i class="fa-solid fa-map-location-dot"></i> Interactive Map</div>
            <div id="destinationMap" style="height:280px;"></div>
            <div class="tp-map-controls">
              <button class="tp-map-btn active" id="mapLayerStreet"><i class="fa-solid fa-map"></i> Street</button>
              <button class="tp-map-btn" id="mapLayerSat"><i class="fa-solid fa-globe"></i> Satellite</button>
              <span class="ms-auto tp-muted" style="font-size:11px;"><i class="fa-solid fa-circle-info"></i> OpenStreetMap</span>
            </div>
          </div>

          <!-- Packing Checklist -->
          <div class="tp-card mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <div class="tp-card-title mb-0"><i class="fa-solid fa-list-check"></i> Packing Checklist</div>
              <button class="tp-sm-btn" id="resetChecklistBtn"><i class="fa-solid fa-rotate-left"></i> Reset</button>
            </div>
            <!-- Progress bar -->
            <div class="tp-progress-bar-wrap mb-2">
              <div class="tp-progress-bar" id="checkProgress" style="width:0%"></div>
            </div>
            <div class="tp-progress-label mb-3" id="checkLabel">0 / 0 items packed</div>
            <div id="checklistContent"><!-- JS --></div>
          </div>

          <!-- Top Attractions -->
          <div class="tp-card mb-4">
            <div class="tp-card-title mb-3"><i class="fa-solid fa-landmark"></i> Top Attractions</div>
            <div id="attractionsGrid"><!-- JS --></div>
          </div>

          <!-- Local Food -->
          <div class="tp-card mb-4">
            <div class="tp-card-title mb-3"><i class="fa-solid fa-bowl-food"></i> Local Food Guide</div>
            <div id="foodCards"><!-- JS --></div>
          </div>

          <!-- IBM Granite badge -->
          <div class="tp-card tp-ibm-card text-center">
            <div class="tp-ibm-logo-wrap mb-2">
              <svg width="36" height="36" viewBox="0 0 40 40">
                <rect width="40" height="40" rx="10" fill="#1F70C1" /><text x="20" y="26" text-anchor="middle" fill="white" font-size="14" font-weight="bold" font-family="Arial">IBM</text>
              </svg>
            </div>
            <div class="fw-700 mb-1">IBM watsonx.ai</div>
            <div class="tp-muted mb-2" style="font-size:12px;">Granite Language Model</div>
            <code class="tp-model-code" id="modelBadge2">ibm/granite-3-3-8b-instruct</code>
          </div>

        </div><!-- /right -->
      </div><!-- /row -->
    </div><!-- /container -->
  </main>

  <!-- ── ERROR STATE ──────────────────────────────────────────────────────── -->
  <div class="tp-error-state d-none" id="errorState">
    <div class="tp-error-box text-center">
      <div style="font-size:56px;">❌</div>
      <h3 class="mt-3 fw-700">Something went wrong</h3>
      <p class="tp-muted" id="errorMessage">Unable to generate itinerary.</p>
      <a href="index.php" class="tp-generate-btn mt-4 d-inline-flex align-items-center gap-2">
        <i class="fa-solid fa-arrow-left"></i> Try Again
      </a>
    </div>
  </div>

  <!-- ── EMAIL MODAL ──────────────────────────────────────────────────────── -->
  <div class="modal fade" id="emailModal" tabindex="-1" aria-labelledby="emailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content tp-modal">
        <div class="modal-header border-0 pb-0">
          <h5 class="modal-title fw-700" id="emailModalLabel">
            <i class="fa-solid fa-paper-plane" style="color:var(--tp-accent)"></i> Email Your Itinerary
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="tp-muted mb-3">Receive your full AI itinerary straight in your inbox.</p>
          <label class="tp-label" for="emailInput"><i class="fa-solid fa-at"></i> Email Address</label>
          <input type="email" class="tp-input" id="emailInput" placeholder="you@example.com" />
          <div id="emailStatus" class="d-none mt-3"></div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="tp-action-btn tp-action-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="tp-generate-btn" id="sendEmailBtn" style="padding:10px 24px;font-size:14px;">
            <span class="btn-content"><i class="fa-solid fa-paper-plane"></i> Send Itinerary</span>
            <span class="btn-loading d-none"><span class="tp-spinner"></span> Sending…</span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ── FOOTER ─────────────────────────────────────────────────────────── -->
  <footer class="tp-footer">
    <div class="tp-footer-inner">

      <!-- ── TWO-COLUMN TOP AREA ──────────────────────────────────────────── -->
      <div class="tp-footer-top">

        <!-- Left: logo + description + IBM badge -->
        <div class="tp-footer-left">
          <div class="tp-footer-brand">
            <div class="tp-footer-brand-icon"><i class="fa-solid fa-map-location-dot"></i></div>
            <span class="tp-footer-brand-name"><span>Tour</span>Verse</span>
          </div>
          <p class="tp-footer-tagline">
            AI-powered travel itineraries generated using IBM watsonx.ai Granite
          </p>
          <span class="tp-ibm-pill tp-ibm-sm">
            <i class="fa-solid fa-microchip"></i> IBM watsonx.ai Granite
          </span>
        </div>

        <!-- Right: developer info + social icons -->
        <div class="tp-footer-right">
          <div class="tp-footer-dev-label">Developer</div>
          <div class="tp-footer-dev-name">Harsh Rakeshkumar Champaneri</div>
          <div class="tp-footer-dev-title">Full-Stack Developer &nbsp;|&nbsp; AI Enthusiast</div>
          <div class="tp-footer-social">
            <a href="https://www.linkedin.com/in/harsh-rakeshkumar-champaneri-a04523315?utm_source=share&utm_campaign=share_via&utm_content=profile&utm_medium=android_app"
              target="_blank" rel="noopener" class="tp-footer-social-link linkedin" title="LinkedIn">
              <i class="fa-brands fa-linkedin-in"></i>
            </a>
            <a href="https://github.com/harsh-champaneri"
              target="_blank" rel="noopener" class="tp-footer-social-link github" title="GitHub">
              <i class="fa-brands fa-github"></i>
            </a>
            <a href="mailto:champharsh.2005@gmail.com"
              class="tp-footer-social-link email" title="Email">
              <i class="fa-solid fa-envelope"></i>
            </a>
          </div>
        </div>

      </div><!-- /tp-footer-top -->

      <!-- ── BOTTOM BAR ──────────────────────────────────────────────────── -->
      <div class="tp-footer-bottom">
        <span class="tp-footer-copy">&copy; <?= date('Y') ?> TourVerse</span>
        <span class="tp-footer-dot" aria-hidden="true">&bull;</span>
        <span class="tp-footer-powered">Powered by IBM watsonx.ai Granite</span>
        <span class="tp-footer-dot" aria-hidden="true">&bull;</span>
        <span class="tp-footer-credit">Developed by Harsh Rakeshkumar Champaneri</span>
      </div>

    </div>
  </footer>

  <!-- Bootstrap 5 JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <!-- Leaflet.js -->
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <!-- App JS -->
  <script src="assets/js/app.js"></script>
  <!-- Map JS -->
  <script src="assets/js/map.js"></script>

</body>

</html>