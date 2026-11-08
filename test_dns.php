<?php
$host = getenv("DB_HOST");
echo "Host: $host\n";
$ip = gethostbyname($host);
echo "Resolved IP: $ip\n";
if ($ip === $host) {
    echo "DNS resolution FAILED\n";
} else {
    echo "DNS resolution OK\n";
    // Try to connect
    $fp = @fsockopen($host, 3306, $errno, $errstr, 5);
    if ($fp) {
        echo "TCP connection OK\n";
        fclose($fp);
    } else {
        echo "TCP connection FAILED: $errstr ($errno)\n";
    }
}

