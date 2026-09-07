<?php

declare(strict_types=1);

namespace OpenStudio\QueryBuilderBundle\Service;

use OpenStudio\QueryBuilderBundle\Contract\Exception\ConditionTreeValidationException;
use OpenStudio\QueryBuilderBundle\Enum\Operator;

/**
 * Checks a submitted condition tree against the fields and operators the form declares.
 *
 * The editor runs in the browser, so the submitted payload is untrusted: the "fields" and
 * "operators" options constrain the UI, never what a crafted POST carries. Field names are the
 * critical part — they cannot be bound as SQL parameters, so an undeclared name reaching a query
 * is an injection.
 *
 * Two tree shapes are checked: the JsonLogic tree of the "jsonLogic" and "parameterized"
 * processors, walked operation by operation, and the react-querybuilder group of the "native"
 * processor, walked rule by rule.
 */
final readonly class ConditionTreeValidator
{
    /**
     * Group combinators and the negation react-querybuilder wraps "doesNotContain", "notIn",
     * "notBetween" and negated groups in.
     */
    private const COMBINATOR_OPERATIONS = ['and', 'or', '!'];

    /**
     * The combinators of a native group. Negation is a flag on the group there, not a combinator.
     */
    private const NATIVE_COMBINATORS = ['and', 'or'];

    /**
     * @var list<string>
     */
    private array $allowedOperations;

    /**
     * @var list<string>
     */
    private array $allowedOperatorNames;

    /**
     * @param list<string>   $allowedFieldNames the names declared in the "fields" option
     * @param list<Operator> $allowedOperators  the operators the form offers
     */
    public function __construct(
        private array $allowedFieldNames,
        array $allowedOperators,
    ) {
        $operations = self::COMBINATOR_OPERATIONS;
        $operatorNames = [];

        foreach ($allowedOperators as $operator) {
            foreach ($operator->jsonLogicOperations() as $operation) {
                $operations[] = $operation;
            }

            $operatorNames[] = $operator->nativeOperator();
        }

        $this->allowedOperations = array_values(array_unique($operations));
        $this->allowedOperatorNames = array_values(array_unique($operatorNames));
    }

    /**
     * @throws ConditionTreeValidationException
     */
    public function assertValid(mixed $data): void
    {
        // A "rules" key is the group of the "native" processor: a JsonLogic tree is keyed by its
        // operations, and the wrapper below by its two parts.
        if (is_array($data) && array_key_exists('rules', $data)) {
            $this->assertValidGroup($data);

            return;
        }

        // The "parameterized" processor wraps the tree, and the editor also reopens a bare tree
        // saved by the "jsonLogic" processor, so both shapes reach this point.
        if (is_array($data) && array_key_exists('conditionTree', $data)) {
            $this->assertValidNode($data['conditionTree']);

            return;
        }

        $this->assertValidNode($data);
    }

    /**
     * @param array<array-key, mixed> $group
     *
     * @throws ConditionTreeValidationException
     */
    private function assertValidGroup(array $group): void
    {
        $combinator = $group['combinator'] ?? null;

        if (!is_string($combinator) || !in_array($combinator, self::NATIVE_COMBINATORS, true)) {
            throw new ConditionTreeValidationException('A group combinator is not "and" or "or".');
        }

        if (array_key_exists('not', $group) && !is_bool($group['not'])) {
            throw new ConditionTreeValidationException(sprintf('A group negation is a "%s" instead of a boolean.', get_debug_type($group['not'])));
        }

        $rules = $group['rules'];

        if (!is_array($rules) || !array_is_list($rules)) {
            throw new ConditionTreeValidationException(sprintf('A group holds a "%s" instead of a list of rules.', get_debug_type($rules)));
        }

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                throw new ConditionTreeValidationException(sprintf('A rule is a "%s" instead of an object.', get_debug_type($rule)));
            }

            // A nested group. Told apart on the key, as a consumer compiling the tree does.
            if (array_key_exists('rules', $rule)) {
                $this->assertValidGroup($rule);

                continue;
            }

            $this->assertValidRule($rule);
        }
    }

    /**
     * The field and the operator are checked, not the value: a value is bound, a field name and an
     * operator are turned into SQL.
     *
     * @param array<array-key, mixed> $rule
     *
     * @throws ConditionTreeValidationException
     */
    private function assertValidRule(array $rule): void
    {
        $fieldName = $rule['field'] ?? null;

        if (!is_string($fieldName)) {
            throw new ConditionTreeValidationException(sprintf('A rule field is a "%s" instead of a string.', get_debug_type($fieldName)));
        }

        if (!in_array($fieldName, $this->allowedFieldNames, true)) {
            throw new ConditionTreeValidationException('A rule names a field that is not declared in the "fields" option.');
        }

        $operator = $rule['operator'] ?? null;

        if (!is_string($operator) || !in_array($operator, $this->allowedOperatorNames, true)) {
            throw new ConditionTreeValidationException('A rule operator is not one of the declared operators.');
        }
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
