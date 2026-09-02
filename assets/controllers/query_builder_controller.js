import { Controller } from "@hotwired/stimulus";

import * as React from "react";
import { createRoot } from "react-dom/client";
import { QueryBuilderDnD } from '@react-querybuilder/dnd';
import * as ReactDnD from 'react-dnd';
import * as ReactDndHtml5Backend from 'react-dnd-html5-backend';
import * as ReactDndTouchBackend from 'react-dnd-touch-backend';
import { defaultCombinators, defaultOperators, defaultRuleProcessorJsonLogic, defaultRuleProcessorParameterized, defaultTranslations } from "react-querybuilder";
import { formatQuery, QueryBuilder } from "react-querybuilder";
import { QueryBuilderBootstrap } from "@react-querybuilder/bootstrap";
import { parseJsonLogic } from "react-querybuilder/parseJsonLogic";
import("react-querybuilder/dist/query-builder.css");

/**
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    #root;

    // List of fields with type 'date' to handle custom date operators (<=ndays and >=ndays).
    #fieldsDate = [];

    // List of fields with type 'number' to handle custom 'null' operator.
    #fieldsNumber = [];

    // Values declared per field, to reopen a stored equality as the 'valuesList' operator it was.
    #fieldsValues = {};

    // List of operators available (by default before filtering).
    #operators = [
        '=',
        'null',
        'contains', 'doesNotContain', 'beginsWith', 'endsWith',
        '>', '>=', '<', '<=',
        'in',
    ];

    // Whether the 'operators' form option replaced the default list (custom operators then require an explicit opt-in).
    #operatorsCustomized = false;

    // Lang used when the requested lang has no labels.
    #fallbackLang = 'en';

    #labelsByLang = {
        'en': {
            fields: {
                title: 'Fields',
            },
            operators: {
                title: 'Operators',
                '=': 'is equal to',
                '!=': 'is not equal to',
                '<': 'is less than',
                '>': 'is greater than',
                '<=': 'is less than or equal to',
                '>=': 'is greater than or equal to',
                between: 'between',
                notBetween: 'not between',
                contains: 'contains',
                doesNotContain: 'does not contain',
                beginsWith: 'begins with',
                endsWith: 'ends with',
                in: 'in',
                notIn: 'not in',
                null: 'is empty',
                notNull: 'is not empty',
                regex: 'matches the regular expression',
                notRegex: 'does not match the regular expression',
                '<=ndays': 'is less than or equal to N day(s)',
                '>=ndays': 'is greater than or equal to N day(s)',
                valuesList: 'List of values',
            },
            value: { title: 'Value' },
            addRule: { label: '+ Rule', title: 'Add a rule' },
            removeRule: { label: '⨯', title: 'Remove the rule' },
            addGroup: { label: '+ Group', title: 'Add a group' },
            removeGroup: { label: '⨯', title: 'Remove the group' },
            and: 'AND',
            or: 'OR',
            not: 'NOT',
            validationError: 'This rule is incomplete',
        },
        'fr': {
            fields: {
                title: 'Champs',
            },
            operators: {
                title: 'Opérateurs',
                '=': 'est égal à',
                '!=': "n'est pas égal à",
                '<': 'est inférieur à',
                '>': 'est supérieur à',
                '<=': 'est inférieur ou égal à',
                '>=': 'est supérieur ou égal à',
                between: 'entre',
                notBetween: 'n\'est pas entre',
                contains: 'contient',
                doesNotContain: 'ne contient pas',
                beginsWith: 'commence par',
                endsWith: 'se termine par',
                in: 'dans',
                notIn: 'pas dans',
                null: 'est vide',
                notNull: 'n\'est pas vide',
                regex: 'correspond à l\'expression régulière',
                notRegex: 'ne correspond pas à l\'expression régulière',
                '<=ndays': 'est inférieure ou égale à N jour(s)',
                '>=ndays': 'est supérieure ou égale à N jour(s)',
                valuesList: 'Liste de valeurs',
            },
            value: { title: 'Valeur' },
            addRule: { label: '+ Règle', title: 'Ajouter une règle' },
            removeRule: { label: '⨯', title: 'Supprimer la règle' },
            addGroup: { label: '+ Groupe', title: 'Ajouter un groupe' },
            removeGroup: { label: '⨯', title: 'Supprimer le groupe' },
            and: 'ET',
            or: 'OU',
            not: 'NON',
            validationError: 'Cette règle est incomplète',
        },
    };

    static values = {
        conditions: Object,
        fields: Array,
        operators: Array,
        processor: String,
        lang: { type: String, default: 'fr' },
    };

    static targets = ["queryBuilder", "input"];

    connect() {
        if (this.operatorsValue.length > 0) {
            this.#operators = this.operatorsValue;
            this.#operatorsCustomized = true;
        }
        this.#fieldsDate = [];
        this.#fieldsNumber = [];
        this.#fieldsValues = {};
        let fields = [];
        for (const fieldConfig of this.fieldsValue) {
            fields.push({
                name: fieldConfig.name,
                label: this.#makeFieldLabel(fieldConfig),
                ...this.#setFieldType(fieldConfig),
                ...this.#setFieldValues(fieldConfig),
            });
        }
        fields.sort((a, b) => a.label.localeCompare(b.label, this.langValue));
        const onQueryChange = (query) => {
            let ruleProcessor = this.#getRuleProcessor();
            const queryFormatted = formatQuery(query, {
                format: "jsonlogic",
                ruleProcessor,
            });

            // Handle jsonLogic input value (only jsonLogic tree).
            // An empty builder formats to false: store an empty object, never the JSON scalar '""',
            // which the 'conditions' Object value would refuse to parse when the form is
            // re-rendered after a failed validation, leaving the editor unmounted.
            if (this.processorValue === 'jsonLogic') {
                this.#updateInputValue(JSON.stringify(false === queryFormatted ? {} : queryFormatted));
            }

            // Handle parameterized SQL input value (keep jsonLogic tree and add SQL and params)
            if (this.processorValue === 'parameterized') {
                ruleProcessor = this.#getParameterizedRuleProcessor();
                const parameterizedQueryFormatted = formatQuery(query, {
                    format: 'parameterized',
                    ruleProcessor,
                });
                const inputValue = {
                    // Same normalization as above, so both processors agree on what "empty" is.
                    conditionTree: false === queryFormatted ? {} : queryFormatted,
                    parameterizedSql: parameterizedQueryFormatted,
                };
                this.#updateInputValue(JSON.stringify(inputValue));
            }
        };

        const queryBuilder = React.createElement(QueryBuilder,{
            fields,
            onQueryChange,
            defaultQuery: this.#getDefaultQuery(),
            combinators: this.#getCombinators(),
            getOperators: this.#getOperators(),
            getValueEditorType: this.#getValueEditorType(),
            getInputType: this.#getInputType(),
            translations: this.#getTranslations(),
            autoSelectField: false,
            autoSelectValue: false,
            listsAsArrays: false,
            enableDragAndDrop: true,
            enableMountQueryChange: false,
            controlClassnames: { queryBuilder: 'queryBuilder-branches' },
        });
        this.#root = createRoot(this.queryBuilderTarget);
        this.#root.render(
            React.createElement(QueryBuilderDnD, {
                dnd: { ...ReactDnD, ...ReactDndHtml5Backend, ...ReactDndTouchBackend },
                children: [React.createElement(QueryBuilderBootstrap, {}, queryBuilder)],
            }),
        );
    }
    disconnect() {
        this.#root?.unmount();
        this.#root = null;
    }

    // Guarded so the initial render does not fire a spurious event (e.g. form dirty trackers).
    #updateInputValue(value) {
        if (this.inputTarget.value === value) {
            return;
        }
        this.inputTarget.value = value;
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    #makeFieldLabel(fieldConfig) {
        let label = fieldConfig.label ?? fieldConfig.name;
        if (fieldConfig.labelInformation) {
            label += `     (${fieldConfig.labelInformation})`;
        }
        return label;
    }

    #setFieldType(fieldConfig) {
        if (fieldConfig.type === 'date') {
            // Memorize date fields to handle custom date operators (<=ndays and >=ndays) in the rule processor.
            this.#fieldsDate.push(fieldConfig.name);
        }
        if (fieldConfig.type === 'number') {
            // Memorize number fields to handle custom 'null' operator in the rule processor.
            this.#fieldsNumber.push(fieldConfig.name);
        }
        // These four are the whole of the ValueType enum, and the form option refuses anything else
        // server-side, so the text fallback only covers a controller fed by hand.
        switch (fieldConfig.type) {
            case 'date': return this.#setFieldTypeDate();
            case 'number': return this.#setFieldTypeNumber();
            case 'boolean': return this.#setFieldTypeBoolean();
            default: return this.#setFieldTypeText();
        };
    }

    #setFieldTypeText() {
        return {
            inputType: 'text',
            datatype: 'string',
        };
    }
    #setFieldTypeDate() {
        return {
            // 'inputType' is managed in 'getInputType' to handle the custom date operators (<=ndays and >=ndays)
            datatype: 'date',
        };
    }
    #setFieldTypeNumber() {
        return {
            inputType: 'number',
            datatype: 'number',
        };
    }
    #setFieldTypeBoolean() {
        return {
            inputType: 'boolean',
            datatype: 'boolean',
            valueEditorType: 'checkbox',
            defaultValue: false,
        };
    }
    #setFieldValues(fieldConfig) {
        // An empty list is not a list of values: it would still declare the field as a select,
        // offering a 'List of values' operator whose editor holds nothing but its placeholder —
        // a rule the user can pick and never complete.
        if (!fieldConfig.values?.length) {
            return {};
        }
        const values = fieldConfig.values.map((value) => ({ name: value.value ?? value.name, label: value.label ?? value.name }));
        // Memorize what a 'valuesList' rule on this field can hold, to reopen it as such.
        this.#fieldsValues[fieldConfig.name] = values.map((value) => value.name);
        return { values };
    }

    #getCombinators() {
        return defaultCombinators.map(combinator => {
            if (combinator.name === 'and') {
                return { ...combinator, label: this.#getLabelTranslation('and') };
            }
            if (combinator.name === 'or') {
                return { ...combinator, label: this.#getLabelTranslation('or') };
            }
            return combinator;
        });
    }

    #getOperators() {
        return (fieldName, { fieldData }) => {
            let operators = defaultOperators.filter(
                (op) => this.#operators.includes(op.name)
            );
            // Reorder operators list.
            operators.sort((a, b) => {
                const indexA = this.#operators.indexOf(a.name);
                const indexB = this.#operators.indexOf(b.name);
                return indexA - indexB;
            });
            // Translate operator labels
            operators = operators.map((op) => {
                return { ...op, label: this.#makeOperatorLabel(op.name, op.label) };
            });
            // Remove '>', '>=', '<', '<=' operators for not number or date fields.
            if (!['number', 'date'].includes(fieldData.datatype)) {
                operators = operators.filter((op) => !['>', '>=', '<', '<='].includes(op.name));
            }
            // 'contains', 'doesNotContain', 'beginsWith', 'endsWith' and 'in' operators only for text fields (string).
            if (fieldData.datatype !== 'string') {
                operators = operators.filter((op) => !['contains', 'doesNotContain', 'beginsWith', 'endsWith', 'in'].includes(op.name));
            }
            // Add the ‘regex’ and ‘notRegex’ operators for text fields.
            if (fieldData.inputType === 'text') {
                if (this.#isOperatorActive('regex')) {
                    operators.push({ name: 'regex', label: this.#makeOperatorLabel('regex'), value: 'regex' });
                }
                if (this.#isOperatorActive('notRegex')) {
                    operators.push({ name: 'notRegex', label: this.#makeOperatorLabel('notRegex'), value: 'notRegex' });
                }
            }
            // Add the ‘ndays’ operators for date fields.
            if (fieldData.datatype === 'date') {
                if (this.#isOperatorActive('<=ndays')) {
                    operators.push({ name: '<=ndays', label: this.#makeOperatorLabel('<=ndays'), value: '<=ndays' });
                }
                if (this.#isOperatorActive('>=ndays')) {
                    operators.push({ name: '>=ndays', label: this.#makeOperatorLabel('>=ndays'), value: '>=ndays' });
                }
            }
            // Add the ‘valuesList’ operator if the field contains a list of values.
            if (fieldName && fieldData.values?.length && this.#isOperatorActive('valuesList')) {
                operators.push({ name: 'valuesList', label: this.#makeOperatorLabel('valuesList'), value: 'valuesList' });
            }
            // A customized operators list also drives the display order, custom operators included.
            if (this.#operatorsCustomized) {
                operators.sort((a, b) => this.#operators.indexOf(a.name) - this.#operators.indexOf(b.name));
            }

            return operators;
        };
    }

    // Custom operators (regex, notRegex, ndays, valuesList) are active by default;
    // once the 'operators' form option is set, they must be listed there.
    #isOperatorActive(name) {
        return !this.#operatorsCustomized || this.#operators.includes(name);
    }

    #makeOperatorLabel(name, fallbackLabel = null) {
        const label = this.#getLabelTranslation('operators')[name] || fallbackLabel || name;
        return `${label}   ( ${name} )`;
    }

    #getValueEditorType() {
        return (field, operator) => {
            switch (operator) {
                case 'valuesList':
                    return 'select';
                case 'in':
                case 'notIn':
                    return 'textarea';
                default:
                    return 'text';
            }
        };
    }

    #getInputType() {
        return (field, operator, { fieldData }) => {
            if (fieldData.datatype && fieldData.datatype === 'date') {
                return ['<=ndays', '>=ndays'].includes(operator) ? 'number' : 'date';
            }
            return fieldData.inputType || 'text';
        };
    }

    #getRuleProcessor() {
        return (rule, opts) => {
            // Replace 'valuesList' operator with equality check
            if (rule.operator === 'valuesList') {
                return { ['==']: [{ var: rule.field }, rule.value]};
            }
            // Handle 'regex' and 'notRegex' operators. The tree always carries a delimited pattern,
            // in both processors: it is JsonLogic, evaluated by the 'regex' operation this bundle
            // ships, and a bare pattern makes preg_match fail. The SQL parameter is built from the
            // raw value by the parameterized processor, which is where MySQL's delimiter-less
            // REGEXP is served.
            if (['regex', 'notRegex'].includes(rule.operator)) {
                return {[rule.operator]: [{ var: rule.field }, this.#delimitRegexPattern(rule.value)]};
            }
            // Handle '<=ndays' and '>=ndays' operators. They carry their own JsonLogic operation,
            // declared back in #getDefaultQuery, and hinge on the operator alone: keying them on
            // the field type would drop a stored rule as soon as its field leaves the 'fields'
            // option, silently, on the next save.
            if (['<=ndays', '>=ndays'].includes(rule.operator)) {
                // Set empty string if the value is not a number (e.g. date string AAAA-MM-JJ)
                const value = isNaN(rule.value) ? '' : rule.value;
                return {[rule.operator]: [{ var: rule.field }, value]};
            }
            // Any other operator on a date field keeps its value checked, but goes through the
            // library so the emitted operation is valid JsonLogic ('=' becomes '==', 'null'
            // becomes an equality against null) and can be parsed back when the form reopens.
            if (this.#fieldsDate.includes(rule.field)) {
                rule = {
                    ...rule,
                    value: this.#normalizeDateValue(rule),
                };
            }
            // Replace newlines with commas for the 'in' and 'notIn' operators: both get a
            // textarea with one value per line, and the library splits its lists on commas.
            if (['in', 'notIn'].includes(rule.operator) && typeof rule.value === 'string') {
                rule = {
                    ...rule,
                    value: rule.value.replaceAll(/\n/g, ','),
                };
            }
            // On a number field, 'is empty' also covers 0 (documented in the README).
            if (rule.operator === 'null' && this.#fieldsNumber.includes(rule.field)) {
                rule = {
                    ...rule,
                    operator: 'in',
                    value: [null, 0],
                };
            }
            // 'is not empty' is its complement, or a 0 would satisfy both operators at once.
            // Emitted as its own operation, declared back in #getDefaultQuery: the standard
            // {"!": {"in": ...}} would reopen as a negated group holding an 'is empty' rule, and
            // since the NOT toggle is hidden the editor would then display the opposite meaning.
            if (rule.operator === 'notNull' && this.#fieldsNumber.includes(rule.field)) {
                return {'notNull': [{ var: rule.field }, [null, 0]]};
            }
            return defaultRuleProcessorJsonLogic(rule, opts);
        };
    };

    // Keeps an incomplete date out of the tree: the library would otherwise serialize whatever
    // the editor held. 'between' carries both bounds in a single comma-separated value.
    #normalizeDateValue(rule) {
        const isDate = (value) => /^\d{4}-\d{2}-\d{2}$/.test(value);
        if (['between', 'notBetween'].includes(rule.operator)) {
            const bounds = String(rule.value).split(',');
            return bounds.length === 2 && bounds.every(isDate) ? rule.value : '';
        }
        return isDate(rule.value) ? rule.value : '';
    }

    #getParameterizedRuleProcessor() {
        return (rule, opts) => {
            // On a number field, 'is empty' also covers 0, like the jsonLogic processor and as the
            // README states. The fragment is written out rather than delegated: "field in (null, 0)"
            // never matches NULL in SQL. Stays above the generic 'null' branch, which returns first.
            if (rule.operator === 'null' && this.#fieldsNumber.includes(rule.field)) {
                return {
                    sql: '( '+rule.field+' is null or '+rule.field+' = ? )',
                    params: [0]
                };
            }
            // Complement of the branch above, so a 0 is never both empty and not empty.
            if (rule.operator === 'notNull' && this.#fieldsNumber.includes(rule.field)) {
                return {
                    sql: '( '+rule.field+' is not null and '+rule.field+' <> ? )',
                    params: [0]
                };
            }
            // Handle 'null' operator
            if (rule.operator === 'null') {
                return {
                    sql: '( '+rule.field+' is null )',
                    operator: '',
                    params: null
                }
            }
            // Replace 'valuesList' operator with equality check
            if (rule.operator === 'valuesList') {
                return {
                    sql: '( '+rule.field+' = ? )',
                    params: [rule.value]
                }
            }
            // Handle 'regex' operator
            if ('regex' === rule.operator) {
                // Create a case-insensitive regex
                return {
                    sql: '( lower('+rule.field+') regexp lower(?) )',
                    params: [rule.value]
                };
            }
            // Handle 'notRegex' operator
            if ('notRegex' === rule.operator) {
                // Create a case-insensitive regex
                return {
                    sql: '( lower('+rule.field+') not regexp lower(?) )',
                    params: [rule.value]
                };
            }
            // Handle '<=ndays' and '>=ndays' operators. Keyed on the operator alone, like the
            // jsonLogic processor, so the rule is never dropped when its field is no longer
            // declared as a date.
            if (['<=ndays', '>=ndays'].includes(rule.operator)) {
                rule = {
                    ...rule,
                    field: 'datediff( curdate(), '+rule.field+' )',
                    operator: rule.operator.replace('ndays', '')
                };
            }
            // Replace newlines with commas for the 'in' and 'notIn' operators: both get a
            // textarea with one value per line, and the library splits its lists on commas.
            if (['in', 'notIn'].includes(rule.operator) && typeof rule.value === 'string') {
                rule = {
                    ...rule,
                    value: rule.value.replaceAll(/\n/g, ','),
                };
            }
            // Handle 'contains' and 'doesNotContain' operators with a like %value%
            if (['contains', 'doesNotContain'].includes(rule.operator)) {
                const sqlOperator = rule.operator === 'contains' ? 'like' : 'not like';
                return {
                    sql: '( '+rule.field+' '+sqlOperator+' ? )',
                    params: [`%${rule.value}%`]
                };
            }

            const base = defaultRuleProcessorParameterized(rule, opts);

            // An empty sql is how the library signals a rule it cannot express, an 'in' with no
            // value for instance. Wrapping it would emit a literal '(  )' and break the statement,
            // so it is passed through for formatQuery to drop the rule.
            if (!base || !base.sql) {
                return base;
            }

            return {
                sql: '( '+base.sql+' )',
                params: base.params,
            };
        };
    }

    #getDefaultQuery() {
        const isNullValueNumber = (value) => Array.isArray(value) && value.length === 2 && [null, 0].every(v => value.includes(v));
        const getFieldInOperation = (val) => {
            if (val[1].var && typeof(val[0]) === 'string') {
                return val[1].var;
            }
            return val[0].var;
        };
        const getOperatorInOperation = function(val) {
            if (val[1].var && typeof(val[0]) === 'string') {
                return 'contains';
            }
            if (isNullValueNumber(val[1])) {
                return 'null';
            }
            return 'in';
        };
        const getValueInOperation = function(val) {
            if (val[1].var && typeof(val[0]) === 'string') {
                return val[0];
            }
            if (isNullValueNumber(val[1])) {
                return null;
            }
            return convertCommaToNewline(val[1]);
        }
        const convertCommaToNewline = (value) => Array.isArray(value) ? value.join('\n') : value;

        // The 'parameterized' processor wraps the tree next to the SQL; every other stored value IS
        // the tree. Detected on the key, not on its truthiness: a tree stored as false or null (an
        // editor emptied before that was normalized to an empty object) would otherwise hand
        // parseJsonLogic the wrapper itself, which is not a JsonLogic node.
        const conditions = (null !== this.conditionsValue && 'object' === typeof this.conditionsValue)
            ? this.conditionsValue
            : {};
        const conditionTree = 'conditionTree' in conditions ? conditions.conditionTree : conditions;
        return this.#restoreReopenedRules(parseJsonLogic(conditionTree, {
            jsonLogicOperations: {
                in: (val) => ({
                    field: getFieldInOperation(val),
                    operator: getOperatorInOperation(val),
                    value: getValueInOperation(val),
                }),
                notIn: (val) => ({
                    field: val[0].var,
                    operator: 'notIn',
                    value: convertCommaToNewline(val[1]),
                }),
                notNull: (val) => ({
                    field: val[0].var,
                    operator: 'notNull',
                    value: '',
                }),
                regex: (val) => ({
                    field: val[0].var,
                    operator: 'regex',
                    value: this.#stripRegexDelimiters(val[1]),
                }),
                notRegex: (val) => ({
                    field: val[0].var,
                    operator: 'notRegex',
                    value: this.#stripRegexDelimiters(val[1]),
                }),
                '<=ndays': (val) => ({
                    field: val[0].var,
                    operator: '<=ndays',
                    value: val[1],
                }),
                '>=ndays': (val) => ({
                    field: val[0].var,
                    operator: '>=ndays',
                    value: val[1],
                }),
            }
        }));
    }

    // The tree is evaluated as JsonLogic, so a pattern needs its PCRE delimiters. A value that
    // already reads as one well-formed /pattern/flags — every inner slash escaped, nothing but
    // flags after the closing delimiter — was delimited by hand and is kept as typed. Anything
    // else is a raw body: its bare slashes are escaped first (and a trailing lone backslash is
    // doubled), because wrapping 'a/b' as-is would store '/a/b/iu', a pattern preg_match cannot
    // compile — matchesRegex() then reads as "never matches" while the SQL, built from the raw
    // value, does match.
    #delimitRegexPattern(value) {
        const raw = 'string' === typeof value ? value : String(value ?? '');
        if (/^\/(?:\\.|[^\\/])*\/[iums]*$/s.test(raw)) {
            return raw;
        }
        const escaped = raw.replace(/\\.|\/|\\$/gs, (piece) => {
            if ('/' === piece) {
                return '\\/';
            }
            if ('\\' === piece) {
                return '\\\\';
            }
            return piece;
        });
        return `/${escaped}/iu`;
    }

    // Inverse of what #delimitRegexPattern adds, so the editor shows what the user typed: the
    // exact '/…/iu' wrapping comes off and the escaped slashes come back. A pattern the user
    // delimited themselves (other flags, other shape) does not match and reaches them untouched,
    // which keeps the tree identical on the next save.
    #stripRegexDelimiters(value) {
        if ('string' !== typeof value) {
            return value;
        }
        const delimited = /^\/(.*)\/iu$/s.exec(value);
        if (null === delimited) {
            return value;
        }
        return delimited[1].replace(/\\./gs, (piece) => ('\\/' === piece ? '/' : piece));
    }

    // Undoes what saving folded into the tree, rule by rule. 'valuesList' is stored as a plain
    // equality, so the library reopens it as '=' with a text input, losing the select the value
    // was picked in — the equality means the same thing either way, and when the field declares
    // that very value among its own, the select is the right editor. A 'notIn' list is stored
    // with the textarea's newlines folded into commas, so they are folded back, as the 'in'
    // parse handler already does for its own operator.
    #restoreReopenedRules(query) {
        if (!query || !Array.isArray(query.rules)) {
            return query;
        }
        return {
            ...query,
            rules: query.rules.map((rule) => {
                // A nested group. Anything else without an operator (the string of an independent
                // combinator, for instance) falls through untouched.
                if (rule && Array.isArray(rule.rules)) {
                    return this.#restoreReopenedRules(rule);
                }
                if (rule && 'notIn' === rule.operator && 'string' === typeof rule.value) {
                    return { ...rule, value: rule.value.replaceAll(',', '\n') };
                }
                if (rule && '=' === rule.operator
                    && this.#isOperatorActive('valuesList')
                    && (this.#fieldsValues[rule.field] ?? []).includes(rule.value)) {
                    return { ...rule, operator: 'valuesList' };
                }
                return rule;
            }),
        };
    }

    #getTranslations() {
        return {
            ...defaultTranslations,
            ...this.#getLabelsTranslation([
                'fields', 'operators', 'value', 'addRule', 'removeRule', 'addGroup', 'removeGroup',
            ]),
        };
    }

    #getLabelsTranslation(fields = null) {
        const labelsByLang = this.#labelsByLang[this.langValue] ?? this.#labelsByLang[this.#fallbackLang];
        // If no specific fields are requested, return all translations for the language
        if (!fields) {
            return labelsByLang;
        }
        // Otherwise, return only the requested fields
        let labels = {};
        fields.forEach((field) => {
            labels[field] = labelsByLang[field] || field;
        });
        return labels;
    }

    #getLabelTranslation(field) {
        const labelTranslation = this.#getLabelsTranslation([field]);
        return labelTranslation[field] || field;
    }
}
