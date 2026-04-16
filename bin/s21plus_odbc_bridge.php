#!/usr/bin/env php
<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$action = $argv[1] ?? 'probe';
$userId = $argv[2] ?? null;

try {
    $pdo = connectToS21Plus();

    $payload = match ($action) {
        'probe' => probeConnection($pdo),
        'inspect' => inspectUser($pdo, requireUserId($userId)),
        'unlock' => unlockUser($pdo, requireUserId($userId)),
        default => throw new InvalidArgumentException(sprintf('Unsupported action [%s].', $action)),
    };

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'action' => $action,
        'payload' => $payload,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL);

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'action' => $action,
        'error' => $exception->getMessage(),
        'type' => $exception::class,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL);

    exit(1);
}

function connectToS21Plus(): PDO
{
    ensurePdoOdbcIsAvailable();
    configureDynamicLibrarySearchPath();
    configureOdbcDriverRegistration();

    $driverName = (string) config('database.connections.s21plus.odbc_driver_name', 'ODBC Driver 18 for SQL Server');
    $host = (string) config('database.connections.s21plus.host');
    $instance = trim((string) config('database.connections.s21plus.instance', ''));
    $port = trim((string) config('database.connections.s21plus.port', '1433'));
    $database = (string) config('database.connections.s21plus.database');
    $username = (string) config('database.connections.s21plus.username');
    $password = (string) config('database.connections.s21plus.password');

    if ($host === '' || $database === '' || $username === '' || $password === '') {
        throw new RuntimeException('S21Plus ODBC configuration is incomplete.');
    }

    if ($instance !== '') {
        $resolvedPort = resolvePortFromSqlBrowser($host, $instance);

        if ($resolvedPort !== null) {
            $port = $resolvedPort;
        }
    }

    $dsn = sprintf(
        'odbc:Driver={%s};Server=%s,%s;Database=%s;Encrypt=no;TrustServerCertificate=yes;',
        $driverName,
        $host,
        $port,
        $database
    );

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function probeConnection(PDO $pdo): array
{
    $statement = $pdo->query('SELECT DB_NAME() AS database_name');
    $row = $statement->fetch() ?: [];

    return [
        'database_name' => $row['database_name'] ?? null,
    ];
}

function inspectUser(PDO $pdo, string $userId): array
{
    $statement = $pdo->prepare('SELECT [UserID], [IsEnabled], [LoginRetry] FROM [User] WHERE [UserID] = ?');
    $statement->execute([$userId]);

    $row = $statement->fetch();

    if (! is_array($row)) {
        return [
            'found' => false,
            'user_id' => $userId,
        ];
    }

    return normalizeUserRow($row);
}

function unlockUser(PDO $pdo, string $userId): array
{
    $before = inspectUser($pdo, $userId);

    if (($before['found'] ?? false) !== true) {
        return [
            'before' => $before,
            'after' => $before,
        ];
    }

    $statement = $pdo->prepare('UPDATE [User] SET [IsEnabled] = 1, [LoginRetry] = 0 WHERE [UserID] = ?');
    $statement->execute([$userId]);

    return [
        'before' => $before,
        'after' => inspectUser($pdo, $userId),
    ];
}

function normalizeUserRow(array $row): array
{
    return [
        'found' => true,
        'user_id' => (string) ($row['UserID'] ?? ''),
        'is_enabled' => ((int) ($row['IsEnabled'] ?? 0)) === 1,
        'login_retry' => (int) ($row['LoginRetry'] ?? 0),
    ];
}

function requireUserId(?string $value): string
{
    $trimmed = trim((string) $value);

    if ($trimmed === '') {
        throw new InvalidArgumentException('UserID argument is required.');
    }

    return $trimmed;
}

function ensurePdoOdbcIsAvailable(): void
{
    if (! extension_loaded('pdo_odbc')) {
        throw new RuntimeException('The PDO_ODBC extension is not loaded in this PHP runtime.');
    }
}

function configureDynamicLibrarySearchPath(): void
{
    $paths = array_values(array_filter([
        base_path('third_party/msodbcsql18/msodbcsql-18.6.2.1/lib'),
        '/opt/homebrew/lib',
        '/opt/homebrew/opt/unixodbc/lib',
        '/opt/homebrew/opt/openssl@3/lib',
    ], static fn ($path) => is_dir($path)));

    if ($paths !== []) {
        putenv('DYLD_LIBRARY_PATH='.implode(':', $paths));
    }
}

function configureOdbcDriverRegistration(): void
{
    $driverName = (string) config('database.connections.s21plus.odbc_driver_name', 'ODBC Driver 18 for SQL Server');
    $driverLibrary = (string) config('database.connections.s21plus.odbc_driver_library', base_path('third_party/msodbcsql18/msodbcsql-18.6.2.1/lib/libmsodbcsql.18.dylib'));

    if (! is_file($driverLibrary)) {
        throw new RuntimeException(sprintf('ODBC driver library was not found at [%s].', $driverLibrary));
    }

    $runtimeDirectory = storage_path('app/private/odbc');

    if (! is_dir($runtimeDirectory) && ! mkdir($runtimeDirectory, 0775, true) && ! is_dir($runtimeDirectory)) {
        throw new RuntimeException(sprintf('Unable to create ODBC runtime directory [%s].', $runtimeDirectory));
    }

    $odbcInstPath = $runtimeDirectory.'/odbcinst.ini';
    $contents = sprintf(
        "[%s]\nDescription=Microsoft ODBC Driver 18 for SQL Server\nDriver=%s\n",
        $driverName,
        $driverLibrary
    );

    if (! file_exists($odbcInstPath) || file_get_contents($odbcInstPath) !== $contents) {
        file_put_contents($odbcInstPath, $contents);
    }

    putenv('ODBCSYSINI='.$runtimeDirectory);
    putenv('ODBCINSTINI=odbcinst.ini');
}

function resolvePortFromSqlBrowser(string $host, string $instance): ?string
{
    $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

    if ($socket === false) {
        return null;
    }

    try {
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => 3,
            'usec' => 0,
        ]);

        $payload = "\x04".$instance;
        socket_sendto($socket, $payload, strlen($payload), 0, $host, 1434);

        $response = '';
        $from = '';
        $port = 0;
        $bytes = @socket_recvfrom($socket, $response, 4096, 0, $from, $port);

        if (! is_int($bytes) || $bytes <= 0) {
            return null;
        }

        return extractTcpPortFromBrowserResponse($response, $instance);
    } finally {
        socket_close($socket);
    }
}

function extractTcpPortFromBrowserResponse(string $response, string $instance): ?string
{
    $normalized = ltrim($response, "\x05");
    $normalized = preg_replace('/^[\x00-\x1F]+/u', '', $normalized) ?? $normalized;
    $parts = array_values(array_filter(explode(';', $normalized), static fn ($value) => $value !== ''));
    $map = [];

    for ($index = 0; $index + 1 < count($parts); $index += 2) {
        $map[strtolower($parts[$index])] = $parts[$index + 1];
    }

    if (
        isset($map['instancename'], $map['tcp'])
        && strcasecmp((string) $map['instancename'], $instance) === 0
        && preg_match('/^\d+$/', (string) $map['tcp'])
    ) {
        return (string) $map['tcp'];
    }

    return null;
}
