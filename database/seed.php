<?php

/**
 * Usage: php database/seed.php
 *
 * Populates the database with a small, clearly-fake sample event so the
 * admin/operator/public interfaces have something to render against during
 * development. Safe to run multiple times against a fresh DB; running it
 * twice without resetting merely creates a second sample event.
 */

require_once __DIR__ . '/seeders/DevSeeder.php';

DevSeeder::run();
