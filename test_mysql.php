<?php
$host = getenv("DB_HOST");
$port = getenv("DB_PUERTO");
$name = getenv("DB_NOMBRE");
$user = getenv("DB_USUARIO");
$pass = getenv("DB_CLAVE");
echo "Host: $host\n";
echo "Port: $port\n";
echo "Name: $name\n";
echo "User: $user\n";
echo "---\n";
try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    echo "MySQL OK: " . $pdo->query("SELECT 1")->fetchColumn();
} catch (Throwable $e) {
    echo "MySQL ERROR: " . $e->getMessage();
}

