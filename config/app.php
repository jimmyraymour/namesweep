<?php

/**
 * Main application configuration.
 *
 * Values come from the environment (see .env.example) with sensible
 * defaults so the app runs out of the box. This file is loaded by
 * bootstrap.php.
 */

// Resolve the RDAP bootstrap cache path against the project root
// (absolute paths pass through untouched).
$rdapCacheEnv = getenv('RDAP_BOOTSTRAP_CACHE') ?: 'storage/cache/rdap_bootstrap.json';
$rdapCache = ($rdapCacheEnv !== '' && $rdapCacheEnv[0] === '/')
    ? $rdapCacheEnv
    : __DIR__ . '/../' . $rdapCacheEnv;

return [
    'app' => [
        'env'      => getenv('APP_ENV') ?: 'development',
        'debug'    => filter_var(getenv('APP_DEBUG') ?: 'true', FILTER_VALIDATE_BOOL),
        'base_url' => getenv('APP_BASE_URL') ?: 'http://localhost:8000',
        'timezone' => getenv('APP_TIMEZONE') ?: 'America/Santo_Domingo',
        'log_level'=> getenv('LOG_LEVEL') ?: 'INFO',
    ],

    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'namesweep',
        'user' => getenv('DB_USER') ?: 'namesweep',
        'pass' => getenv('DB_PASS') ?: '',
    ],

    'http' => [
        'timeout'    => (int) (getenv('HTTP_TIMEOUT_SECONDS') ?: 8),
        'user_agent' => getenv('HTTP_USER_AGENT') ?: 'NameSweep/1.0',
    ],

    'llm' => [
        'ollama_base_url'    => getenv('OLLAMA_BASE_URL') ?: 'http://127.0.0.1:11434',
        'ollama_model'       => getenv('OLLAMA_MODEL') ?: 'llama3.1:8b',
        'openrouter_api_key' => getenv('OPENROUTER_API_KEY') ?: '',
        'openrouter_model'   => getenv('OPENROUTER_MODEL') ?: 'meta-llama/llama-3.1-8b-instruct:free',
    ],

    'aftermarket' => [
        'openprovider_api_url' => getenv('OPENPROVIDER_API_URL') ?: 'https://api.openprovider.com/v1beta',
        'openprovider_api_key' => getenv('OPENPROVIDER_API_KEY') ?: '',
    ],

    'rdap' => [
        'bootstrap_url'   => getenv('RDAP_BOOTSTRAP_URL') ?: 'https://data.iana.org/rdap/dns.json',
        'bootstrap_cache' => $rdapCache,
    ],

    'rate_limit' => [
        'per_minute' => (int) (getenv('RATE_LIMIT_PER_MINUTE') ?: 60),
    ],

    'default_tlds'    => ['com', 'net', 'io'],
    'default_modules' => ['domain', 'marketplace', 'social', 'trademark'],
    'default_markets' => ['us'],

    'ttl' => [
        'domain'      => (int) (getenv('TTL_DOMAIN') ?: 24) * 3600,
        'marketplace' => (int) (getenv('TTL_MARKETPLACE') ?: 24) * 3600,
        'social'      => (int) (getenv('TTL_SOCIAL') ?: 168) * 3600,
        'trademark'   => (int) (getenv('TTL_TRADEMARK') ?: 720) * 3600,
    ],

    'prompts' => [
        'suggest_system' => 'You are a naming assistant. Return only valid JSON: {"names": [...]}',
        'suggest_user'   => "Project: {description}\nTLDs: {tlds}\nNames to avoid (already taken): {rejected}\nReturn {count} short, brandable names as JSON.",
        'summary_system' => 'You write one-sentence English summaries of name-availability reports. Max 280 chars.',
        'summary_user'   => "Name: {name}\nResults: {results_json}\nWrite one sentence summarizing availability.",
    ],
];
