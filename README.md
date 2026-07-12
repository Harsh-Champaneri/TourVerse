# ✈️ TourVerse — AI Travel Planner

> A fully-featured, AI-powered travel planner web application built with **PHP 8** and **IBM watsonx.ai Granite** (`ibm/granite-3-3-8b-instruct`). Generate personalised day-wise itineraries, hotel recommendations, budget breakdowns, weather forecasts, interactive maps, PDF exports, and email delivery — **no login, no database required**.

---

## 📸 Features

| Feature | Details |
|---|---|
| 🤖 **AI Itinerary Generator** | IBM watsonx.ai Granite (`ibm/granite-3-3-8b-instruct`) |
| 📅 **Day-wise Planning** | Morning / Afternoon / Evening accordion breakdown |
| 🏨 **Hotel Recommendations** | 3 AI-curated hotels with price, location, map links |
| 🌍 **Interactive Map** | Leaflet.js + OpenStreetMap — street & satellite layers |
| ⛅ **Live Weather** | OpenWeatherMap API (mock fallback when key absent) |
| 💰 **Budget Analysis** | Budget / Mid-range / Luxury — 6-category cost cards |
| 📄 **PDF Export** | Dompdf 3.x — professional B&W report (DejaVu Sans, ₹ support) |
| 📧 **Email Itinerary** | PHPMailer — sends PDF attachment via SMTP (no temp file) |
| 🎒 **Packing Checklist** | Interactive AI-generated checklist with progress bar |
| 🇮🇳 **Indian Travel Focus** | Train routes, vegetarian options, festivals, visa tips |
| 🌿 **Eco-friendly** | Sustainable travel recommendations |
| 🌙 **Dark / Light Mode** | Toggle with `localStorage` persistence |
| 📱 **Mobile Responsive** | Bootstrap 5 two-column dashboard |

---

## 🗂️ Project Structure

```
travel-planner/
│
├── index.php                    # Multi-step planner form (4 steps)
├── itinerary.php                # Two-column AI itinerary dashboard
├── .env                         # Your API keys — gitignored
├── .env.example                 # Template — copy to .env and fill in keys
├── composer.json                # PHP dependencies (Dompdf 3.x, PHPMailer 7.x)
│
├── api/
│   ├── generate.php             # POST endpoint — watsonx.ai Granite generation
│   ├── weather.php              # GET  endpoint — OpenWeather API + mock fallback
│   ├── generate-pdf.php         # POST endpoint — thin controller, streams PDF
│   └── send-email.php           # POST endpoint — builds PDF in memory, emails it
│
├── includes/
│   ├── config.php               # .env loader, constants, AGENT_INSTRUCTIONS, helpers
│   ├── watsonx.php              # WatsonxAI class — IAM token, generate(), buildTravelPrompt()
│   └── pdf-builder.php          # All PDF logic — parseSections(), buildPdfHtml(), generateItineraryPdfBinary()
│
├── assets/
│   ├── css/
│   │   └── style.css            # Glassmorphism, dark/light mode, Bootstrap overrides
│   └── js/
│       ├── app.js               # Form stepper, Markdown parser, all UI renderers
│       └── map.js               # Leaflet.js + Nominatim geocoding
│
├── vendor/                      # Composer dependencies (created by composer install)
└── README.md                    # This file
```

---

## 🔑 Required API Keys

You need **2 required** + **2 optional** credentials:

