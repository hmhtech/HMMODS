<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'valid' => false,
        'code' => 'METHOD_NOT_ALLOWED'
    ]);
    exit;
}

$configFile = __DIR__ . DIRECTORY_SEPARATOR . 'Config.json';

if (!is_file($configFile) || !is_readable($configFile) || !is_writable($configFile)) {
    http_response_code(500);
    echo json_encode([
        'valid' => false,
        'code' => 'SERVER_CONFIG_ERROR'
    ]);
    exit;
}

$body = file_get_contents('php://input');
$request = json_decode($body ?: '', true);

$key = trim((string)($request['key'] ?? ''));
$deviceId = strtolower(trim((string)($request['device_id'] ?? '')));

if ($key === '' || !preg_match('/^HMH-[A-Za-z0-9]{5}(?:-[A-Za-z0-9]{5}){2}$/', $key)) {
    echo json_encode(['valid' => false, 'code' => 'INVALID']);
    exit;
}

if ($deviceId === '' || !preg_match('/^[a-f0-9]{32}$/', $deviceId)) {
    echo json_encode(['valid' => false, 'code' => 'INVALID_DEVICE']);
    exit;
}

$config = json_decode(file_get_contents($configFile), true);

if (!is_array($config) || !isset($config['licenses']) || !is_array($config['licenses'])) {
    http_response_code(500);
    echo json_encode(['valid' => false, 'code' => 'SERVER_CONFIG_ERROR']);
    exit;
}

$today = new DateTimeImmutable('today');
$index = null;

foreach ($config['licenses'] as $i => $license) {
    if (
        is_array($license) &&
        hash_equals((string)($license['key'] ?? ''), $key)
    ) {
        $index = $i;
        break;
    }
}

if ($index === null) {
    echo json_encode(['valid' => false, 'code' => 'INVALID']);
    exit;
}

$license = $config['licenses'][$index];

if (strtolower((string)($license['status'] ?? '')) !== 'active') {
    echo json_encode(['valid' => false, 'code' => 'BLOCKED']);
    exit;
}

$expires = trim((string)($license['expires'] ?? ''));

if ($expires !== '') {
    try {
        $expiry = new DateTimeImmutable($expires);
        if ($expiry < $today) {
            echo json_encode(['valid' => false, 'code' => 'EXPIRED']);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['valid' => false, 'code' => 'SERVER_CONFIG_ERROR']);
        exit;
    }
}

$boundDevice = strtolower(trim((string)($license['device_id'] ?? '')));

if ($boundDevice !== '') {
    if (!hash_equals($boundDevice, $deviceId)) {
        echo json_encode([
            'valid' => false,
            'code' => 'DEVICE_MISMATCH'
        ]);
        exit;
    }

    echo json_encode([
        'valid' => true,
        'code' => 'OK',
        'bound_now' => false,
        'expires' => $expires
    ]);
    exit;
}

/*
 * First activation:
 * Bind this key to this device.
 * LOCK_EX prevents two first activations from racing.
 */
$config['licenses'][$index]['device_id'] = $deviceId;

$encoded = json_encode(
    $config,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);

if ($encoded === false) {
    http_response_code(500);
    echo json_encode(['valid' => false, 'code' => 'SERVER_CONFIG_ERROR']);
    exit;
}

$fp = fopen($configFile, 'c+');

if ($fp === false || !flock($fp, LOCK_EX)) {
    if (is_resource($fp)) {
        fclose($fp);
    }
    http_response_code(500);
    echo json_encode(['valid' => false, 'code' => 'SERVER_WRITE_ERROR']);
    exit;
}

rewind($fp);
ftruncate($fp, 0);

$written = fwrite($fp, $encoded . PHP_EOL);
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

if ($written === false) {
    http_response_code(500);
    echo json_encode(['valid' => false, 'code' => 'SERVER_WRITE_ERROR']);
    exit;
}

echo json_encode([
    'valid' => true,
    'code' => 'OK',
    'bound_now' => true,
    'expires' => $expires
]);
?>

