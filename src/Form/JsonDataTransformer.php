<?php

declare(strict_types=1);

namespace OpenStudio\QueryBuilderBundle\Form;

use OpenStudio\QueryBuilderBundle\Contract\Exception\ConditionTreeValidationException;
use OpenStudio\QueryBuilderBundle\Service\ConditionTreeValidator;
use Override;
use Safe\Exceptions\JsonException;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

use function Safe\json_decode;
use function Safe\json_encode;

/**
 * @implements DataTransformerInterface<array<array-key, mixed>, string>
 */
final readonly class JsonDataTransformer implements DataTransformerInterface
{
    public function __construct(
        private ?ConditionTreeValidator $conditionTreeValidator = null,
    ) {
    }

    #[Override]
    public function reverseTransform($value): ?array
    {
        if (empty($value)
            || 'null' === $value || 'false' === $value
            || '[]' === $value || '{}' === $value
            || '""' === $value || "''" === $value
        ) {
            return null;
        }

        try {
            $decoded = json_decode($value, true);
        } catch (JsonException $exception) {
            throw new TransformationFailedException('Invalid JSON.', 0, $exception);
        }

        // The widget submits a JsonLogic object, the wrapper of the "parameterized" processor, or
        // the react-querybuilder group of the "native" one.
        // Anything else is valid JSON but not a query: a scalar ("42", "\"abc\"") would reach the
        // model and fail in the property setter, which is a 500 for what is only an invalid
        // submission, and a list ("[1,2]") is no tree either — a tree is always keyed by its
        // operation. The empty ones are already null, handled above.
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new TransformationFailedException(sprintf('The submitted query is a "%s" instead of an object.', get_debug_type($decoded)), 0, null, 'The submitted query is invalid.');
        }

        // The "parameterized" processor wraps the tree next to the SQL, so an emptied editor
        // arrives as a wrapper the sentinels above cannot recognize, where the "jsonLogic" processor
        // sends a plain "{}" that already becomes null. Both mean "no condition", and the SQL that
        // comes with an empty tree is the neutral "(1 = 1)": persisting it would leave the
        // application a statement matching every single row. The "native" processor sends "{}" as
        // well, but a group emptied of its rules is the same "no condition" if a client builds it.
        if ($this->isEmptyConditionTree($decoded)) {
            return null;
        }

        try {
            $this->conditionTreeValidator?->assertValid($decoded);
        } catch (ConditionTreeValidationException $exception) {
            // The message states what a crafted payload carried, so it stays server-side: only the
            // generic invalid message reaches the user.
            throw new TransformationFailedException($exception->getMessage(), 0, $exception, 'The submitted query is invalid.');
        }

        return $decoded;
    }

    #[Override]
    public function transform($value): string
    {
        if (empty($value)) {
            return json_encode([], \JSON_FORCE_OBJECT);
        }

        try {
            return json_encode($value);
        } catch (JsonException $exception) {
            throw new TransformationFailedException('The value cannot be encoded to JSON.', 0, $exception);
        }
    }

    /**
     * A wrapper whose tree is absent, empty, or not a tree at all ("false" for an editor emptied
     * before that was normalized to an empty object), or a native group with no rule left in it.
     *
     * @param array<array-key, mixed> $decoded
     */
    private function isEmptyConditionTree(array $decoded): bool
    {
        // A "rules" key is the native group: a JsonLogic tree is keyed by its operations and the
        // wrapper by its two parts, neither has one.
        if (array_key_exists('rules', $decoded)) {
            $rules = $decoded['rules'];

            return !is_array($rules) || [] === $rules;
        }

        if (!array_key_exists('conditionTree', $decoded) && !array_key_exists('parameterizedSql', $decoded)) {
            return false;
        }

        $conditionTree = $decoded['conditionTree'] ?? null;

        return !is_array($conditionTree) || [] === $conditionTree;
    }
}
