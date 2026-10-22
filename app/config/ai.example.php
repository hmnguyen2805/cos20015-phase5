<?php
// Copy to app\config\ai.php (git-ignored) to turn on AI extraction of
// adverse-event narratives. Without ai.php the AI review page shows how to
// set it up and nothing is sent anywhere.
//
// Treat the key like a password: it lives only in ai.php, never in git,
// never in the database or the audit log.
//
// provider 'gemini': free key from Google AI Studio (aistudio.google.com, "Get API key").
//   On the FREE tier Google may use prompts and answers to improve its products
//   and people may review them, so only synthetic data (like this project's) may
//   be sent. A real trial would need a paid tier that does not train on the data.
// provider 'claude': key from the Claude Console (API keys page), paid per use.
return [
    'provider' => 'gemini',                 // 'gemini' or 'claude'
    'api_key'  => 'AIza...',
    // Optional: leave out to use the default for the provider
    // 'model'      => 'gemini-3.5-flash-lite',   // claude default: 'claude-haiku-5-5'
    // 'max_tokens' => 4096,
    // 'timeout'    => 60,                        // seconds per narrative
];
