<?php

// SEC-14 tests: a stand-in for clamd speaking its INSTREAM protocol (php tests/Support/fake-clamd.php <port>).
// Answers "stream: Eicar-Test-Signature FOUND" when the stream contains the EICAR test string, else "stream: OK".
$server = stream_socket_server('tcp://127.0.0.1:'.(int) $argv[1], $errno, $err);
if (! $server) {
    fwrite(STDERR, $err);
    exit(1);
}
while ($c = @stream_socket_accept($server, 60)) {
    $cmd = '';
    while (! str_ends_with($cmd, "\0") && ($b = fread($c, 1)) !== false && $b !== '') {
        $cmd .= $b;
    }
    $data = '';
    while (true) {
        $len = fread($c, 4);
        if ($len === false || strlen($len) < 4) {
            break;
        }
        $n = unpack('N', $len)[1];
        if ($n === 0) {
            break;
        }
        $chunk = '';
        while (strlen($chunk) < $n && ($part = fread($c, $n - strlen($chunk))) !== false && $part !== '') {
            $chunk .= $part;
        }
        $data .= $chunk;
    }
    fwrite($c, str_contains($data, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE') ? "stream: Eicar-Test-Signature FOUND\0" : "stream: OK\0");
    fclose($c);
}
