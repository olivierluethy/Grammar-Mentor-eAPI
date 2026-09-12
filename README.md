# Grammar Mentor — Backend API

PHP backend API for **Grammar Mentor**, an AI writing assistant. It handles Google
sign-in, subscription billing via **LemonSqueezy** (including webhooks), and AI-powered
grammar and writing feedback through the **OpenAI API**.

## Features

- **Google Sign-In** token verification
- **LemonSqueezy** checkout and subscription **webhooks** (`subscribe.php`, `webhook.php`)
- **AI grammar & writing** feedback via the OpenAI API
- MySQL schema in `db.sql`

## Tech

PHP · Composer · OpenAI API · LemonSqueezy · Google Identity · MySQL

## Setup

This service reads its credentials (OpenAI key, LemonSqueezy key + webhook signing
secret, Google client ID) from environment configuration. Provide your **own** keys via a
local `.env` — never commit real credentials.

```bash
composer install
```

Then serve the PHP files behind your web server.
