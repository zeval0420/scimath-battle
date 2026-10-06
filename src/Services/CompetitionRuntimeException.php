<?php

/**
 * Thrown for expected, user-facing runtime problems -- invalid navigation,
 * scoring an inactive contestant, starting an already-active event, and so
 * on. Callers (API layer, future operator UI) can catch this specifically
 * and show the message directly; anything else escaping the service layer
 * is treated as an unexpected error.
 */
class CompetitionRuntimeException extends RuntimeException
{
}
