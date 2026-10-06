<?php

/**
 * Database connection configuration.
 *
 * Uses the same connection settings as the rest of the reserve-a-lab /
 * scilab pages by including the shared db_connection.php helper. The values
 * below come from that file, so the scimath app always talks to the same
 * database (dbadmin) as the rest of the system.
 */

require_once __DIR__ . '/../../../scilab/helperFiles/db_connection.php';

return [
    'driver'    => 'mysql',
    'host'      => $servername,
    'port'      => '3306',
    'database'  => $database,
    'username'  => $username,
    'password'  => $password,
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];
