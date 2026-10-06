<?php

/**
 * Usage: php database/seed.php
 *
 * Populates the database with a small, clearly-fake sample event so the
 * admin/operator/public interfaces have something to render against during
 * development. Safe to run multiple times against a fresh DB; running it
 * twice without resetting will create a second sample event (categories,
 * contestants and questions are not globally unique across events by
 * design, so this won't error -- it'll just add another demo event).
 */

require_once __DIR__ . '/seeders/DevSeeder.php';

DevSeeder::run();
