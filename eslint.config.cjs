/**
 * Extends the @wordpress/scripts defaults. @wordpress/* packages are supplied
 * by WordPress at runtime (webpack maps them to wp.* globals), so they are
 * not installed locally and the import rules must not expect them to be.
 */
const wpConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...wpConfig,
	{
		ignores: [ 'assets/classic-check.js' ],
	},
	{
		settings: {
			'import/core-modules': [
				'@wordpress/api-fetch',
				'@wordpress/components',
				'@wordpress/data',
				'@wordpress/editor',
				'@wordpress/edit-post',
				'@wordpress/element',
				'@wordpress/i18n',
				'@wordpress/plugins',
			],
		},
	},
];
