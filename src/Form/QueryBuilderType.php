<?php

declare(strict_types=1);

namespace OpenStudio\QueryBuilderBundle\Form;

use OpenStudio\QueryBuilderBundle\Dto\Field;
use OpenStudio\QueryBuilderBundle\Enum\Operator;
use OpenStudio\QueryBuilderBundle\Enum\QueryBuilderProcessor;
use OpenStudio\QueryBuilderBundle\Service\ConditionTreeValidator;
use OpenStudio\QueryBuilderBundle\Service\FormOptionsNormalizer;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Webmozart\Assert\Assert;

/**
 * @phpstan-extends AbstractType<mixed>
 *
 * @psalm-suppress TooManyTemplateParams
 */
class QueryBuilderType extends AbstractType
{
    public function __construct(
        private readonly FormOptionsNormalizer $formOptionsNormalizer,
        private readonly string $defaultLocale = 'fr',
    ) {
    }

    #[Override]
    public function getParent(): string
    {
        return HiddenType::class;
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'empty_data' => '{}',
            // HiddenType would say "The hidden field is invalid." for a widget the user can see.
            // Both conversion paths are covered: the Core listener reads this option, the validator
            // extension prefers the message carried by the TransformationFailedException.
            'invalid_message' => 'The submitted query is invalid.',
            // HiddenType bubbles errors up to the root form, where a visible widget's errors would
            // go unnoticed. Keep them on the field, next to the editor that produced them.
            'error_bubbling' => false,
            'lang' => $this->defaultLocale,
            'fields' => [],
            'operators' => null,
            'processor' => QueryBuilderProcessor::JsonLogic,
            'validate_condition_tree' => true,
        ]);
        // Lang
        $resolver->setAllowedTypes('lang', 'string');
        // Fields
        $resolver->setAllowedTypes('fields', [Field::class.'[]', 'array']);
        $resolver->setNormalizer('fields', $this->formOptionsNormalizer->normalizeFields(...));
        // Operators
        $resolver->setAllowedTypes('operators', ['null', Operator::class.'[]', 'array']);
        $resolver->setNormalizer('operators', $this->formOptionsNormalizer->normalizeOperators(...));
        // Processor
        $resolver->setAllowedTypes('processor', [QueryBuilderProcessor::class, 'string']);
        $resolver->setNormalizer('processor', $this->formOptionsNormalizer->normalizeProcessor(...));
        // Condition tree validation
        $resolver->setAllowedTypes('validate_condition_tree', 'bool');
    }

    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new JsonDataTransformer($this->createConditionTreeValidator($options)));
    }

    #[Override]
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $lang = $options['lang'];
        Assert::stringNotEmpty($lang);

        $fields = $options['fields'];
        Assert::isArray($fields);
        Assert::allIsInstanceOf($fields, Field::class);

        $operators = $options['operators'];
        if (null !== $operators) {
            Assert::isArray($operators);
            Assert::allIsInstanceOf($operators, Operator::class);
            $operators = array_map(static fn (Operator $operator): string => $operator->value, $operators);
        }

        $processor = $options['processor'];
        Assert::isInstanceOf($processor, QueryBuilderProcessor::class);

        $view->vars['lang'] = substr($lang, 0, 2);
        $view->vars['fields'] = array_map(static fn (Field $field): array => $field->toArray(), $fields);
        $view->vars['operators'] = $operators;
        $view->vars['processor'] = $processor->value;
    }

    /**
     * @param array<array-key, mixed> $options
     */
    private function createConditionTreeValidator(array $options): ?ConditionTreeValidator
    {
        if (true !== $options['validate_condition_tree']) {
            return null;
        }

        $fields = $options['fields'];
        Assert::isArray($fields);
        Assert::allIsInstanceOf($fields, Field::class);

        // A null "operators" option means the editor offers every operator it knows.
        $operators = $options['operators'] ?? Operator::cases();
        Assert::isArray($operators);
        Assert::allIsInstanceOf($operators, Operator::class);

        return new ConditionTreeValidator(
            array_values(array_map(static fn (Field $field): string => $field->name, $fields)),
            array_values($operators),
        );
    }
}
