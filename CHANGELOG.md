# Changelog

All notable changes to this bundle are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026.09.08

### Added

- A `native` processor, `QueryBuilderProcessor::Native`: the hidden input carries the tree as React Query Builder holds it, groups as `{combinator, not, rules}` and rules as `{field, operator, value}`, without the ids the editor keeps for itself, for an application that compiles the rules into a query on its own. The stored form folds like the other processors ("List of values" stored as `=`, `in` and `notIn` lists comma-separated, rules without a field or an operator and empty groups left out) and nothing is rewritten for evaluation: `regex` holds the pattern as typed, `null` and `notNull` on a `Number` field are stored as picked. An emptied group reaches the model as `null`, like an empty tree under the other processors. Reopening a saved value detects its shape, so a value saved under another processor is migrated at the next save. `ConditionTreeValidator::assertValid()` checks a native tree rule by rule, and `Operator::nativeOperator()` gives the name each operator is stored under.
- `Field.operators`: a field can declare its own ordered list of operators, as `Operator` cases or backing strings, in the DTO and in the array shape alike. The list replaces the `operators` option on that field and is taken as written, without the type compatibility filter, so a `Number` field can offer `in` and `notIn`. An empty list, a repeated or an unknown operator throws an `InvalidOptionsException` when the form is built. The server-side check holds each field to its own list, or to the `operators` option when it has none, so a list declared on one field opens nothing to another. `ConditionTreeValidator` takes the per-field lists as an optional third constructor argument. New `OperatorListNormalizer` service and `OperatorListNormalizationException`.
- The Stimulus controller follows changes of its `fields`, `operators` and `lang` values after render, written as `data-query-builder-*-value` attributes by another controller. The editor is rebuilt with the new settings and the query as it currently is. Rules on a field the new list no longer declares, or whose operator their field no longer offers, are dropped, and a group left empty with them. A `valuesList` rule whose field no longer offers that operator or no longer declares its value becomes the `=` it is stored as, when `=` is offered. The hidden input is then rewritten through the active processor, with the usual `input` and `change` events. Writing an attribute with the value the editor already has does nothing.
- Changing the `processor` value after render rewrites the hidden input in the new format without waiting for an edit, so a form reopened on a value saved under another processor is migrated on submit.
- Webpack Encore can load the controller: the bundle's `assets/` directory is added to the application's `package.json` as a path dependency and `@symfony/stimulus-bridge` picks the controller up, lazy loading included. The controller has no JSX, so the React preset of Encore is not needed. The README documents both setups.

### Changed

- `symfony/asset-mapper` is no longer required. It moved from `require` to `require-dev` and `suggest`, and the bundle registers its `assets/` directory with AssetMapper only when the package is installed. An application that relied on the bundle to pull AssetMapper in has to require `symfony/asset-mapper` itself.
- React Query Builder is pinned to 8.23.1 (`react-querybuilder`, `@react-querybuilder/core`, `@react-querybuilder/bootstrap` and `@react-querybuilder/dnd`, previously 8.14.0) and React to 19.2.8 (previously 19.2.4). The peer dependencies ask for `^8.23.1`. In the `symfony.importmap` block, `redux` 5.0.1 is pinned and the React entries come last: Symfony Flex requires the entries one by one, in file order, and `react-dnd` brings `redux` 4 and `react` 18 with it, so the entries it would overwrite have to come after it.

### Fixed

- Under the `parameterized` processor, an empty editor now writes `{}` to the hidden input, as the other processors do, instead of a `{"conditionTree": {}, "parameterizedSql": "(1 = 1)"}` object. The model transformer already read `null` from both, but the input value differed from the one the page was rendered with, and an `input` event fired for a form the user had not changed.
- Following the `importmap:require` command of the README gave an importmap where `dnd-core`, a dependency of React DnD, brought redux 4 in first while the `@reduxjs/toolkit` used by React Query Builder needs redux 5. The controller failed to load with "The requested module 'redux' does not provide an export named 'isAction'". The command now lists `redux`, which resolves it to the current major.

## [1.0.0] - 2026-09-04

### Added

- `QueryBuilderType`, a form type built on `HiddenType`. Its form theme wraps the hidden input in a `<div>` handled by the bundled `query-builder` Stimulus controller, which mounts [React Query Builder](https://react-querybuilder.js.org/) with its Bootstrap theme and drag-and-drop, and rewrites the input on every change. There is no Node build step and no dedicated endpoint. The theme renders the label, help text and errors of the field like any other row.
- The `fields` option, as `Field` objects or plain arrays: `name`, `type`, `label`, `labelInformation` and `values`. The `ValueType` enum covers `Text`, `Number`, `Date` and `Boolean`. A field with `values` gets a select of its declared values and the "List of values" operator. A missing or empty `name`, a duplicated `name` or an unknown `type` throws an `InvalidOptionsException` when the form is built.
- The `operators` option and the `Operator` enum, 21 operators including the bundle's own `regex`, `notRegex`, `<=ndays`, `>=ndays` and `valuesList`. The list is exhaustive and ordered, and type compatibility still applies.
- The `processor` option: `jsonLogic` (default) stores the [JsonLogic](https://jsonlogic.com/) tree, `parameterized` stores an object holding the tree under `conditionTree` and a parameterized SQL preview under `parameterizedSql`. An empty editor gives `null` under both.
- The `lang` option, defaulting to `kernel.default_locale`, with English and French labels bundled.
- A model transformer decoding the submitted JSON into a plain PHP array, or `null` when the builder is empty. Invalid JSON, a scalar or a list becomes a regular form error, not an exception.
- Server-side validation of the submitted tree against the declared `fields` and `operators`, on by default and switched off with `validate_condition_tree`. `ConditionTreeValidator` can also be used on its own and throws `ConditionTreeValidationException`. A refused tree becomes a form error and never reaches the model.
- Reopening a saved tree in the editor: regex delimiters are stripped and "List of values" rules are restored.
- `JsonLogicOperations`, registering into a PHP JsonLogic evaluator the seven operations the trees use beyond standard JsonLogic: `startsWith`, `endsWith`, `regex`, `notRegex`, `<=ndays`, `>=ndays` and `notNull`.
- Requirements: PHP 8.3 or later, Symfony 7.4, AssetMapper, Stimulus and Twig, and Bootstrap CSS on the pages that render the widget. Continuous integration runs PHPStan, Psalm and PHP-CS-Fixer.

[1.1.0]: https://github.com/openstudio-fr/query-builder-bundle/releases/tag/v1.1.0
[1.0.0]: https://github.com/openstudio-fr/query-builder-bundle/releases/tag/v1.0.0
