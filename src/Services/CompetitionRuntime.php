<?php

require_once __DIR__ . '/../Models/Event.php';
require_once __DIR__ . '/../Models/CompetitionSession.php';
require_once __DIR__ . '/../Models/ScoreEntry.php';
require_once __DIR__ . '/../Models/PromotionalSlide.php';
require_once __DIR__ . '/../Models/AnswerSlide.php';
require_once __DIR__ . '/../Support/EventValidator.php';
require_once __DIR__ . '/CompetitionRuntimeException.php';
require_once __DIR__ . '/../../config/constants.php';

/**
 * The competition runtime and scoring engine.
 *
 * This is the ONLY code that should mutate competition_sessions or
 * score_entries during a live event. Both the operator interface and the
 * public display (built in later stages) are expected to call these methods
 * (directly, or via the thin JSON API in public/api/runtime.php) rather than
 * touching the models directly -- that's what guarantees they can never
 * disagree about what's currently happening.
 *
 * Every mutating method:
 *   - runs inside a transaction
 *   - takes `SELECT ... FOR UPDATE` on the session row first, so concurrent
 *     requests for the same event serialize instead of racing
 *   - re-validates everything server-side (never trusts that the caller's
 *     UI already prevented an invalid action)
 */
class CompetitionRuntime
{
    // ------------------------------------------------------------------
    // State read
    // ------------------------------------------------------------------

    /**
     * The full current runtime state for an event: display state, timer
     * (with remaining time freshly computed, never stale), current
     * question (fully resolved), and top-line event/session flags.
     *
     * Also lazily syncs timer expiry (see syncTimerExpiry()) as part of
     * every read, so a timer that ran out between operator actions is
     * reflected the moment anyone asks -- no polling loop or cron needed.
     */
    public static function getState(int $eventId): array
    {
        $event = self::requireEvent($eventId);
        $session = self::syncTimerExpiry($eventId);
        $settings = EventSetting::forEvent($eventId);

        $currentQuestion = null;
        if ($session['current_question_id'] !== null) {
            $q = Question::find((int) $session['current_question_id']);
            if ($q !== null) {
                $currentQuestion = self::resolveQuestion($q, $settings);
            }
        }

        $remaining = CompetitionSession::computeRemainingSeconds($session);

        $questions = Question::forEvent($eventId, true);
        $totalQuestions = count($questions);
        $questionPosition = null;
        $isFirstQuestion = null;
        $isLastQuestion = null;
        if ($session['current_question_id'] !== null) {
            $ids = array_column($questions, 'id');
            $idx = array_search((int) $session['current_question_id'], $ids, true);
            if ($idx !== false) {
                $questionPosition = $idx + 1; // 1-based, for "Question X of Y"
                $isFirstQuestion = ($idx === 0);
                $isLastQuestion = ($idx === count($questions) - 1);
            }
        }

        return [
            'event' => [
                'id'     => (int) $event['id'],
                'name'   => $event['name'],
                'status' => $event['status'],
            ],
            'is_active'      => (bool) $session['is_active'],
            'ended_at'       => $session['ended_at'],
            'display_state'  => $session['display_state'],
            'current_question' => $currentQuestion,
            'question_progress' => [
                'position'         => $questionPosition, // null if no current question
                'total'            => $totalQuestions,
                'is_first'         => $isFirstQuestion,
                'is_last'          => $isLastQuestion,
            ],
            'timer' => [
                'duration_seconds'  => $session['timer_duration_seconds'] !== null ? (int) $session['timer_duration_seconds'] : null,
                'remaining_seconds' => $remaining,
                'is_running'        => CompetitionSession::isTimerRunning($session),
                'is_paused'         => CompetitionSession::isTimerPaused($session),
                'warning_seconds'   => $settings['timer_warning_seconds'] !== null ? (int) $settings['timer_warning_seconds'] : null,
            ],
            'presentation' => EventSetting::presentationToggles($settings),
            'auto_show_ranking_after_score' => (bool) ((int) ($settings['auto_show_ranking_after_score'] ?? 0)),
        ];
    }

