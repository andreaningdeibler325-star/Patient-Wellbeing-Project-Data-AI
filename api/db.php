<?php
declare(strict_types=1);

/*
 * api/db.php
 *
 * Shared SQL Server connection for authentication.
 *
 * Your PHP installation exposes the PDO "sqlsrv" driver, so this file
 * uses PDO_SQLSRV with Windows Authentication.
 */

const DB_SERVER = 'localhost';
const DB_NAME   = 'PatientWellbeingDB';

function getDb(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn =
        'sqlsrv:Server=' . DB_SERVER .
        ';Database=' . DB_NAME .
        ';Encrypt=1' .
        ';TrustServerCertificate=1';

    try {
        $pdo = new PDO(
            $dsn,
            null,
            null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        return $pdo;
    } catch (PDOException $e) {
        error_log('[api/db.php] Connection failed: ' . $e->getMessage());
        throw $e;
    }
}