### 1. IBM watsonx.ai API Key *(Required)*
1. Login to [cloud.ibm.com](https://cloud.ibm.com)
2. Go to **Manage → Access (IAM) → API Keys**
3. Click **Create an IBM Cloud API key**
4. Copy the key — you'll only see it once!

### 2. IBM watsonx.ai Project ID *(Required)*
1. Go to [dataplatform.cloud.ibm.com](https://dataplatform.cloud.ibm.com)
2. Create or open a **watsonx.ai project**
3. Go to **Manage → General** tab
4. Copy the **Project ID**

### 3. OpenWeather API Key *(Optional)*
1. Register free at [openweathermap.org](https://openweathermap.org/api)
2. Go to **API Keys** in your account and copy the default key
3. Note: New keys take ~2 hours to activate. Free tier = 1,000 calls/day

### 4. SMTP Credentials *(Optional — for email itinerary)*
- For Gmail: create an [App Password](https://myaccount.google.com/apppasswords) (enable 2FA first)
- For other providers: use standard SMTP host / port / username / password

---

## ⚡ Quick Setup (XAMPP)

### Step 1 — Place files
```
C:\xampp\htdocs\IBM\travel-planner\
```

### Step 2 — Copy environment file
```bash
# Windows
copy .env.example .env

# Linux / macOS
cp .env.example .env
```

### Step 3 — Edit `.env`
```env
WATSONX_API_KEY=your_actual_ibm_api_key
WATSONX_PROJECT_ID=your_actual_project_id
OPENWEATHER_API_KEY=your_openweather_key   # optional
SMTP_USERNAME=your_email@gmail.com         # optional
SMTP_PASSWORD=your_gmail_app_password      # optional
APP_URL=http://localhost/IBM/travel-planner
```

### Step 4 — Install PHP dependencies
Requires [Composer](https://getcomposer.org/):
```bash
cd C:\xampp\htdocs\IBM\travel-planner
composer install
```
This installs **Dompdf 3.x** (PDF) and **PHPMailer 7.x** (email).

### Step 5 — Start XAMPP
1. Open XAMPP Control Panel
2. Start **Apache** (MySQL is NOT required)
3. Visit → `http://localhost/IBM/travel-planner/`

---

## 🚀 Deployment on InfinityFree

### Step 1 — Create account
Sign up at [infinityfree.com](https://infinityfree.com) and create a hosting account.

### Step 2 — Upload files
Via FileZilla FTP or the InfinityFree File Manager.  
Upload everything from `travel-planner/` to the `htdocs/` folder on the server.

### Step 3 — Configure environment
InfinityFree doesn't always load `.env` via the web root. Use `.htaccess` instead:
```apache
SetEnv WATSONX_API_KEY    your_api_key_here
SetEnv WATSONX_PROJECT_ID your_project_id_here
SetEnv OPENWEATHER_API_KEY your_weather_key_here
```
Or temporarily hardcode values in `includes/config.php` (uncomment the `define()` lines).

### Step 4 — PHP version
Set PHP 8.1+ in your InfinityFree control panel:
- **Softaculous → PHP Configuration → PHP 8.1**

### Step 5 — Upload vendor/
Composer is not available on shared hosting. Upload the entire `vendor/` folder from your local machine via FTP.

---

## 🎛️ Customising the AI Agent

All AI behaviour is controlled by `AGENT_INSTRUCTIONS` in [`includes/config.php`](includes/config.php).

| Section | What it controls |
|---|---|
| `RESPONSE TONE & STYLE` | Friendly, formal, concise |
| `ITINERARY DETAIL LEVEL` | Day segments, activity brevity |
| `BUDGET OPTIMISATION` | Cost-saving per tier (Budget / Mid-range / Luxury) |
| `TRAVEL PREFERENCES` | Solo / Couple / Family / Group / Business tailoring |
| `SAFETY & GUIDELINES` | Destination-specific safety, emergency numbers |
| `FAMILY-FRIENDLY` | Kid activities, rest breaks, no nightlife |
| `ECO-FRIENDLY` | Sustainable stays, public transport, responsible tourism |
| `INDIAN TRAVEL PREFERENCES` | Trains, vegetarian food, festivals, visa tips |
| `HOTEL RECOMMENDATIONS` | 3 hotels — rules for accuracy, pricing, map links |
| `OUTPUT STRUCTURE` | Exact section order and format |
| `STRICT RULES` | Word limits, no hallucination, ethical safeguards |

---

## 🔧 Configuration Reference

| Variable | Default | Description |
|---|---|---|
| `WATSONX_API_KEY` | — | IBM Cloud API Key (required) |
| `WATSONX_PROJECT_ID` | — | watsonx.ai Project ID (required) |
| `WATSONX_URL` | `us-south.ml.cloud.ibm.com` | Regional endpoint |
| `WATSONX_MODEL_ID` | `ibm/granite-3-3-8b-instruct` | Granite model |
| `WATSONX_MAX_TOKENS` | `2048` | Max response tokens |
| `WATSONX_TEMPERATURE` | `0.7` | Creativity (0=deterministic, 1=max creative) |
| `WATSONX_TOP_P` | `0.9` | Nucleus sampling |
| `WATSONX_TOP_K` | `50` | Top-K sampling |
| `WATSONX_REPETITION_PENALTY` | `1.1` | Penalises repeated phrases |
| `OPENWEATHER_API_KEY` | — | OpenWeather key (optional) |
| `SMTP_HOST` | `smtp.gmail.com` | SMTP server |
| `SMTP_PORT` | `587` | 587 = TLS, 465 = SSL |
| `APP_DEBUG` | `true` | Set `false` in production |

---

## 📦 Available Granite Models

Change `WATSONX_MODEL_ID` in `.env` to switch models:

| Model ID | Context | Best For |
|---|---|---|
| `ibm/granite-3-3-8b-instruct` | 128K | **Default** — fast & capable |
| `ibm/granite-3-8b-instruct` | 4K | Fast responses |
| `ibm/granite-3-2b-instruct` | 4K | Fastest, lightweight |
| `ibm/granite-13b-chat-v2` | 4K | Conversational quality |
| `ibm/granite-20b-multilingual` | 4K | Multi-language support |

---

## 🛠️ Troubleshooting

### "IBM IAM token error"
- Check `WATSONX_API_KEY` in `.env` — no extra spaces or quotes
- Ensure your IBM Cloud account is active
- Verify the API key has **ML Developer** role on the watsonx.ai project

### "watsonx.ai returned empty response"
- Check `WATSONX_PROJECT_ID` is correct
- Try a different `WATSONX_MODEL_ID`
- Reduce `WATSONX_MAX_TOKENS` to `1500` in `config.php`

### Weather shows "Demo data"
- Add `OPENWEATHER_API_KEY` in `.env`
- Free keys take ~2 hours to activate

### PDF download triggers browser print dialog
- This is the HTML fallback when Dompdf is not installed
- Run `composer install` to install Dompdf 3.x

### Email sending fails
- Gmail: enable 2FA and use an [App Password](https://myaccount.google.com/apppasswords)
- Check `SMTP_PORT`: use `465` for SSL, `587` for TLS
- Some hosts block outbound SMTP on port 587 — try 465 with `SMTP_SECURE=ssl`

### Map doesn't load
- Check browser console for Leaflet errors
- Ad blockers may block OpenStreetMap tile requests — whitelist your domain

### "cURL error" on InfinityFree
- Ensure `WATSONX_URL` starts with `https://`
- Add to `.htaccess`: `SetEnv CURLOPT_SSL_VERIFYPEER 0`

---

## 📋 Tech Stack

| Layer | Technology |
|---|---|
| **Backend** | PHP 8.0+, cURL |
| **AI** | IBM watsonx.ai REST API + Granite LLM |
| **Frontend** | Bootstrap 5, Vanilla JS (ES2020) |
| **Markdown Rendering** | Marked.js |
| **Maps** | Leaflet.js + OpenStreetMap + Nominatim geocoding |
| **Weather** | OpenWeatherMap API v2.5 |
| **PDF** | Dompdf 3.x (DejaVu Sans, ₹ Unicode support) |
| **Email** | PHPMailer 7.x + SMTP (in-memory PDF attachment) |
| **Fonts** | Inter (Google Fonts) |

---

## 🔒 Security Notes

- Never commit `.env` to version control → add to `.gitignore`
- Set `APP_DEBUG=false` in production
- No server-side state — itinerary lives in browser `sessionStorage` only
- All user input sanitized via `htmlspecialchars()` before use
- API keys loaded from env vars, never hardcoded

```bash
# .gitignore
.env
vendor/
```

---

## 📄 License

MIT License — free for personal and commercial use.

---

## 🙏 Credits

- **IBM watsonx.ai** — AI backbone
- **IBM Granite** — Language model (`ibm/granite-3-3-8b-instruct`)
- **OpenStreetMap & Nominatim** — Free maps & geocoding
- **OpenWeatherMap** — Weather data
- **Leaflet.js** — Interactive map library
- **Bootstrap 5** — UI framework
- **Dompdf** — PDF generation library
- **PHPMailer** — Email library

---

<div align="center">
  <strong>TourVerse — Built with IBM watsonx.ai Granite</strong><br>
  <em>Developed by Harsh Rakeshkumar Champaneri</em><br>
  <a href="https://www.linkedin.com/in/harsh-rakeshkumar-champaneri-a04523315">LinkedIn</a> ·
  <a href="https://github.com/harsh-champaneri">GitHub</a> ·
  <a href="mailto:champharsh.2005@gmail.com">Email</a><br><br>
  <em>Safe travels! 🌏</em>
</div>
