<?php

declare(strict_types=1);

namespace OpenStudio\QueryBuilderBundle\Service;

use OpenStudio\QueryBuilderBundle\Contract\Exception\FieldNormalizationException;
use OpenStudio\QueryBuilderBundle\Dto\Field;
use OpenStudio\QueryBuilderBundle\Enum\Operator;
use OpenStudio\QueryBuilderBundle\Enum\QueryBuilderProcessor;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Options;

/**
 * @phpstan-import-type FieldShape from FieldNormalizer
 */
final readonly class FormOptionsNormalizer
{
    public function __construct(
        private FieldNormalizer $fieldNormalizer,
    ) {
    }

    /**
     * @param Options<array<string, mixed>> $options
     * @param array<Field|FieldShape>       $fields
     *
     * @return list<Field>
     *
     * @throws InvalidOptionsException
     *
     * @psalm-suppress TooManyTemplateParams
     */
    public function normalizeFields(Options $options, array $fields): array
    {
        $fieldsNormalized = [];
        $names = [];

        foreach ($fields as $field) {
            try {
                $fieldNormalized = ($this->fieldNormalizer)($field);
            } catch (FieldNormalizationException $e) {
                throw new InvalidOptionsException('The option "fields" contains a field whose '.$e->getMessage(), $e->getCode(), $e);
            }

            if ('' === $fieldNormalized->name) {
                throw new InvalidOptionsException('The option "fields" contains a field with an empty name.');
            }

            if (isset($names[$fieldNormalized->name])) {
                throw new InvalidOptionsException(sprintf('The option "fields" contains the field "%s" more than once.', $fieldNormalized->name));
            }

            $fieldsNormalized[] = $fieldNormalized;
            $names[$fieldNormalized->name] = true;
        }

        return $fieldsNormalized;
    }

    /**
     * @param Options<array<string, mixed>> $options
     * @param array<Operator|string>|null   $operators
     *
     * @return non-empty-list<Operator>|null
     *
     * @throws InvalidOptionsException
     *
     * @psalm-suppress TooManyTemplateParams
     * @psalm-suppress DocblockTypeContradiction The "operators" option also allows plain arrays, so entries are checked at runtime.
     */
    public function normalizeOperators(Options $options, ?array $operators): ?array
    {
        if (null === $operators) {
            return null;
        }

        if ([] === $operators) {
            throw new InvalidOptionsException('The option "operators" must be null or a non-empty list of operators.');
        }

        $accepted = implode('", "', Operator::values());
        $operatorsNormalized = [];

        foreach ($operators as $operator) {
            if (!$operator instanceof Operator && !is_string($operator)) {
                throw new InvalidOptionsException(sprintf('The option "operators" contains an entry of type "%s"; expected "%s" or string.', get_debug_type($operator), Operator::class));
            }

            if (is_string($operator)) {
                $operator = Operator::tryFrom($operator)
                    ?? throw new InvalidOptionsException(sprintf('The option "operators" contains the value "%s", which is invalid. Accepted values are: "%s".', $operator, $accepted));
            }

            if (in_array($operator, $operatorsNormalized, true)) {
                throw new InvalidOptionsException(sprintf('The option "operators" contains the operator "%s" more than once.', $operator->value));
            }

            $operatorsNormalized[] = $operator;
        }

        return $operatorsNormalized;
    }

    /**
     * @param Options<array<string, mixed>> $options
     *
     * @throws InvalidOptionsException
     *
     * @psalm-suppress TooManyTemplateParams
     */
    public function normalizeProcessor(Options $options, QueryBuilderProcessor|string $processor): QueryBuilderProcessor
    {
        if ($processor instanceof QueryBuilderProcessor) {
            return $processor;
        }

        $accepted = implode('", "', QueryBuilderProcessor::values());

        return QueryBuilderProcessor::tryFrom($processor)
            ?? throw new InvalidOptionsException(sprintf('The option "processor" with value "%s" is invalid. Accepted values are: "%s".', $processor, $accepted));
    }
}
