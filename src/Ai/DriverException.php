<?php

declare(strict_types=1);

namespace Germa\Ai;

/**
 * Any failure to obtain AI output. Callers catch this and fall back to the
 * rules driver: a dashboard that loads without a boardroom paragraph is far
 * better than one that 500s because an API call timed out.
 */
final class DriverException extends \RuntimeException
{
    /**
     * The part of an SDK failure worth storing and showing.
     *
     * The SDK reports HTTP errors by appending the whole JSON response body to
     * the message. Stored raw, that blob fills the 255-character log column
     * with braces and truncates before reaching the one sentence that says what
     * went wrong, so the boardroom minutes showed a wall of JSON instead of
     * "this model does not support the fallbacks parameter".
     */
    public static function readable(string $raw, int $max = 255): string
    {
        // Read the message field by hand: the stored body may already be
        // truncated, so parsing it as JSON is not safe.
        if (preg_match('/"message"\s*:\s*"([^"]*)/', $raw, $m) === 1 && trim($m[1]) !== '') {
            $message = stripcslashes(trim($m[1]));

            // Keep the driver's own leading summary when it adds context the
            // API message does not, such as which class of error it was.
            $head = rtrim(trim(explode("\n", $raw)[0]), ':');

            if ($head !== '' && stripos($head, $message) === false) {
                $message = $head . ': ' . $message;
            }

            return mb_substr($message, 0, $max);
        }

        return mb_substr(trim(explode("\n", $raw)[0]), 0, $max);
    }
}
