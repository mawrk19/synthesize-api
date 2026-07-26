<?php

namespace App\Support;

/**
 * Controls what sensitive / diagnostic detail is returned to API clients.
 * Full messages should still be logged and stored server-side for operators.
 */
final class ClientDebug
{
    public static function enabled(): bool
    {
        return (bool) config('synthesize.debug', false);
    }

    /**
     * Return the message as-is when debug is on; otherwise a safe public string.
     */
    public static function publicError(
        ?string $message,
        string $fallback = 'Something went wrong. Please try again or contact support.',
    ): ?string {
        if ($message === null || $message === '') {
            return $message;
        }

        if (self::enabled()) {
            return $message;
        }

        if (self::isSafePublicMessage($message)) {
            return $message;
        }

        return $fallback;
    }

    /**
     * Hide internal fields (prompts, raw diagnostics) when debug is off.
     */
    public static function internal(?string $value): ?string
    {
        if (self::enabled()) {
            return $value;
        }

        return null;
    }

    public static function isSafePublicMessage(string $message): bool
    {
        $trimmed = trim($message);

        if ($trimmed === '') {
            return true;
        }

        // Hard deny: dumps, SQL, tokens, GitHub validation JSON, stack frames
        if (preg_match(
            '/SQLSTATE|stack trace|ghp_[A-Za-z0-9]|github_pat_|sk-[A-Za-z0-9]|Validation Failed|at vendor\/|\\\\[a-zA-Z]+Exception\b|PDOException|Traceback \(most recent/i',
            $trimmed
        )) {
            return false;
        }

        if (str_contains($trimmed, '{"') || str_contains($trimmed, "\n")) {
            return false;
        }

        $safeExact = [
            'Cancelled by user.',
            'Project repository is not configured.',
            'SRS document not found.',
            'Planner produced no tasks.',
            'No tasks to execute.',
            'One or more pipeline tasks failed.',
            'GitHub check runs failed.',
            'Blocked by failing CI checks.',
            'Blocked by TesterAgent.',
            'Gherkin validation failed.',
            'Missing developer dependency.',
            'Developer agent produced no file changes.',
        ];

        if (in_array($trimmed, $safeExact, true)) {
            return true;
        }

        $safePrefixes = [
            'Skipped at approval',
            'Skipped because',
            'Blocked because',
            'Pipeline run is not',
            'An active pipeline',
            'SRS document must be',
            'Document does not belong',
            'Review link',
            'At least one developer task',
            'One or more task_ids',
            'Failed to open pull request', // short summary only — full body denied by JSON check
            'GitHub rejected',
            'GitHub could not find',
            'GitHub error while',
            'GitHub token is not configured',
            'Repository linked, but',
        ];

        foreach ($safePrefixes as $prefix) {
            if (str_starts_with($trimmed, $prefix) && strlen($trimmed) <= 200) {
                return true;
            }
        }

        // Short operational messages without dump markers
        return strlen($trimmed) <= 120;
    }
}
