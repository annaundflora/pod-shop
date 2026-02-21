<?php
$host_str = getenv('WORDPRESS_DB_HOST') ?: 'db:3306';
$parts    = explode(':', $host_str);
$host     = $parts[0];
$port     = isset($parts[1]) ? (int)$parts[1] : 3306;
$user     = getenv('WORDPRESS_DB_USER')     ?: 'wordpress';
$pass     = getenv('WORDPRESS_DB_PASSWORD') ?: '';
$dbname   = getenv('WORDPRESS_DB_NAME')     ?: 'wordpress';

$m = @new mysqli($host, $user, $pass, $dbname, $port);
if ($m->connect_error) {
    echo "FAIL: " . $m->connect_error . "\n";
    exit(1);
}
echo "OK\n";
exit(0);
