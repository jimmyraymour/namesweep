<?php

declare(strict_types=1);

namespace NameSweep\Util;

/**
 * Minimal .env loader — no dotenv dependency, plain parsing.
 *
 * Values in the project's .env file win over pre-existing environment
 * variables, and the first occurrence in the file wins over later ones.
 * (Reversed 2026-09-06: the machine-wide environment on the owner's box
 * carries DB_USER/DB_NAME/DB_PASSWORD from other projects, which used to
 * silently override .env and break every CLI entry point with
 * "Access denied for user 'root'@'localhost'".)
 *
 * Escape hatch: when the process environment sets
 * NAMESWEEP_ENV_ALLOW_OVERRIDE=1, real environment variables win again.
 * The smoke suite sets this so the dev-server children it spawns honour
 * the RATE_LIMIT_PER_MINUTE / OLLAMA_BASE_URL values it injects.
 */
final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        // Shared mod_php worker guard (tracker 3269): putenv()'d keys below
        // leak into whichever sibling app the Apache worker serves next.
        // Snapshot the process env and restore it on shutdown.
        static $guarded = false;
        if (!$guarded) {
            $guarded = true;
            $envAtStart = getenv();
            if (is_array($envAtStart)) {
                register_shutdown_function(static function () use ($envAtStart): void {
                    $now = getenv();
                    if (!is_array($now)) {
                        return;
                    }
                    foreach ($now as $key => $value) {
                        if (isset($envAtStart[$key])) {
                            if ($envAtStart[$key] !== $value) {
                                putenv($key . '=' . $envAtStart[$key]);
                            }
                        } else {
                            putenv($key);
                        }
                    }
                });
            }
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Strip one layer of surrounding quotes.
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last  = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            // The project's .env wins over a pre-existing environment
            // variable — unless the process opted into real-env precedence
            // (the smoke suite's spawned children). See the class docblock.
            if (getenv($key) !== false) {
                if ((getenv('NAMESWEEP_ENV_ALLOW_OVERRIDE') ?: '') !== '1') {
                    putenv($key . '=' . $value);
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}
