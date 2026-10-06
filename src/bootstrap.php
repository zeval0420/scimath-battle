<?php

/**
 * Shared bootstrap for the admin interface. Included once at the top of
 * every admin page/action. Starts the session, loads configuration, and
 * pulls in the models and support classes the admin UI needs.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

require_once __DIR__ . '/../config/constants.php';

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Models/Model.php';
require_once __DIR__ . '/Models/Event.php'; // pulls in EventSetting/Category/Contestant/Question
require_once __DIR__ . '/Models/ScoreEntry.php';
require_once __DIR__ . '/Models/ScoreAdjustment.php';
require_once __DIR__ . '/Models/CompetitionSession.php';

require_once __DIR__ . '/Support/Auth.php';
require_once __DIR__ . '/Support/Csrf.php';
require_once __DIR__ . '/Support/Flash.php';
require_once __DIR__ . '/Support/Validator.php';
require_once __DIR__ . '/Support/Uploader.php';
require_once __DIR__ . '/Support/EventValidator.php';
require_once __DIR__ . '/Services/CompetitionRuntimeException.php';
require_once __DIR__ . '/Services/CompetitionRuntime.php';

/**
 * Build a path-relative URL for an app-internal target (e.g.
 * "/admin/index.php") from the currently-executing script.
 *
 * Works no matter which subfolder the app is served from: it walks up from
 * the current script's directory (resolved physically) until it reaches the
 * public web root, then appends the target minus its leading slash. The
 * browser resolves the result relative to the page it's on.
 *
 * Example: executing script = .../public/admin/actions/event_save.php,
 * target = "/admin/index.php" -> returns "../../admin/index.php".
 */
function relative_url(string $target): string
{
    $public = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/../public')), '/');
    $current = dirname(realpath($_SERVER['SCRIPT_FILENAME'] ?? __DIR__ . '/index.php'));
    $current = rtrim(str_replace('\\', '/', $current), '/');

    $up = '';
    $rest = $current;
    while ($rest !== $public && str_starts_with($rest, $public . '/')) {
        $up .= '../';
        $rest = dirname($rest);
    }

    return $up . ltrim($target, '/');
}
