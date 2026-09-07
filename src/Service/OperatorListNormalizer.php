<?php

declare(strict_types=1);

namespace OpenStudio\QueryBuilderBundle\Service;

use OpenStudio\QueryBuilderBundle\Contract\Exception\OperatorListNormalizationException;
use OpenStudio\QueryBuilderBundle\Enum\Operator;

/**
 * Turns a list of operators, given as enum cases or backing strings, into a list of enum cases.
 *
 * The "operators" form option and the "operators" of a Field follow the same rules, so both come
 * through here: the list is not empty, every entry is a known operator, none is repeated. The
 * messages start right after their subject, which the caller names, so one rule reads the same
 * wherever it fails.
 */
final readonly class OperatorListNormalizer
{
    /**
     * @param array<array-key, mixed> $operators
     *
     * @return non-empty-list<Operator>
     *
     * @throws OperatorListNormalizationException
     */
    public function __invoke(array $operators): array
    {
        if ([] === $operators) {
            throw new OperatorListNormalizationException('must be null or a non-empty list of operators.');
        }

        $accepted = implode('", "', Operator::values());
        $operatorsNormalized = [];

        foreach ($operators as $operator) {
            if (!$operator instanceof Operator && !is_string($operator)) {
                throw new OperatorListNormalizationException(sprintf('contains an entry of type "%s"; expected "%s" or string.', get_debug_type($operator), Operator::class));
            }

            if (is_string($operator)) {
                $operator = Operator::tryFrom($operator)
                    ?? throw new OperatorListNormalizationException(sprintf('contains the value "%s", which is invalid. Accepted values are: "%s".', $operator, $accepted));
            }

            if (in_array($operator, $operatorsNormalized, true)) {
                throw new OperatorListNormalizationException(sprintf('contains the operator "%s" more than once.', $operator->value));
            }

            $operatorsNormalized[] = $operator;
        }

        return $operatorsNormalized;
    }
}
