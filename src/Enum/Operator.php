<?php

declare(strict_types=1);

namespace OpenStudio\QueryBuilderBundle\Enum;

enum Operator: string
{
    case Equal = '=';
    case NotEqual = '!=';
    case LessThan = '<';
    case GreaterThan = '>';
    case LessThanOrEqual = '<=';
    case GreaterThanOrEqual = '>=';
    case Between = 'between';
    case NotBetween = 'notBetween';
    case Contains = 'contains';
    case DoesNotContain = 'doesNotContain';
    case BeginsWith = 'beginsWith';
    case EndsWith = 'endsWith';
    case In = 'in';
    case NotIn = 'notIn';
    case Null = 'null';
    case NotNull = 'notNull';
    case Regex = 'regex';
    case NotRegex = 'notRegex';
    case LessThanOrEqualNDays = '<=ndays';
    case GreaterThanOrEqualNDays = '>=ndays';
    case ValuesList = 'valuesList';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $operator): string => $operator->value, self::cases());
    }

    /**
     * The JsonLogic operation keys the editor can emit for this operator.
     *
     * Two sources are merged: what react-querybuilder emits by default, and the rewrites the
     * Stimulus controller applies (valuesList to an equality, "null" on a number field to an
     * inclusion, regex and ndays to their own operations).
     *
     * Negations are covered by the "!" combinator: "doesNotContain", "notIn" and "notBetween" are
     * emitted wrapped in it. Trees saved before the date branch was fixed can hold a raw operator
     * key ("=", "null", "between", "notBetween") — invalid JsonLogic the editor no longer produces,
     * and which this map deliberately rejects.
     *
     * "notNull" is the exception: on a number field it is a deliberate custom operation, so that
     * "is not empty" excludes 0 like "is empty" includes it, and reopens as one rule.
     *
     * @return list<string>
     */
    public function jsonLogicOperations(): array
    {
        return match ($this) {
            self::Equal, self::ValuesList => ['=='],
            self::NotEqual => ['!='],
            self::LessThan => ['<'],
            self::GreaterThan => ['>'],
            self::LessThanOrEqual => ['<='],
            self::GreaterThanOrEqual => ['>='],
            self::Between, self::NotBetween => ['<='],
            self::Contains, self::In, self::DoesNotContain, self::NotIn => ['in'],
            self::BeginsWith => ['startsWith'],
            self::EndsWith => ['endsWith'],
            self::Null => ['==', 'in'],
            self::NotNull => ['!=', 'notNull'],
            self::Regex => ['regex'],
            self::NotRegex => ['notRegex'],
            self::LessThanOrEqualNDays => ['<=ndays'],
            self::GreaterThanOrEqualNDays => ['>=ndays'],
        };
    }

    /**
     * The operator name a rule holds in the tree of the "native" processor.
     *
     * The editor's own name, with one exception: "valuesList" is stored as the equality it stands
     * for, as the two other processors already do, so a consumer compiling the tree never meets an
     * operator that only exists in this editor.
     */
    public function nativeOperator(): string
    {
        return match ($this) {
            self::ValuesList => self::Equal->value,
            default => $this->value,
        };
    }
}
