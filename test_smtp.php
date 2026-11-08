<?php
$host = "smtp.gmail.com";
$ip = gethostbyname($host);
echo "DNS: $ip\n";
$fp = @fsockopen($host, 587, $errno, $errstr, 5);
if ($fp) {
    echo "TCP 587: OK\n";
    fclose($fp);
} else {
    echo "TCP 587: FAILED ($errstr $errno)\n";
}
$fp = @fsockopen($host, 465, $errno, $errstr, 5);
if ($fp) {
    echo "TCP 465: OK\n";
    fclose($fp);
} else {
    echo "TCP 465: FAILED ($errstr $errno)\n";
}