    public static function getCurrentQuestion(int $eventId): ?array
    {
        return self::getState($eventId)['current_question'];
    }

    // ------------------------------------------------------------------
    // Event lifecycle
    // ------------------------------------------------------------------

    /**
     * Starts the competition. Idempotent: calling this on an already-active
     * session simply returns the current state unchanged, rather than
     * resetting progress -- guards against an accidental double-click on
     * "Start Event" wiping out a question the operator has already begun.
     */
    public static function startEvent(int $eventId): array
    {
        return self::mutate($eventId, function (array $session, array $event) use ($eventId) {
            if ((bool) $session['is_active']) {
                return null; // already running -- no-op, not a reset
            }

            $problems = EventValidator::readinessProblems($eventId);
            if ($problems !== []) {
                throw new CompetitionRuntimeException(
                    'Cannot start: ' . implode(' ', $problems)
                );
            }

            if ($event['status'] !== EventStatus::COMPLETED && $event['status'] !== EventStatus::ARCHIVED) {
                Event::update($eventId, ['status' => EventStatus::ACTIVE]);
            }

            return [
                'is_active'               => 1,
                'ended_at'                => null,
                'display_state'           => DisplayState::COVER,
                'current_question_id'     => null,
                'timer_duration_seconds'  => null,
                'timer_remaining_seconds' => null,
                'timer_started_at'        => null,
                'timer_paused_at'         => null,
            ];
        });
    }

    /**
     * Ends the competition. Only valid while active (guards against
     * double-ending or ending a competition that was never started).
     */
    public static function endEvent(int $eventId): array
    {
        return self::mutate($eventId, function (array $session, array $event) use ($eventId) {
            if (!(bool) $session['is_active']) {
                throw new CompetitionRuntimeException('The competition is not currently active.');
            }

            Event::update($eventId, ['status' => EventStatus::COMPLETED]);

            return [
                'is_active'        => 0,
                'ended_at'         => date('Y-m-d H:i:s'),
                'display_state'    => DisplayState::FINAL_RESULTS,
                'timer_started_at' => null,
                'timer_paused_at'  => null,
            ];
        });
    }

    // ------------------------------------------------------------------
    // Question navigation
    // ------------------------------------------------------------------

    /**
     * Moves to the first active question. Only valid right at the start of
     * a competition (display_state COVER, no current question yet) --
     * prevents an accidental click from jumping back to question 1 mid-event.
     */
    public static function startFirstQuestion(int $eventId): array
    {
        return self::mutate($eventId, function (array $session) use ($eventId) {
            self::requireActive($session);

            if ($session['current_question_id'] !== null) {
                throw new CompetitionRuntimeException(
                    'A question is already in progress. Use next/previous/go-to-question instead.'
                );
            }

            $questions = Question::forEvent($eventId, true);
            if ($questions === []) {
                throw new CompetitionRuntimeException('This event has no active questions.');
            }

            return self::buildNavigationUpdate($eventId, $questions[0]);
        });
    }

    public static function nextQuestion(int $eventId): array
    {
        return self::mutate($eventId, function (array $session) use ($eventId) {
            self::requireActive($session);
            $current = self::requireCurrentQuestion($session);

            $target = self::findAdjacentActiveQuestion($eventId, (int) $current['id'], 1);
            if ($target === null) {
                throw new CompetitionRuntimeException('There is no next question -- this is the final question.');
            }

            return self::buildNavigationUpdate($eventId, $target);
        });
    }

    public static function previousQuestion(int $eventId): array
    {
        return self::mutate($eventId, function (array $session) use ($eventId) {
            self::requireActive($session);
            $current = self::requireCurrentQuestion($session);

            $target = self::findAdjacentActiveQuestion($eventId, (int) $current['id'], -1);
            if ($target === null) {
                throw new CompetitionRuntimeException('Cannot move before the first question.');
            }

            return self::buildNavigationUpdate($eventId, $target);
        });
    }

