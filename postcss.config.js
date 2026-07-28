/**
 * Mirrors @wordpress/scripts' default PostCSS config, defined explicitly so
 * postcss-loader's config lookup resolves here instead of walking up past
 * this project (an unrelated parent-directory package.json is unreadable
 * in this environment and breaks the default upward search).
 */
const postcssPlugins = require('@wordpress/postcss-plugins-preset');

const isProduction = process.env.NODE_ENV === 'production';

module.exports = {
	plugins: isProduction
		? [
				...postcssPlugins,
				require('cssnano')({
					preset: [
						'default',
						{
							discardComments: {
								removeAll: true,
							},
						},
					],
				}),
		  ]
		: postcssPlugins,
};
