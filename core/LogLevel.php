<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

enum LogLevel: int
{
    case Debug = 0;
    case Info = 1;
    case Warning = 2;
    case Error = 3;
    case Critical = 4;

    public function label(): string
    {
        return match ($this) {
            self::Debug => 'DEBUG',
            self::Info => 'INFO',
            self::Warning => 'WARNING',
            self::Error => 'ERROR',
            self::Critical => 'CRITICAL',
        };
    }
}