    /**
     * Jumps directly to a specific question (must be active and belong to
     * this event). Useful for operator recovery -- e.g. skipping back to a
     * particular question out of normal sequence.
     */
    public static function goToQuestion(int $eventId, int $questionId): array
    {
        return self::mutate($eventId, function (array $session) use ($eventId, $questionId) {
            self::requireActive($session);

            $question = Question::find($questionId);
            if ($question === null || (int) $question['event_id'] !== $eventId) {
                throw new CompetitionRuntimeException('That question does not exist for this event.');
            }
            if (!(bool) $question['is_active']) {
                throw new CompetitionRuntimeException('That question is not active and cannot be shown.');
            }

            return self::buildNavigationUpdate($eventId, $question);
        });
    }

    // ------------------------------------------------------------------
    // Timer
    // ------------------------------------------------------------------

    public static function startTimer(int $eventId): array
    {
        return self::mutate($eventId, function (array $session) {
            self::requireActive($session);
            self::requireQuestionDisplayed($session);

            if (CompetitionSession::isTimerRunning($session)) {
                return null; // already running -- idempotent no-op, not an error
            }
            if (CompetitionSession::isTimerPaused($session)) {
                throw new CompetitionRuntimeException('Timer is paused. Use resume instead of start.');
            }

            return [
                'timer_remaining_seconds' => $session['timer_duration_seconds'],
                'timer_started_at'        => date('Y-m-d H:i:s'),
                'timer_paused_at'         => null,
            ];
        });
    }

    public static function pauseTimer(int $eventId): array
    {
        return self::mutate($eventId, function (array $session) {
            self::requireActive($session);

            if (!CompetitionSession::isTimerRunning($session)) {
                throw new CompetitionRuntimeException('Timer is not running.');
            }

            $remaining = CompetitionSession::computeRemainingSeconds($session);
            if ($remaining <= 0) {
                // Time actually ran out right as pause was requested --
                // treat as expiry, not a pause at 0.
                return [
                    'timer_remaining_seconds' => 0,
                    'timer_started_at'        => null,
                    'timer_paused_at'         => null,
                    'display_state'           => DisplayState::TIME_UP,
                ];
            }

            return [
                'timer_remaining_seconds' => $remaining,
                'timer_started_at'        => null,
                'timer_paused_at'         => date('Y-m-d H:i:s'),
            ];
        });
    }

    public static function resumeTimer(int $eventId): array
    {
        return self::mutate($eventId, function (array $session) {
            self::requireActive($session);

            if (!CompetitionSession::isTimerPaused($session)) {
                throw new CompetitionRuntimeException('Timer is not paused.');
            }
            if ((int) $session['timer_remaining_seconds'] <= 0) {
                throw new CompetitionRuntimeException('Timer already reached zero; reset it to run again.');
            }

            return [
                'timer_started_at' => date('Y-m-d H:i:s'),
                'timer_paused_at'  => null,
            ];
        });
    }

    /**
     * Resets the timer back to the current question's full duration.
     * Also clears a TIME_UP display state back to QUESTION, since the timer
     * is no longer expired.
     */
    public static function resetTimer(int $eventId): array
    {
        return self::mutate($eventId, function (array $session) {
            self::requireActive($session);
            self::requireCurrentQuestion($session);

            return [
                'timer_remaining_seconds' => $session['timer_duration_seconds'],
                'timer_started_at'        => null,
                'timer_paused_at'         => null,
                'display_state'           => DisplayState::QUESTION,
            ];
        });
    }

