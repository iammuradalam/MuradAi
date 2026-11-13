<?php
/* =========================================================
   MURAD AI — settings (PRIVATE: never share this file)
   ========================================================= */
return [

    // 1) Paste your free API keys here. One is enough; two gives a backup.
    //    Groq:    https://console.groq.com/keys
    //    Gemini:  https://aistudio.google.com/apikey
    'api_keys' => [
        'groq'       => 'PASTE_YOUR_GROQ_KEY_HERE',
        'gemini'     => '',
        'openrouter' => '',
    ],

    // 2) Which provider to try first. If it fails, the next one is used.
    'order' => ['groq', 'gemini', 'openrouter'],

    // 3) Models for normal text chat (tried from left to right).
    //    If a provider retires a model, just replace its name here.
    'models' => [
        'groq'       => ['openai/gpt-oss-120b', 'llama-3.3-70b-versatile', 'openai/gpt-oss-20b'],
        'gemini'     => ['gemini-2.5-flash', 'gemini-2.5-flash-lite'],
        'openrouter' => ['openrouter/free'],
    ],

    // 4) Models that can SEE images (photos, screenshots, scanned PDFs).
    'vision_models' => [
        'gemini'     => ['gemini-2.5-flash', 'gemini-2.5-flash-lite'],
        'groq'       => ['meta-llama/llama-4-scout-17b-16e-instruct'],
        'openrouter' => ['openrouter/free'],
    ],

    // 5) Passcode. Leave '' so anyone can use Murad AI, or set a word so
    //    only people who know it can (it protects your free daily limit).
    'passcode' => '',

    // 6) Max messages one visitor can send per hour (0 = no limit).
    'max_requests_per_hour' => 40,

    // 7) Longest answer the AI may write (in tokens, ~0.75 words each).
    'max_tokens' => 4096,
];
