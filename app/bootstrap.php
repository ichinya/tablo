<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidEncodingException;
use Dotenv\Exception\InvalidFileException;

// Keep getenv() compatible with existing configuration readers and preserve process overrides.
try {
    Dotenv::createUnsafeImmutable(dirname(__DIR__))->safeLoad();
} catch (InvalidFileException | InvalidEncodingException) {
    // Parser diagnostics may include credentials from the invalid line.
    throw new RuntimeException('Не удалось загрузить .env: проверьте синтаксис и кодировку файла.');
}
