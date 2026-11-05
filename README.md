# Proofsheet: document extraction prototype

Upload a financial document, such as a bank statement, a GST invoice or an ITR. An LLM reads it and records the fields, tables and text exactly as printed. A rule engine then checks the numbers and flags anything a reviewer should look at. You review the result on a dashboard and download it as an Excel workbook.

It runs on free tiers: **Groq** for the LLM, **Supabase** for login, storage and the database, **Railway** for the API and **Vercel** for the web app. Anthropic Claude is supported as an optional, paid alternative.

---

## Contents

1. [Overview](#1-overview)
2. [How it works](#2-how-it-works)
3. [Project structure](#3-project-structure)
4. [Run it locally](#4-run-it-locally)
5. [Using the app](#5-using-the-app)
6. [Configuration](#6-configuration)
7. [Deploy](#7-deploy)
8. [Tests](#8-tests)
9. [API reference](#9-api-reference)
10. [Limits and troubleshooting](#10-limits-and-troubleshooting)

---

## 1. Overview

**What it does**
- **Reads any document** (PDF, PNG, JPG or WEBP, up to 20 MB). It works out the document type itself and picks the relevant fields, such as account number, IFSC, GSTIN, statement period or invoice total.
- **Copies, never computes.** Values are recorded exactly as printed, with Indian digit grouping and Dr/Cr markers kept. Anything that isn't printed is shown as **Not available** rather than guessed.
- **Rebuilds every table** row by row, including tables that continue across pages.
- **Verifies the arithmetic** with fixed rules, not AI:
  - Bank statements: each row's running balance and the printed totals.
  - Invoices: taxable value plus tax equals the grand total.
  - Identifiers: GSTIN, IFSC and PAN must fit their official formats.
- **Gives a reviewer the full picture:** a dashboard with fields, review notes and a balance chart, plus the full tables, cleaned-up text and the original file side by side.
- **Exports to Excel** with sheets for Summary, Fields, each table (numbers stored as real numbers), colour-coded Flags, and Text.

**Who it's for:** credit and operations teams who need structured data out of borrower documents, with a clear trail of what was read and what needs a human check.

**Architecture**

```
Browser (Next.js, Vercel)
   │  sign in with Supabase Auth (email + password, single user)
   │  Authorization: Bearer <access token>
   ▼
PHP API (Railway) ──► Supabase Storage    original files (private bucket)
   │               ──► Supabase Postgres   documents table, JSON results
   └─────────────────► Groq API (default, free)  or  Anthropic Claude API
                       reads the document, returns schema-checked JSON
```

---

## 2. How it works

Every upload goes through three stages.

### Stage 1: LLM read ([`backend/src/Extractor.php`](backend/src/Extractor.php))

The model is told to extract only what is printed. Its answer is forced into one JSON schema with `fields`, `tables`, `sections` (clean Markdown) and `flags`, so the output is always machine-readable.

`LLM_PROVIDER` picks the provider:

| | **Groq** (default, free) | **Anthropic** (optional, paid) |
|---|---|---|
| Code | [`GroqExtractor.php`](backend/src/GroqExtractor.php) + [`PdfTools.php`](backend/src/PdfTools.php) | `Extractor.php` |
| Digital PDFs | poppler pulls out the printed text, and `openai/gpt-oss-120b` reads it. Copying from the text layer is exact. | The whole PDF is sent to Claude |
| Scanned PDFs and photos | Pages are rendered as images and read by the vision model `qwen/qwen3.8-27b` | Sent as-is |
| Large documents | Read a page or two at a time to stay under the free tier's ~8K tokens per minute. Continuing tables are joined back together, and rate-limit replies are waited out automatically. | One request |

### Stage 2: rule checks ([`backend/src/Validator.php`](backend/src/Validator.php))

These are deterministic and use no AI. They only *verify* printed figures: a computed number is never written in as an extracted value.

- **Fields:** values that aren't printed, low-confidence readings, values that don't parse as numbers or dates, and malformed GSTIN, IFSC or PAN values (scans often add or drop a character).
- **Tables:** rows that don't match the column count.
- **Bank statements:** previous balance + credit − debit must equal the printed balance on every row. Printed totals must match the column sums.
- **Invoices:** taxable value plus printed IGST, CGST, SGST and cess must equal the printed grand total.
- **Truncation:** if the model's answer was cut off, a critical flag is raised.

Every flag has a severity: **critical** (wrong or missing data), **warning** (check it) or **info** (for the record).

### Stage 3: Excel ([`backend/src/ExcelExporter.php`](backend/src/ExcelExporter.php))

The workbook is built without any third-party library.

---

## 3. Project structure

```
doc-extractor/
├── backend/                 Plain PHP 8.3 API, no framework, no third-party packages
│   ├── public/index.php     Entry point and router
│   ├── src/
│   │   ├── Api.php          Routes: upload, process, list, show, Excel, delete
│   │   ├── Extractor.php    Prompt, JSON schema, provider switch, Anthropic call
│   │   ├── GroqExtractor.php  Groq calls, page batching, merging, retries
│   │   ├── PdfTools.php     poppler wrapper: page count, page text, page images
│   │   ├── Validator.php    Rule checks and flags
│   │   ├── ExcelExporter.php / XlsxWriter.php   .xlsx output
│   │   ├── Supabase.php     Auth check, Postgres (PostgREST) and Storage calls
│   │   └── Env.php, HttpClient.php, HttpException.php
│   ├── tests/
│   │   ├── run.php          Offline test suite
│   │   ├── mock_supabase.php  Local stand-in for Supabase (auth, DB, storage)
│   │   └── fixtures/        Sample extraction used by tests and MOCK_EXTRACTION
│   ├── Dockerfile, railway.json, .env.example
├── frontend/                Next.js 16 + React 19 app
│   ├── app/page.tsx         Upload and document list
│   ├── app/users/           Users page (admins add, reset and remove logins)
│   ├── app/documents/[id]/  Document view: Overview, Tables, Text, Original
│   ├── components/          Sign-in page (AuthGate), field sheet, flag rail, balance chart, tables, text
│   └── lib/                 API client, Supabase client, demo data
├── supabase/schema.sql      Table, row-level security, private storage bucket
└── samples/                 Synthetic test documents and the script that makes them
```

---

## 4. Run it locally

**You need:**
- PHP 8.2+ with curl, zip and mbstring
- Composer
- poppler (`brew install poppler`, or `apt install poppler-utils`)
- Node 20+
- A Supabase project set up as in [7.1 Supabase](#71-supabase)
- A free Groq API key from [console.groq.com](https://console.groq.com) → API Keys

### 1. Configure (once)

Create the backend settings file and install dependencies:

```bash
cd backend && cp .env.example .env && composer dump-autoload
```

```bash
cd frontend && cp .env.example .env.local && npm install
```

Then fill in the keys.

`backend/.env`:

```
SUPABASE_URL=https://<project-ref>.supabase.co
SUPABASE_SECRET_KEY=sb_secret_...
GROQ_API_KEY=gsk_...
```

`frontend/.env.local`:

```
NEXT_PUBLIC_SUPABASE_URL=https://<project-ref>.supabase.co
NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY=sb_publishable_...
NEXT_PUBLIC_API_URL=http://localhost:8080
```

### 2. Start the API and the web app

Run each command in its own terminal tab, from the project root.

API:

```bash
cd backend && PHP_CLI_SERVER_WORKERS=4 php -d upload_max_filesize=25M -d post_max_size=26M -d max_execution_time=400 -S localhost:8080 public/index.php
```

Web app:

```bash
cd frontend && npm run dev
```

### 3. Open the app

Go to http://localhost:3000 and sign in with the user you created in Supabase. Check that the API is up at http://localhost:8080/health.

**Other ways to run it**
- **Screens only:** `NEXT_PUBLIC_DEMO_MODE=true` in `frontend/.env.local` runs the web app on built-in sample data, with no login and no backend.
- **Without spending Groq quota:** `MOCK_EXTRACTION=true` in `backend/.env` makes the API return a canned bank-statement extraction instead of calling the LLM. Supabase is still used.

---

## 5. Using the app

### Demo login

| | |
|---|---|
| **Email** | `koyna@test.com` |
| **Password** | `test123` |

This is a test account in the project's Supabase, and it's an admin (listed in `ADMIN_EMAILS`).

### Creating an account

On the sign-in page, choose **Create an account**. Enter your email and a password of at least 8 characters (a strength meter helps), and confirm it.

- **By default** you're signed in straight away.
- **With `SIGNUP_REQUIRES_APPROVAL=true`**, new accounts wait for an admin instead. Until then, signing in shows **Waiting for approval**, and nothing can be seen or uploaded.
- `SIGNUP_ENABLED=false` removes the sign-up option entirely.

Email sign-up goes through the backend (`POST /api/signup`), which limits it to 5 attempts per hour per IP.

### Continue with Google

The sign-in and create-account screens show **Continue with Google** when `NEXT_PUBLIC_GOOGLE_AUTH=true`. The first time someone uses it, their account is created and they're signed in (or held for approval if `SIGNUP_REQUIRES_APPROVAL=true`). If the Google email matches an existing account, Supabase links the two.

To set it up:
1. **Google Cloud Console → APIs & Services → Credentials → your OAuth client (Web application):**
   - **Authorized JavaScript origins:**
     - `http://localhost:3000`
     - your Vercel URL
   - **Authorized redirect URIs:** `https://<project-ref>.supabase.co/auth/v1/callback`
2. **Supabase → Authentication → Sign In / Providers → Google:**
   - turn it on
   - paste the **Client ID** and **Client Secret**
   - save
3. **Supabase → Authentication → Sign In / Providers:** turn **Allow new users to sign up** on. First-time Google users need this.
4. **Supabase → Authentication → URL Configuration:** add these to **Redirect URLs**:
   - `http://localhost:3000/**`
   - `https://your-app.vercel.app/**`

The client secret lives only in Supabase. It is never in this repo or in the frontend.



On the sign-in page, choose **Forgot password?** and enter your email. Supabase emails you a one-time link that's valid for an hour. The link opens the app on **Set a new password**, and you stay signed in afterwards. Expired or used links show a clear message with the option to send a new one. An admin can also reset any password from the **Users** page.

For reset emails to work, you need two settings in Supabase:
1. **Authentication → URL Configuration:**
   - set **Site URL** to the app's URL (e.g. `https://your-app.vercel.app`)
   - add these to **Redirect URLs**:
     - `http://localhost:3000/**`
     - `https://your-app.vercel.app/**`
2. **Email delivery:** Supabase's built-in email sender only delivers to members of your Supabase organisation and is heavily rate-limited. Before real users can reset their password, add your own email service under **Project Settings → Authentication → SMTP Settings**. Resend, Brevo and similar services have free tiers.

### Users page (admins)

Admins see a **Users** tab in the top bar. On it they can:
- **Approve or decline** people who signed up, when approval is turned on. These appear at the top under **Waiting for approval**. Declining removes the account.
- **Add a user:** enter an email and a password, or click **Generate** for a readable random one. The person can sign in straight away. Copy the details from the box that appears, since the password isn't shown again.
- **Reset password:** set a new password for anyone who forgot theirs.
- **Remove:** delete a login, together with that person's documents and files. You can't remove yourself.

To make someone an admin, add their email to `ADMIN_EMAILS` on the backend. You can also still manage users in the Supabase dashboard, under **Authentication → Users**.

> Anyone who can read this README can sign in with this account. Keep the repository private, and change or delete the account before real documents go in.


1. **Sign in**, or **Create an account** (see above). If you forgot your password, use **Forgot password?**.
2. **Upload.** Drop a PDF or image on **Drop a document here**, or use **Choose file**. You can try the files in `samples/`.
3. **Wait for extraction.** It runs right after upload. A one-page document takes about 5–10 seconds on Groq. Long documents take longer on the free tier, roughly a minute per page or two.
4. **Review the document:**
   - **Overview:** the detected fields (with **Not available** for anything not printed), **Review notes** with a severity filter, and for bank statements a **Balance through the period** chart.
   - **Tables:** every table, row by row, with a row filter.
   - **Text:** the document rewritten as clean, readable text.
   - **Original:** the uploaded file, for checking side by side.
5. **Act on it:**
   - **Download Excel** saves the workbook.
   - **Read again** re-runs extraction, for example after changing the model.
   - **Delete** removes the file and its data.

**Reading the notes:** start with the **critical** ones, such as a balance that doesn't reconcile, a malformed GSTIN, or pages the model couldn't read. Check each against the **Original** tab. Info notes like "Running balance verified" confirm that the check passed.

---

## 6. Configuration

### Backend (`backend/.env`, or Railway variables)

| Variable | Default | What it does |
|---|---|---|
| `SUPABASE_URL` | none | Supabase project URL |
| `SUPABASE_SECRET_KEY` | none | Secret key (`sb_secret_…`). Server only, never in the frontend. |
| `SUPABASE_BUCKET` | `documents` | Storage bucket for originals |
| `LLM_PROVIDER` | `groq` | `groq` or `anthropic` |
| `GROQ_API_KEY` | none | From console.groq.com |
| `GROQ_TEXT_MODEL` | `openai/gpt-oss-120b` | Model for digital PDF text |
| `GROQ_VISION_MODEL` | `qwen/qwen3.8-27b` | Model for images and scanned pages |
| `GROQ_TEXT_REASONING_EFFORT` / `GROQ_VISION_REASONING_EFFORT` | `low` / `none` | How much the model "thinks" before answering. Lower uses fewer tokens. |
| `GROQ_MAX_TOKENS` | `4500` | Answer size per request |
| `GROQ_TEXT_CHUNK_TOKENS` | `1800` | How much PDF text goes in one request |
| `GROQ_IMAGES_PER_REQUEST` | `1` | Scanned pages per request (max 3) |
| `GROQ_IMAGE_DPI` | `150` | Resolution used to render scanned pages |
| `GROQ_MAX_PAGES` | `30` | Rejects longer PDFs |
| `ANTHROPIC_API_KEY` / `ANTHROPIC_MODEL` / `LLM_MAX_TOKENS` | none / `claude-sonnet-5-5` / `16000` | Only used when `LLM_PROVIDER=anthropic` |
| `LLM_TIMEOUT_SECONDS` | `300` | Timeout per LLM request |
| `MOCK_EXTRACTION` | `false` | `true` returns a canned extraction with no LLM call |
| `ALLOWED_ORIGINS` | none | Comma-separated web origins allowed to call the API |
| `ALLOWED_ORIGIN_REGEX` | none | Optional, for example to allow Vercel preview URLs |
| `SIGNUP_ENABLED` | `true` | Show **Create an account** on the sign-in page |
| `SIGNUP_REQUIRES_APPROVAL` | `false` | `true` makes new sign-ups wait for an admin to approve them |
| `ADMIN_EMAILS` | none | Comma-separated emails that can use the **Users** page to add, reset and remove logins |
| `ALLOWED_EMAILS` | none | Optional: only these users may use the API (admins always can) |

The Groq defaults are sized for the free tier. On a paid Groq plan, raise them, for example:
- `GROQ_MAX_TOKENS=16000`
- `GROQ_TEXT_CHUNK_TOKENS=12000`
- `GROQ_IMAGES_PER_REQUEST=3`

### Frontend (`frontend/.env.local`, or Vercel variables)

| Variable | What it does |
|---|---|
| `NEXT_PUBLIC_SUPABASE_URL` | Supabase project URL |
| `NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY` | Publishable key (`sb_publishable_…`). Safe in the browser. |
| `NEXT_PUBLIC_API_URL` | Backend URL, no trailing slash |
| `NEXT_PUBLIC_GOOGLE_AUTH` | `true` shows **Continue with Google** (set up the provider in Supabase first) |
| `NEXT_PUBLIC_DEMO_MODE` | `true` runs on built-in sample data, with no login and no backend |

`.env` and `.env.local` are git-ignored. Never commit keys.

---

## 7. Deploy

### 7.1 Supabase

1. Create a project at supabase.com.
2. In **SQL Editor**, paste and run [`supabase/schema.sql`](supabase/schema.sql). This creates the `documents` table, row-level security and a private `documents` bucket.
3. In **Authentication → Users → Add user**, create your one user with an email and password, and tick **Auto confirm**.
4. In **Authentication → Sign In / Providers**, turn off **Allow new users to sign up**, so nobody else can register.
5. From **Project Settings → API Keys**, copy three things:
   - the Project URL
   - the **publishable** key (`sb_publishable_…`), for the frontend
   - the **secret** key (`sb_secret_…`), for the backend only

### 7.2 Backend on Railway

1. Push this repo to GitHub.
2. In Railway, choose **New Project → Deploy from GitHub repo**, and set **Root directory** to `backend`. Railway picks up the `Dockerfile` (which installs poppler) and `railway.json` automatically.
3. Add these variables: `SUPABASE_URL`, `SUPABASE_SECRET_KEY`, `GROQ_API_KEY`, `ALLOWED_ORIGINS` (your Vercel URL), `ADMIN_EMAILS`, and optionally `ALLOWED_EMAILS`. See [Configuration](#6-configuration).
4. Under **Settings → Networking**, choose **Generate domain**. Then open `https://<domain>/health`, which should return `{"ok":true,...}`.

### 7.3 Frontend on Vercel

1. In Vercel, choose **Add New → Project**, pick the repo and set **Root directory** to `frontend`.
2. Add `NEXT_PUBLIC_SUPABASE_URL`, `NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY` and `NEXT_PUBLIC_API_URL` (your Railway URL).
3. Deploy, then add the Vercel URL to `ALLOWED_ORIGINS` on Railway.

---

## 8. Tests

This runs offline and needs no keys. It covers number parsing, every rule check, the identifier formats, Groq schema conversion, retry timing, merging across pages, and the Excel export.

```bash
cd backend && php tests/run.php
```

To test the full API flow without a Supabase project or the LLM, start the bundled fake Supabase ([`backend/tests/mock_supabase.php`](backend/tests/mock_supabase.php)) and the API in separate tabs:

```bash
cd backend && php -S 127.0.0.1:54321 tests/mock_supabase.php
```

```bash
cd backend && SUPABASE_URL=http://127.0.0.1:54321 SUPABASE_SECRET_KEY=x MOCK_EXTRACTION=true php -S 127.0.0.1:8080 public/index.php
```

Then upload a sample, from the project root:

```bash
curl -H "Authorization: Bearer test-user-token" -F file=@samples/sample_bank_statement.pdf http://127.0.0.1:8080/api/documents
```

**Test documents**
- **`samples/`** has three synthetic files:
  - a bank statement (27 transactions, Indian number format, IFSC and MICR)
  - a GST tax invoice with IGST
  - a skewed, noisy "scanned" photo of the same invoice

  Regenerate them with `python samples/generate_samples.py`.
- **More bank statements:** the [AgamiAI Indian Bank Statements dataset](https://huggingface.co/datasets/AgamiAI/Indian-Bank-Statements) is synthetic and Apache-2.0 licensed, and comes with JSON ground truth. Use it to measure accuracy.
- **Invoices:** the [parsee-ai invoices example dataset](https://huggingface.co/datasets/parsee-ai/invoices-example).

---

## 9. API reference

All `/api/*` routes need `Authorization: Bearer <Supabase access token>`.

| Method | Path | What it does |
|---|---|---|
| GET | `/health` | Liveness check |
| GET | `/api/documents` | List your documents |
| POST | `/api/documents` | Upload (`multipart/form-data`, field `file`; PDF, PNG, JPG or WEBP up to 20 MB) |
| POST | `/api/documents/{id}/process` | Run extraction. It's synchronous: about 5–10 s per page on Groq, longer for big files on the free tier. |
| GET | `/api/documents/{id}` | Full result and a signed link to the original |
| GET | `/api/documents/{id}/excel` | Download the .xlsx |
| DELETE | `/api/documents/{id}` | Delete the file and its data |
| GET | `/api/auth-config` | Public: whether sign-up is on and needs approval |
| POST | `/api/signup` | Public: create an account (`{"email", "password"}`, password 8+ characters). Returns `{"status": "pending"}` or `{"status": "active"}` |
| GET | `/api/me` | Your user id, email, and whether you're an admin and approved |
| GET | `/api/users` | Admin only: list logins |
| POST | `/api/users` | Admin only: add a login (`{"email", "password"}`, password 6+ characters) |
| PATCH | `/api/users/{id}` | Admin only: set a new password (`{"password"}`) and/or approve (`{"approved": true}`) |
| DELETE | `/api/users/{id}` | Admin only: remove a login with its documents and files |

---

## 10. Limits and troubleshooting

**Known limits (this is a prototype)**
- **Free-tier speed:** Groq's free tier allows about 8K tokens per minute and 1,000 requests a day per model. Long documents are read in small batches with waits in between.
- **Extraction runs inside the HTTP request.** That's fine for a few documents a day. For volume, move it to a queue worker.
- **Scans are less reliable than digital PDFs.** Characters can be misread on scans. The rule checks catch many of these, such as a broken GSTIN or IFSC format or a balance that doesn't reconcile, but always check critical notes against the original.
- **Very long statements** can hit the output limit. The affected pages are flagged as critical, never silently dropped.
- **English only.**

**Troubleshooting**

| Symptom | Fix |
|---|---|
| `Server misconfigured: GROQ_API_KEY is not set` | Add the key to `backend/.env`, or to Railway variables |
| `pdftotext (poppler-utils) is not installed` | Run `brew install poppler` (Mac) or `apt install poppler-utils`. The Docker image already includes it. |
| `Groq rate limit reached` | You've used the free daily quota. Wait, or use a paid Groq plan. |
| `request is larger than your plan allows per minute` | Lower `GROQ_MAX_TOKENS` or `GROQ_TEXT_CHUNK_TOKENS` |
| A Groq model is no longer available | Groq retires models often. Check [console.groq.com/docs/models](https://console.groq.com/docs/models) and set `GROQ_TEXT_MODEL` or `GROQ_VISION_MODEL`. |
| The browser shows CORS errors | Add the web app's URL to `ALLOWED_ORIGINS` |
| "Setup needed" on the sign-in page | Set `NEXT_PUBLIC_SUPABASE_URL` and `NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY`, or use `NEXT_PUBLIC_DEMO_MODE=true` |
