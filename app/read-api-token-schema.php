<?php
declare(strict_types=1);

namespace Tablo;

function read_api_token_schema(): string
{
    return "CREATE TABLE read_api_token (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        secret_hash TEXT NOT NULL CHECK (typeof(secret_hash) = 'text' AND length(secret_hash) = 64 AND length(CAST(secret_hash AS BLOB)) = 64 AND secret_hash NOT GLOB '*[^0-9a-f]*'),
        administrator_fingerprint TEXT NOT NULL CHECK (typeof(administrator_fingerprint) = 'text' AND length(administrator_fingerprint) = 64 AND length(CAST(administrator_fingerprint AS BLOB)) = 64 AND administrator_fingerprint NOT GLOB '*[^0-9a-f]*'),
        created_at TEXT NOT NULL CHECK (typeof(created_at) = 'text' AND length(created_at) = 20 AND length(CAST(created_at AS BLOB)) = 20
            AND created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'
            AND strftime('%Y-%m-%dT%H:%M:%SZ', created_at, '+0 days') IS NOT NULL
            AND strftime('%Y-%m-%dT%H:%M:%SZ', created_at, '+0 days') = created_at)
    )";
}
