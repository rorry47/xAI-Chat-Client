# xAI Chat Client — Self-Hosted AI Chat powered by Grok API

A fully functional chat client in a single PHP file, running on the xAI Grok API.
Supports multiple AI characters, image generation, streaming responses,
and password protection.

## ✨ Features

- 💬 **Streaming responses** — text appears in real time as the AI types
- 🎭 **Multiple characters** — create your own AI bots with custom system prompts and avatars
- 🖼️ **Image generation** — automatic intent detection + automatic prompt enhancement
- 🔐 **Password protection** — private access without unnecessary registration
- 💾 **Chat history** — conversations are saved locally in JSON files
- 📱 **Responsive design** — works great on both mobile and desktop
- ⚡ **Single file** — no frameworks, no npm, no databases

## ⚙️ Requirements & Configuration

- **PHP 7.4 or higher** (PHP 8.x recommended)
- **cURL extension** must be enabled on your server

Before uploading, open `index.php` and edit lines **9 and 10**:

```php
define('XAI_API_KEY', 'YOUR_API_KEY_HERE'); // line 9 — your xAI API key
define('CHAT_PASSWORD', 'YOUR_PASSWORD_HERE'); // line 10 — access password
```

Get your API key at [console.x.ai](https://console.x.ai).

## 🤖 Model

This script uses **`grok-4-1-fast-non-reasoning`** — xAI's cost-optimized,
low-latency model with a 2M token context window, ideal for real-time chat.

## 🚀 Installation

1. Edit lines 9–10 in `index.php` (API key + password)
2. Upload the file to any PHP hosting
3. Open in browser — done

## 💰 Cost

Runs on the xAI Grok API with pay-per-use billing.
For personal use, costs typically amount to **$1–5/month** —
far cheaper than any comparable paid AI chat subscription.

## 💸 Why Cheaper Than Character.AI

Character.AI charges **$9.99/month** for c.ai+.
With Grok 4.1 Fast at just **$0.20 per million input tokens**,
sending 100 messages a day costs you **under $1/month**.
Plus: full character control, built-in image generation,
no ads, no queues, and your data stays on your own server.
