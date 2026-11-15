import { Flex } from '@wordpress/components';

/**
 * Vertical stack of form controls with the block editor spacing.
 *
 * On current WordPress (7.x) the core controls have no bottom margin, so the
 * parent sets the space between them (16px between fields, 12px between
 * the rows of a group).
 *
 * @param {Object} props           Component props.
 * @param {number} props.gap       Gap in 4px steps (default 4 = 16px).
 * @param {string} props.className Extra class names.
 * @param {*}      props.children  Controls.
 */
export function FormStack( { gap = 4, className = '', children } ) {
	return (
		<Flex
			direction="column"
			align="stretch"
			justify="flex-start"
			gap={ gap }
			className={ `quant-form-stack ${ className }`.trim() }
		>
			{ children }
		</Flex>
	);
}

/**
 * A labelled group of checkboxes or toggles, as a fieldset.
 *
 * @param {Object} props          Component props.
 * @param {string} props.label    Group label, styled as a control label.
 * @param {string} props.help     Help text under the label.
 * @param {*}      props.children Rows.
 */
export function FieldGroup( { label, help, children } ) {
	return (
		<fieldset className="quant-field-group">
			<legend className="quant-field-group__label">{ label }</legend>
			{ help && <p className="quant-field-group__help">{ help }</p> }
			<FormStack gap={ 3 }>{ children }</FormStack>
		</fieldset>
	);
}
