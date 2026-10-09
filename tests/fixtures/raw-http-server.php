<?php
declare(strict_types=1);

// A real TCP peer: PHP's HTTP server cannot emit broken chunk framing.
$directory = $argv[1];
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) { throw new RuntimeException('Cannot allocate raw HTTP fixture port'); }
file_put_contents($directory . '/ready.tmp', stream_socket_get_name($server, false));
rename($directory . '/ready.tmp', $directory . '/ready');
$deadline = microtime(true) + 120;
$chunked = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nX-Fixture: private-marker\r\nConnection: close\r\n\r\n";
$version = '{"version":"1.3.1","commit":"a61de82"}';
try {
    while (microtime(true) < $deadline && !is_file($directory . '/stop')) {
        $read = [$server];
        $write = $except = [];
        if (stream_select($read, $write, $except, 0, 200000) !== 1) { continue; }
        $connection = stream_socket_accept($server, 0);
        if ($connection === false) { throw new RuntimeException('Cannot accept raw HTTP fixture connection'); }
        try {
            stream_set_timeout($connection, 8);
            // Keep headers in the kernel for /reset: closing with unread bytes
            // generates a genuine TCP reset, without requiring ext-sockets.
            stream_set_chunk_size($connection, 1);
            $line = fgets($connection, 8192);
            $path = explode(' ', $line ?: '')[1] ?? '';
            $mode = explode('/', $path)[1] ?? '';
            if (str_ends_with($path, '/version')) { $mode = 'version'; }
            if ($mode !== 'reset') {
                while (($line = fgets($connection, 8192)) !== false && $line !== "\r\n") {}
            }
            $response = match ($mode) {
                'invalid-hex' => $chunked . "Z\r\nprivate-marker\r\n0\r\n\r\n",
                'long-hex' => $chunked . str_repeat('f', 32) . "\r\nprivate-marker\r\n",
                'overflow-hex' => $chunked . str_repeat('f', 16) . "\r\nprivate-marker\r\n",
                'bad-separator' => $chunked . "1\r\nxZ\r\n0\r\n\r\n",
                'incomplete', 'reset', 'stall' => $chunked . "5\r\nhello\r\n",
                'large' => $chunked . "100001\r\n" . str_repeat('x', 1_048_577) . "\r\n0\r\n\r\n",
                'version' => $chunked . dechex(strlen($version)) . "\r\n" . $version . "\r\n0\r\n\r\n",
                default => $chunked . "5;fixture=yes\r\nhello\r\n0\r\nX-Trailer: valid\r\n\r\n",
            };
            fwrite($connection, $response);
            if ($mode === 'stall') {
                // Wait for the client's deadline to close its handle.
                fread($connection, 1);
                file_put_contents($directory . '/disconnected.tmp', feof($connection) ? '1' : '0');
                rename($directory . '/disconnected.tmp', $directory . '/disconnected');
            }
        } finally { fclose($connection); }
    }
} finally { fclose($server); }
