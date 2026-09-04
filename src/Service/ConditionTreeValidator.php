<?php

declare(strict_types=1);

namespace OpenStudio\QueryBuilderBundle\Service;

use OpenStudio\QueryBuilderBundle\Contract\Exception\ConditionTreeValidationException;
use OpenStudio\QueryBuilderBundle\Enum\Operator;

/**
 * Checks a submitted JsonLogic tree against the fields and operators the form declares.
 *
 * The editor runs in the browser, so the submitted payload is untrusted: the "fields" and
 * "operators" options constrain the UI, never what a crafted POST carries. Field names are the
 * critical part — they cannot be bound as SQL parameters, so an undeclared name reaching a query
 * is an injection.
 */
final readonly class ConditionTreeValidator
{
    /**
     * Group combinators and the negation react-querybuilder wraps "doesNotContain", "notIn",
     * "notBetween" and negated groups in.
     */
    private const COMBINATOR_OPERATIONS = ['and', 'or', '!'];

    /**
     * @var list<string>
     */
    private array $allowedOperations;

    /**
     * @param list<string>   $allowedFieldNames the names declared in the "fields" option
     * @param list<Operator> $allowedOperators  the operators the form offers
     */
    public function __construct(
        private array $allowedFieldNames,
        array $allowedOperators,
    ) {
        $operations = self::COMBINATOR_OPERATIONS;

        foreach ($allowedOperators as $operator) {
            foreach ($operator->jsonLogicOperations() as $operation) {
                $operations[] = $operation;
            }
        }

        $this->allowedOperations = array_values(array_unique($operations));
    }

    /**
     * @throws ConditionTreeValidationException
     */
    public function assertValid(mixed $data): void
    {
        // The "parameterized" processor wraps the tree, and the editor also reopens a bare tree
        // saved by the other processor, so both shapes reach this point.
        if (is_array($data) && array_key_exists('conditionTree', $data)) {
            $this->assertValidNode($data['conditionTree']);

            return;
        }

        $this->assertValidNode($data);
    }

    /**
     * @throws ConditionTreeValidationException
     */
    private function assertValidNode(mixed $node): void
    {
        // A scalar value, or false when the builder was left empty.
        if (!is_array($node)) {
            return;
        }

        // A plain list: the arguments of an operation, or a list of values as in "in".
        if (array_is_list($node)) {
            foreach ($node as $item) {
                $this->assertValidNode($item);
            }

            return;
        }

        if (array_key_exists('var', $node)) {
            $this->assertDeclaredField($node);

            return;
        }

        foreach ($node as $operation => $arguments) {
            $this->assertAllowedOperation($operation);
            $this->assertValidNode($arguments);
        }
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @throws ConditionTreeValidationException
     */
    private function assertDeclaredField(array $node): void
    {
        // A field reference carries nothing else: {"var": "name", "in": [...]} would otherwise
        // smuggle an unchecked operation past this method.
        if (1 !== count($node)) {
            throw new ConditionTreeValidationException('A field reference carries unexpected keys.');
        }

        $fieldName = $node['var'];

        if (!is_string($fieldName)) {
            throw new ConditionTreeValidationException(sprintf('A field reference is a "%s" instead of a string.', get_debug_type($fieldName)));
        }

        if (!in_array($fieldName, $this->allowedFieldNames, true)) {
            throw new ConditionTreeValidationException('A field reference names a field that is not declared in the "fields" option.');
        }
    }

    /**
     * @throws ConditionTreeValidationException
     */
    private function assertAllowedOperation(int|string $operation): void
    {
        if (!is_string($operation) || !in_array($operation, $this->allowedOperations, true)) {
            throw new ConditionTreeValidationException('An operation is not one of those the declared operators produce.');
        }
    }
}
