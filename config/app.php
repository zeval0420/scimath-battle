<?php

/**
 * Application-level settings that aren't database connection details.
 *
 * Admin credentials are intentionally simple (single shared admin account,
 * no user table) -- the spec for this stage asks only that admin/operator
 * functions be protected from unauthorized access, not that a full
 * multi-user account system be built. A `password_hash()`'d credential is
 * read from the environment; a dev-only fallback is provided so the app
 * runs locally without extra setup, and is clearly marked as such.
 */

return [
    'admin' => [
        'username' => getenv('ADMIN_USERNAME') ?: 'admin',
        // Fallback password for local development ONLY: "admin123"
        // Always set ADMIN_USERNAME / ADMIN_PASSWORD_HASH in production.
        'password_hash' => getenv('ADMIN_PASSWORD_HASH')
            ?: '$2y$10$ft8NYp8viIbSGSczwNDQh.7ip1iOzylqMrVRXjqj0ROBo23hhnPHe',
    ],

    'uploads' => [
        'max_bytes' => 5 * 1024 * 1024, // 5 MB
        'allowed_extensions' => ['png', 'jpg', 'jpeg', 'webp'],
        'allowed_mime_types' => [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ],
        // Absolute path to the web-served uploads directory.
        'base_path' => __DIR__ . '/../public/uploads',
        // Public URL prefix corresponding to base_path, for building <img src>.
        'base_url'  => 'uploads',
    ],
];