    /**
     * Checks whether a running timer has actually reached zero and, if so,
     * persists the TIME_UP transition. Called at the top of getState() so
     * expiry is reflected the instant anyone reads state, without relying
     * on a background job. Idempotent and cheap when the timer isn't
     * running or hasn't expired yet.
     */
    private static function syncTimerExpiry(int $eventId): array
    {
        $db = Database::connection();
        $db->beginTransaction();

        try {
            $session = CompetitionSession::lockForEvent($eventId);
            if ($session === null) {
                throw new CompetitionRuntimeException('This event has no competition session.');
            }

            if (CompetitionSession::isTimerRunning($session)
                && CompetitionSession::computeRemainingSeconds($session) <= 0
            ) {
                $session = CompetitionSession::update((int) $session['id'], [
                    'timer_remaining_seconds' => 0,
                    'timer_started_at'        => null,
                    'timer_paused_at'         => null,
                    'display_state'           => DisplayState::TIME_UP,
                ]);
            }

            $db->commit();

            return $session;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Display state
    // ------------------------------------------------------------------

    public static function showRanking(int $eventId): array
    {
        return self::mutate($eventId, function (array $session) {
            self::requireActive($session);

            return ['display_state' => DisplayState::RANKING];
        });
    }

    /**
     * Returns from a RANKING (or other) view back to the current question,
     * respecting whatever the timer's actual state is (QUESTION if time
     * remains, TIME_UP if it doesn't) -- restores exactly where the
     * operator left off rather than assuming.
     */
    public static function returnToQuestion(int $eventId): array
    {
        return self::mutate($eventId, function (array $session) {
            self::requireActive($session);
            self::requireCurrentQuestion($session);

            $remaining = CompetitionSession::computeRemainingSeconds($session);
            $expired = !CompetitionSession::isTimerRunning($session)
                && !CompetitionSession::isTimerPaused($session)
                && $remaining <= 0
                && (int) $session['timer_duration_seconds'] > 0;

            return ['display_state' => $expired ? DisplayState::TIME_UP : DisplayState::QUESTION];
        });
    }

    public static function showFinalResults(int $eventId): array
    {
        return self::mutate($eventId, function (array $session) {
            self::requireActive($session);

            return ['display_state' => DisplayState::FINAL_RESULTS];
        });
    }

    /**
     * Returns the public display to the COVER state without touching
     * current_question_id, the timer, or any scoring data. This is the
     * "reset" available to the operator: it undoes what's on screen, never
     * competition data. A genuine data-wiping reset (deleting scores/
     * progress) is deliberately NOT implemented -- see the architecture
     * notes -- so an accidental click here can never destroy history.
     */
    public static function returnToCover(int $eventId): array
    {
        return self::mutate($eventId, function (array $session) {
            self::requireActive($session);

            return ['display_state' => DisplayState::COVER];
        });
    }

    // ------------------------------------------------------------------
    // Promotional & Answer Slides
    // ------------------------------------------------------------------

    public static function showPromotionalSlide(int $eventId, int $slideId): array
    {
        return self::mutate($eventId, function (array $session) use ($slideId) {
            self::requireActive($session);

            $slide = PromotionalSlide::find($slideId);
            if ($slide === null) {
                throw new CompetitionRuntimeException('That promotional slide does not exist.');
            }
            if ((int) $slide['event_id'] !== $eventId) {
                throw new CompetitionRuntimeException('That promotional slide does not belong to this event.');
            }
            if (!(bool) $slide['is_active']) {
                throw new CompetitionRuntimeException('That promotional slide is not active.');
            }

            return ['display_state' => DisplayState::PROMOTIONAL, 'promotional_slide' => $slide];
        });
    }

    public static function showAnswerSlide(int $eventId, int $slideId): array
    {
        return self::mutate($eventId, function (array $session) use ($slideId) {
            self::requireActive($session);

            $slide = AnswerSlide::find($slideId);
            if ($slide === null) {
                throw new CompetitionRuntimeException('That answer slide does not exist.');
            }
            if ((int) $slide['event_id'] !== $eventId) {
                throw new CompetitionRuntimeException('That answer slide does not belong to this event.');
            }
            if (!(bool) $slide['is_active']) {
                throw new CompetitionRuntimeException('That answer slide is not active.');
            }

            return ['display_state' => DisplayState::ANSWER, 'answer_slide' => $slide];
        });
    }

    public static function hideQuestion(int $eventId): array
    {
        return self::mutate($eventId, function (array $session) {
            self::requireActive($session);

            return ['display_state' => DisplayState::HIDDEN];
        });
    }

    public static function showQuestion(int $eventId): array
    {
        return self::mutate($eventId, function (array $session) {
            self::requireActive($session);
            self::requireCurrentQuestion($session);

            $remaining = CompetitionSession::computeRemainingSeconds($session);
            $expired = !CompetitionSession::isTimerRunning($session)
                && !CompetitionSession::isTimerPaused($session)
                && $remaining <= 0
                && (int) $session['timer_duration_seconds'] > 0;

            return ['display_state' => $expired ? DisplayState::TIME_UP : DisplayState::QUESTION];
        });
    }

    public static function previewNextQuestion(int $eventId): ?array
    {
        $session = CompetitionSession::forEvent($eventId);
        if ($session === null || !(bool) $session['is_active']) {
            return null;
        }

        $currentId = $session['current_question_id'];
        $questions = Question::forEvent($eventId, true);

        if ($currentId === null) {
            return $questions[0] ?? null;
        }

        foreach ($questions as $i => $q) {
            if ((int) $q['id'] === (int) $currentId && $i < count($questions) - 1) {
                return $questions[$i + 1] ?? null;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Round Control
    // ------------------------------------------------------------------

    public static function startRound(int $eventId, ?int $roundNumber = null): array
    {
        return self::mutate($eventId, function (array $session) use ($roundNumber) {
            self::requireActive($session);

            $nextRound = $roundNumber ?? ((int) ($session['current_round'] ?? 0) + 1);

            return [
                'current_round'   => $nextRound,
                'round_started_at' => date('Y-m-d H:i:s'),
                'display_state'   => DisplayState::COVER,
            ];
        });
    }

    public static function endRound(int $eventId): array
    {
        return self::mutate($eventId, function (array $session) {
            self::requireActive($session);

            return [
                'display_state' => DisplayState::RANKING,
            ];
        });
    }

    /**
     * Single composed payload for the operator dashboard: full state, plus
     * the current question's scores and the live rankings -- everything one
     * polling tick needs, in one call, so the UI never has to reassemble
     * this itself from separate pieces (which would risk it drifting from
     * what the service actually considers authoritative).
     */
    public static function getDashboard(int $eventId): array
    {
        $state = self::getState($eventId);

        $currentQuestionScores = [];
        if ($state['current_question'] !== null) {
            $currentQuestionScores = self::getScores($eventId, $state['current_question']['id']);
        }

        // Also fetch all scores for round breakdown view
        $allScores = self::getScores($eventId);

        return [
            'state'                   => $state,
            'current_question_scores' => $currentQuestionScores,
            'rankings'                => self::getRankings($eventId),
            'all_scores'              => $allScores,
        ];
    }

    /**
     * The payload for the public/projector display. Deliberately a
     * different (smaller) shape than getDashboard(): the public screen only
     * ever needs event branding, the current display state, the question
     * itself, the timer, and rankings when relevant. It never includes
     * who-scored-what, per-contestant current-question results, or
     * anything else meant for the operator's eyes only. This is also the
     * one place event branding (logo/cover/subtitle) is exposed, since the
     * operator dashboard has no use for it.
     */
    public static function getPublicState(int $eventId): array
    {
        $event = self::requireEvent($eventId);
        $state = self::getState($eventId);
        $settings = EventSetting::forEvent($eventId);
        $audioConfig = $settings ? EventSetting::audioConfig($settings) : [];

        $needsRanking = in_array($state['display_state'], [DisplayState::RANKING, DisplayState::FINAL_RESULTS], true);

        return [
            'event'          => [
                'name'             => $event['name'],
                'subtitle'         => $event['subtitle'],
                'logo_path'        => $event['logo_path'],
                'cover_image_path' => $event['cover_image_path'],
            ],
            'display_state'  => $state['display_state'],
            'current_question' => $state['current_question'],
            'question_progress' => $state['question_progress'],
            'timer'          => $state['timer'],
            'presentation'   => $state['presentation'],
            'audio_config'   => $audioConfig,
            'rankings'       => $needsRanking ? self::getRankings($eventId) : [],
            'promotional_slide' => $state['display_state'] === DisplayState::PROMOTIONAL ? ($state['promotional_slide'] ?? null) : null,
            'answer_slide'   => $state['display_state'] === DisplayState::ANSWER ? ($state['answer_slide'] ?? null) : null,
        ];
    }

    // ------------------------------------------------------------------
    // Scoring
    // ------------------------------------------------------------------

    /**
     * Records (or corrects) a contestant's result on a question.
     *
     * Points are always derived from the question's configured points --
     * never hard-coded here:
     *   CORRECT    -> the question's effective points (override or event default)
     *   INCORRECT  -> 0
     *   NO_ANSWER  -> 0
     *   ADJUSTMENT -> an explicit operator-supplied point value (manual
     *                 correction not tied to a right/wrong call), required
     *                 when result is ADJUSTMENT
     *
     * Server-side safety checks (never trust the caller's UI already did
     * this): question and contestant must exist, belong to this event, and
     * be active; the event must not be archived.
     */
    public static function scoreContestant(
        int $eventId,
        int $questionId,
        int $contestantId,
        string $result,
        ?int $adjustmentPoints = null,
        ?string $scoredBy = null,
        ?string $reason = null
    ): array {
        $event = self::requireEvent($eventId);
        if ($event['status'] === EventStatus::ARCHIVED) {
            throw new CompetitionRuntimeException('This event is archived and cannot be scored.');
        }

        if (!in_array($result, AnswerResult::ALL, true)) {
            throw new CompetitionRuntimeException('Invalid result value.');
        }

        $question = Question::find($questionId);
        if ($question === null || (int) $question['event_id'] !== $eventId) {
            throw new CompetitionRuntimeException('That question does not exist for this event.');
        }
        if (!(bool) $question['is_active']) {
            throw new CompetitionRuntimeException('That question is inactive and cannot be scored.');
        }

        $contestant = Contestant::find($contestantId);
        if ($contestant === null || (int) $contestant['event_id'] !== $eventId) {
            throw new CompetitionRuntimeException('That contestant does not exist for this event.');
        }
        if (!(bool) $contestant['is_active']) {
            throw new CompetitionRuntimeException('That contestant is inactive and cannot be scored.');
        }

        $settings = EventSetting::forEvent($eventId);
        $points = self::resolveResultPoints($result, $question, $settings, $adjustmentPoints);

        $entry = ScoreEntry::recordResult($eventId, $questionId, $contestantId, $result, $points, $scoredBy, $reason);

        if ((int) ($settings['auto_show_ranking_after_score'] ?? 0) === 1) {
            $session = CompetitionSession::forEvent($eventId);
            if ($session !== null && (bool) $session['is_active']) {
                self::showRanking($eventId);
            }
        }

        return $entry;
    }

    public static function getScores(int $eventId, ?int $questionId = null): array
    {
        self::requireEvent($eventId);

        return ScoreEntry::detailedForEvent($eventId, $questionId);
    }

    public static function getRankings(int $eventId): array
    {
        self::requireEvent($eventId);

        return ScoreEntry::rankingForEvent($eventId);
    }

    private static function resolveResultPoints(string $result, array $question, ?array $settings, ?int $adjustmentPoints): int
    {
        if ($result === AnswerResult::ADJUSTMENT) {
            if ($adjustmentPoints === null) {
                throw new CompetitionRuntimeException('An adjustment requires an explicit point value.');
            }

            return $adjustmentPoints;
        }

        if ($result === AnswerResult::CORRECT) {
            return $settings !== null ? Question::effectivePoints($question, $settings) : (int) ($question['points'] ?? 0);
        }

        // INCORRECT / NO_ANSWER
        return 0;
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    private static function requireEvent(int $eventId): array
    {
        $event = Event::find($eventId);
        if ($event === null) {
            throw new CompetitionRuntimeException('Event not found.');
        }

        return $event;
    }

    private static function requireActive(array $session): void
    {
        if (!(bool) $session['is_active']) {
            throw new CompetitionRuntimeException('The competition has not been started.');
        }
    }

    private static function requireCurrentQuestion(array $session): array
    {
        if ($session['current_question_id'] === null) {
            throw new CompetitionRuntimeException('No question is currently displayed.');
        }

        $question = Question::find((int) $session['current_question_id']);
        if ($question === null) {
            throw new CompetitionRuntimeException('The current question could not be found.');
        }

        return $question;
    }

    private static function requireQuestionDisplayed(array $session): void
    {
        if ($session['display_state'] !== DisplayState::QUESTION) {
            throw new CompetitionRuntimeException('The timer can only be started while a question is being displayed.');
        }
    }

    private static function resolveQuestion(array $question, ?array $settings): array
    {
        $category = null;
        if ($question['category_id'] !== null) {
            $cat = Category::find((int) $question['category_id']);
            $category = $cat !== null ? $cat['name'] : null;
        }

        return [
            'id'              => (int) $question['id'],
            'question_number' => (int) $question['question_number'],
            'category'        => $category,
            'image_path'      => $question['image_path'],
            'points'          => $settings !== null ? Question::effectivePoints($question, $settings) : (int) $question['points'],
            'time_seconds'    => $settings !== null ? Question::effectiveTimeSeconds($question, $settings) : (int) $question['time_seconds'],
        ];
    }

    /**
     * Builds the attribute set for moving the display onto a new question:
     * fresh timer at that question's full resolved duration, idle
     * (not started), display state QUESTION.
     */
    private static function buildNavigationUpdate(int $eventId, array $question): array
    {
        $settings = EventSetting::forEvent($eventId);
        $duration = Question::effectiveTimeSeconds($question, $settings);

        return [
            'current_question_id'    => (int) $question['id'],
            'display_state'          => DisplayState::QUESTION,
            'timer_duration_seconds' => $duration,
            'timer_remaining_seconds' => $duration,
            'timer_started_at'       => null,
            'timer_paused_at'        => null,
        ];
    }

    /**
     * Finds the next active question relative to $fromQuestionId, walking
     * in $direction (1 or -1) through the FULL ordered question list (not
     * just active ones) and skipping inactive questions. Using the full
     * list for positioning means the sequence stays stable even if a
     * question gets deactivated mid-event; only the destination must be
     * active.
     */
    private static function findAdjacentActiveQuestion(int $eventId, int $fromQuestionId, int $direction): ?array
    {
        $all = Question::forEvent($eventId, false);
        $ids = array_column($all, 'id');
        $index = array_search($fromQuestionId, $ids, true);

        if ($index === false) {
            return null;
        }

        for ($i = $index + $direction; $i >= 0 && $i < count($all); $i += $direction) {
            if ((bool) $all[$i]['is_active']) {
                return $all[$i];
            }
        }

        return null;
    }

    /**
     * Wraps a mutating operation in a transaction with the session row
     * locked for update, applies the attribute changes the callback
     * returns, and returns the fresh full state. The callback receives the
     * locked session (and the event row) and returns either an attributes
     * array to persist, or null for a no-op (used for idempotent
     * double-click guards).
     */
    private static function mutate(int $eventId, callable $callback): array
    {
        $event = self::requireEvent($eventId);

        $db = Database::connection();
        $db->beginTransaction();

        try {
            $session = CompetitionSession::lockForEvent($eventId);
            if ($session === null) {
                throw new CompetitionRuntimeException('This event has no competition session.');
            }

            $attributes = $callback($session, $event);

            if ($attributes !== null) {
                CompetitionSession::update((int) $session['id'], $attributes);
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return self::getState($eventId);
    }
}
