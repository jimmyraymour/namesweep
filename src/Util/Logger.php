<?php

declare(strict_types=1);

namespace NameSweep\Util;

/**
 * File-based logger — one file per day: storage/logs/app-YYYY-MM-DD.log
 *
 * Line format: [ISO8601] [LEVEL] [module] message {context_json}
 * Levels: DEBUG < INFO < WARN < ERROR.
 */
final class Logger
{
    private const LEVELS = ['DEBUG' => 0, 'INFO' => 1, 'WARN' => 2, 'ERROR' => 3];

    private string $dir;
    private int $minLevel;

    public function __construct(string $dir, string $minLevel = 'INFO')
    {
        $this->dir = rtrim($dir, '/');
        $level     = strtoupper($minLevel);
        $this->minLevel = self::LEVELS[$level] ?? self::LEVELS['INFO'];

        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create log directory: {$this->dir}");
        }
    }

    public function log(string $level, string $message, array $context = [], ?string $module = null): void
    {
        $level = strtoupper($level);
        if (!isset(self::LEVELS[$level]) || self::LEVELS[$level] < $this->minLevel) {
            return;
        }

        $suffix    = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $modulePart = $module !== null ? " [$module]" : '';
        $line       = sprintf('[%s] [%s]%s %s%s%s', date('c'), $level, $modulePart, $message, $suffix, PHP_EOL);

        file_put_contents($this->dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public function debug(string $message, array $context = [], ?string $module = null): void
    {
        $this->log('DEBUG', $message, $context, $module);
    }

    public function info(string $message, array $context = [], ?string $module = null): void
    {
        $this->log('INFO', $message, $context, $module);
    }

    public function warning(string $message, array $context = [], ?string $module = null): void
    {
        $this->log('WARN', $message, $context, $module);
    }

    public function error(string $message, array $context = [], ?string $module = null): void
    {
        $this->log('ERROR', $message, $context, $module);
    }
}
