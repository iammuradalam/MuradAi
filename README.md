# Murad AI

A personal AI assistant you can host yourself on ordinary shared hosting. It chats, talks out loud, reads your files, remembers things about you, and keeps every conversation — with accounts, so more than one person can use it.

Built with plain HTML, CSS, JavaScript and PHP. No build step, no framework, no database server.

![Talk mode](screenshots/talk-mode.png)

## Features

- **Chat** with a free AI model (Groq, Google Gemini or OpenRouter)
- **Talk mode** — speak to it and it answers out loud, with a 3D android that blinks and follows you
- **Nine languages** for speech, switchable mid-conversation; the voice matches the language of each answer
- **Reads files** — PDF, Word, text and code, plus photos and scanned PDFs through a vision model
- **Memory** — tell it something once ("remember I live in Tampere") and it uses it in later chats
- **Accounts** — sign up with an email, and chats sync between your phone and laptop; guests get a smaller message limit
- **Saved chats**, grouped by date, searchable, exportable

## How it works

```
Browser (index.html)  --->  api.php   --->  Groq / Gemini / OpenRouter
                      --->  auth.php  --+
                      --->  data.php  --+-->  JSON files above the web root
```

`api.php` is a proxy: the API key lives on the server, never in the browser, so visitors can't take it. If one provider hits its free limit or retires a model, it falls through to the next one automatically.

| File | Purpose |
|---|---|
| `index.html` | The whole app — interface, voice, 3D graphics, file reading |
| `api.php` | Talks to the AI provider, hides the key, limits usage |
| `auth.php` | Create account, sign in, sign out |
| `data.php` | Saves each account's chats and memories |
| `lib.php` | Shared storage and account helpers |
| `config.example.php` | Template for your settings — copy to `config.php` |
| `lib/` | pdf.js, for reading PDFs in the browser |

## Setup

1. Get a free API key from [Groq](https://console.groq.com/keys). Add a [Gemini](https://aistudio.google.com/apikey) key too if you want photos and scanned PDFs read.
2. Copy the config and add your key:
   ```bash
   cp config.example.php config.php
   ```
   Then edit `config.php` and replace `PASTE_YOUR_GROQ_KEY_HERE`.
3. Upload everything to your web host (PHP 8 with cURL; tested on InfinityFree).
4. Turn on HTTPS — browsers refuse microphone access without it.
5. Open the site.

`config.php` is in `.gitignore` on purpose. Never commit it.

## Settings worth knowing

| Setting | Does |
|---|---|
| `accounts.require_login` | `true` means nobody can chat without an account |
| `accounts.guest_messages_per_hour` | How much a guest may use (default 50) |
| `accounts.invite_code` | Only people who know the word can sign up |
| `max_requests_per_hour` | Limit for people with an account |

## Notes

- Accounts and chats are stored as JSON files in a folder created **above** the web root, so they can't be downloaded. Passwords are hashed, never stored as text.
- Free AI tiers change their model names often. If you get a "model not found" error, update the names in `config.php`.
- Voice needs Chrome, Edge or Safari. Firefox has no speech recognition.
- On iPhone, the silent switch mutes web speech — that's an Apple restriction, not a bug.
