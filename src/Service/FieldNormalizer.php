<?php

declare(strict_types=1);

namespace OpenStudio\QueryBuilderBundle\Service;

use OpenStudio\QueryBuilderBundle\Contract\Exception\FieldNormalizationException;
use OpenStudio\QueryBuilderBundle\Contract\Exception\OperatorListNormalizationException;
use OpenStudio\QueryBuilderBundle\Dto\Field;
use OpenStudio\QueryBuilderBundle\Dto\FieldOption;
use OpenStudio\QueryBuilderBundle\Enum\Operator;
use OpenStudio\QueryBuilderBundle\Enum\ValueType;

/**
 * @phpstan-type FieldOptionShape array{name?: string, label?: string|null, value?: string|null}
 * @phpstan-type FieldShape array{
 *     name?: string,
 *     type?: ValueType|string,
 *     label?: string|null,
 *     labelInformation?: string|null,
 *     values?: array<int|string, FieldOption|FieldOptionShape|string>|null,
 *     operators?: array<Operator|string>|null,
 * }
 */
final readonly class FieldNormalizer
{
    public function __construct(
        private OperatorListNormalizer $operatorListNormalizer,
    ) {
    }

    /**
     * @param Field|FieldShape $field
     *
     * @throws FieldNormalizationException
     */
    public function __invoke(Field|array $field): Field
    {
        if ($field instanceof Field) {
            return $this->withNormalizedOperators($field);
        }

        if (!isset($field['name'])) {
            throw new FieldNormalizationException('"name" is empty.');
        }

        return new Field(
            name: $this->assertString($field['name'], '"name"'),
            type: $this->resolveValueType($field['type'] ?? ''),
            label: $this->assertNullableString($field['label'] ?? null, '"label"'),
            labelInformation: $this->assertNullableString($field['labelInformation'] ?? null, '"labelInformation"'),
            values: $this->normalizeValues($field['values'] ?? null),
            operators: $this->normalizeOperators($field['operators'] ?? null),
        );
    }

    /**
     * A Field built by hand is taken as is, except for its operators, which go through the same
     * checks as those of the array shape: given as backing strings, they become enum cases.
     *
     * @throws FieldNormalizationException
     */
    private function withNormalizedOperators(Field $field): Field
    {
        if (null === $field->operators) {
            return $field;
        }

        $operators = $this->normalizeOperators($field->operators);

        if ($operators === $field->operators) {
            return $field;
        }

        return new Field(
            name: $field->name,
            type: $field->type,
            label: $field->label,
            labelInformation: $field->labelInformation,
            values: $field->values,
            operators: $operators,
        );
    }

    /**
     * @return non-empty-list<Operator>|null
     *
     * @throws FieldNormalizationException
     */
    private function normalizeOperators(mixed $operators): ?array
    {
        if (null === $operators) {
            return null;
        }

        if (!is_array($operators)) {
            throw new FieldNormalizationException(sprintf('"operators" is not an array ("%s" given).', get_debug_type($operators)));
        }

        try {
            return ($this->operatorListNormalizer)($operators);
        } catch (OperatorListNormalizationException $exception) {
            throw new FieldNormalizationException('"operators" '.$exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * @throws FieldNormalizationException
     */
    private function resolveValueType(mixed $fieldType): ValueType
    {
        if ($fieldType instanceof ValueType) {
            return $fieldType;
        }
        if (!is_string($fieldType)) {
            throw new FieldNormalizationException(sprintf('"type" is not a string or a %s ("%s" given).', ValueType::class, get_debug_type($fieldType)));
        }
        if ('' === $fieldType) {
            return ValueType::Text;
        }

        $accepted = implode('", "', ValueType::values());

        return ValueType::tryFrom($fieldType)
            ?? throw new FieldNormalizationException(sprintf('"type" has an unknown value "%s". Accepted values are: "%s".', $fieldType, $accepted));
    }

    /**
     * @return list<FieldOption>|null
     *
     * @throws FieldNormalizationException
     */
    private function normalizeValues(mixed $values): ?array
    {
        // An empty list means the field has no list of values at all, never a select with nothing
        // to pick, so it normalizes to null rather than to an empty list.
        if (null === $values || [] === $values) {
            return null;
        }

        if (!is_array($values)) {
            throw new FieldNormalizationException(sprintf('"values" is not an array ("%s" given).', get_debug_type($values)));
        }

        $options = [];

        foreach ($values as $name => $option) {
            $options[] = match (true) {
                $option instanceof FieldOption => $option,
                is_array($option) => $this->resolveOption($option),
                is_string($option) && is_string($name) => new FieldOption(name: $name, label: $option),
                default => throw new FieldNormalizationException(sprintf('"values" contains an entry of type "%s". Accepted entries are a FieldOption, an associative array with a "name" key, or a "name => label" string pair.', get_debug_type($option))),
            };
        }

        return $options;
    }

    /**
     * @param array<array-key, mixed> $option
     *
     * @throws FieldNormalizationException
     */
    private function resolveOption(array $option): FieldOption
    {
        if (!isset($option['name'])) {
            throw new FieldNormalizationException('"values" contains an entry without a "name".');
        }

        return new FieldOption(
            name: $this->assertString($option['name'], '"values" contains an entry whose "name"'),
            label: $this->assertNullableString($option['label'] ?? null, '"values" contains an entry whose "label"'),
            value: $this->assertNullableString($option['value'] ?? null, '"values" contains an entry whose "value"'),
        );
    }

    /**
     * @throws FieldNormalizationException
     */
    private function assertString(mixed $value, string $subject): string
    {
        if (!is_string($value)) {
            throw new FieldNormalizationException(sprintf('%s is not a string ("%s" given).', $subject, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * @throws FieldNormalizationException
     */
    private function assertNullableString(mixed $value, string $subject): ?string
    {
        if (null === $value) {
            return null;
        }

        return $this->assertString($value, $subject);
    }
}
