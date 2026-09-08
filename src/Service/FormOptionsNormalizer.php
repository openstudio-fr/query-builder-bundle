<?php

declare(strict_types=1);

namespace OpenStudio\QueryBuilderBundle\Service;

use OpenStudio\QueryBuilderBundle\Contract\Exception\FieldNormalizationException;
use OpenStudio\QueryBuilderBundle\Contract\Exception\OperatorListNormalizationException;
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
        private OperatorListNormalizer $operatorListNormalizer,
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
     */
    public function normalizeOperators(Options $options, ?array $operators): ?array
    {
        if (null === $operators) {
            return null;
        }

        try {
            return ($this->operatorListNormalizer)($operators);
        } catch (OperatorListNormalizationException $exception) {
            throw new InvalidOptionsException('The option "operators" '.$exception->getMessage(), $exception->getCode(), $exception);
        }
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
