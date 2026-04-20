# RAG Business Chatbot — Developer Setup Guide

This is the internal setup guide for deploying the middleware and releasing the plugin.
It is NOT for end buyers.

---

## Architecture recap

```
WordPress site  ←→  plugin/   (buyers install this)
                         ↕ HTTPS + shared secret
                    middleware/  (you deploy to Railway)
                         ↕
                    Cohere API  (embeddings + chat)
                         ↕
                    Neon DB  (pgvector storage)
```

---

## Step 1 — Set up Neon (vector database)

1. Create a free account at [neon.tech](https://neon.tech).
2. Create a new project (region closest to your Railway deployment).
3. Open the **SQL Editor** and run the contents of `middleware/schema.sql`.
4. Copy the **Connection string** from the dashboard — it looks like:
   ```
   postgresql://user:password@ep-xxx-xxx.us-east-2.aws.neon.tech/neondb?sslmode=require
   ```
5. Save this as `NEON_CONNECTION_STRING` for Step 3.

---

## Step 2 — Get a Cohere production API key

1. Sign up at [cohere.com](https://cohere.com).
2. Go to **API Keys** → create a **Production** key (not trial — trial has rate limits).
3. Copy the key. Save as `COHERE_API_KEY` for Step 3.

---

## Step 3 — Deploy middleware to Railway

1. Install the Railway CLI: `npm install -g @railway/cli`
2. Login: `railway login`
3. From the repo root:
   ```bash
   cd middleware
   railway init          # creates a new Railway project
   railway up            # deploys the middleware/
   ```
4. In the Railway dashboard → your project → **Variables**, add:

   | Key                    | Value                          |
   |------------------------|--------------------------------|
   | `COHERE_API_KEY`       | your Cohere production key     |
   | `NEON_CONNECTION_STRING` | your Neon connection string  |
   | `ALLOWED_PLUGIN_SECRET` | a random 32-char string (generate with `openssl rand -hex 16`) |

5. Railway will give you a URL like `https://my-service.up.railway.app`.
   Copy it.

---

## Step 4 — Update plugin constants before each release

Open `plugin/rag-business-chatbot.php` and update the two constants:

```php
define( 'RAG_CHATBOT_MIDDLEWARE_URL', 'https://my-service.up.railway.app' );
define( 'RAG_CHATBOT_PLUGIN_SECRET',  'the-same-32-char-string-from-railway' );
```

**Important:** `ALLOWED_PLUGIN_SECRET` in Railway env vars and `RAG_CHATBOT_PLUGIN_SECRET`
in the plugin MUST be identical — this is how the middleware verifies that requests come
from your plugin and not random bots.

---

## Step 5 — Release a new plugin ZIP

Push a version tag to trigger the GitHub Action:

```bash
git tag v1.0.0
git push origin v1.0.0
```

The Action will:
1. Run `composer install --no-dev` inside `plugin/`
2. Zip the plugin folder
3. Create a GitHub Release with the ZIP attached

Download the ZIP from the Releases page and distribute to buyers.

---

## Local development

### Running the middleware locally

```bash
cd middleware
composer install
cp .env.example .env        # fill in real keys
php -S localhost:8080 index.php
```

Test with:
```bash
# Health check
curl http://localhost:8080/health

# Ingest a PDF
curl -X POST http://localhost:8080/ingest \
  -H "X-Plugin-Secret: your-secret" \
  -F "pdf=@/path/to/test.pdf" \
  -F "site_id=00000000-0000-0000-0000-000000000001"

# Chat
curl -X POST http://localhost:8080/chat \
  -H "Content-Type: application/json" \
  -H "X-Plugin-Secret: your-secret" \
  -d '{"site_id":"00000000-0000-0000-0000-000000000001","message":"What services do you offer?","history":[]}'
```

### Testing the plugin locally

Use [Local by Flywheel](https://localwp.com) or [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/):

```bash
cd plugin
npm install -g @wordpress/env   # if not already installed
wp-env start
```

Then install the plugin from `wp-env`'s uploads folder or zip it manually.

---

## Updating an existing deployment

1. Update `RAG_CHATBOT_MIDDLEWARE_URL` / `RAG_CHATBOT_PLUGIN_SECRET` in the plugin if changed.
2. Push new code to the middleware and run `railway up` from `middleware/`.
3. Tag and release the plugin ZIP if plugin code changed.

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Admin page shows "Could not reach the middleware" | Railway URL or secret mismatch |
| `/health` returns `cohere: false` | Invalid or expired `COHERE_API_KEY` |
| `/health` returns `neon: false` | Invalid `NEON_CONNECTION_STRING` or schema not applied |
| Chatbot returns "I don't have that information" for everything | PDF wasn't ingested, or wrong `site_id` |
| 403 on all middleware calls | `X-Plugin-Secret` doesn't match `ALLOWED_PLUGIN_SECRET` env var |
