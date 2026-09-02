<?php

declare(strict_types=1);

namespace OpenStudio\QueryBuilderBundle\Service;

use DateMalformedStringException;
use DateTimeImmutable;

/**
 * The operations a submitted tree can hold that standard JsonLogic does not know.
 *
 * Two come from react-querybuilder ("startsWith" and "endsWith", emitted for the "beginsWith" and
 * "endsWith" operators), five from this bundle ("regex", "notRegex", "<=ndays", ">=ndays", and
 * "notNull" on a number field). Everything else the editor emits is standard: "and", "or", "!",
 * "==", "!=", "<", "<=", ">", ">=" and "in".
 *
 * Values reach these callables from the evaluated data, so they are of unknown type and are
 * narrowed here rather than trusted: a mistyped or incomplete rule returns false instead of
 * raising a TypeError in the middle of an evaluation.
 */
final class JsonLogicOperations
{
    /**
     * Registers every operation on a JsonLogic evaluator, without the bundle depending on one:
     *
     *     JsonLogicOperations::register(JWadhams\JsonLogic::add_operation(...));
     *
     * @param callable(string, callable): mixed $addOperation
     */
    public static function register(callable $addOperation): void
    {
        foreach (self::all() as $name => $operation) {
            $addOperation($name, $operation);
        }
    }

    /**
     * @return array<string, callable>
     */
    public static function all(): array
    {
        return [
            'startsWith' => self::startsWith(...),
            'endsWith' => self::endsWith(...),
            'regex' => self::matchesRegex(...),
            'notRegex' => self::doesNotMatchRegex(...),
            '<=ndays' => self::atMostDaysOld(...),
            '>=ndays' => self::atLeastDaysOld(...),
            'notNull' => self::isNotEmpty(...),
        ];
    }

    /**
     * An empty prefix would match every record, which is never what an unfinished rule means.
     */
    private static function startsWith(mixed $value, mixed $prefix): bool
    {
        return is_string($value) && is_string($prefix) && '' !== $prefix && str_starts_with($value, $prefix);
    }

    private static function endsWith(mixed $value, mixed $suffix): bool
    {
        return is_string($value) && is_string($suffix) && '' !== $suffix && str_ends_with($value, $suffix);
    }

    /**
     * The pattern carries its own delimiters and flags, as the editor writes them ("/abc/iu"), and
     * it is typed by the user: a pattern that does not compile is a false match, not a warning.
     */
    private static function matchesRegex(mixed $value, mixed $pattern): bool
    {
        if (!is_string($value) || !is_string($pattern) || '' === $pattern) {
            return false;
        }

        set_error_handler(static fn (): bool => true);

        try {
            return 1 === preg_match($pattern, $value);
        } finally {
            restore_error_handler();
        }
    }

    private static function doesNotMatchRegex(mixed $value, mixed $pattern): bool
    {
        return !self::matchesRegex($value, $pattern);
    }

    private static function atMostDaysOld(mixed $date, mixed $days): bool
    {
        $age = self::ageInDays($date);

        return null !== $age && is_numeric($days) && $age <= (int) $days;
    }

    private static function atLeastDaysOld(mixed $date, mixed $days): bool
    {
        $age = self::ageInDays($date);

        return null !== $age && is_numeric($days) && $age >= (int) $days;
    }

    /**
     * Signed, to mirror the SQL datediff(curdate(), field) the parameterized processor emits: a
     * date in the future has a negative age, so it is never "at least N days old".
     */
    private static function ageInDays(mixed $date): ?int
    {
        if (!is_string($date) || '' === $date) {
            return null;
        }

        try {
            $moment = new DateTimeImmutable($date);
        } catch (DateMalformedStringException) {
            return null;
        }

        return (int) $moment->diff(new DateTimeImmutable('today'))->format('%r%a');
    }

    /**
     * The complement of the inclusion in [null, 0] the editor emits for "is empty" on a number
     * field. The comparison is loose on purpose: a 0 reaches the evaluator as an int or as "0"
     * depending on the data source, and both mean empty here.
     */
    private static function isNotEmpty(mixed $value, mixed $emptyValues = null): bool
    {
        $empty = is_array($emptyValues) ? $emptyValues : [null, 0];

        return !in_array($value, $empty, false);
    }
}
