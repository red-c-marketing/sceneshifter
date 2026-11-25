<?php
/**
 * The thing a finding was found in: a post, a menu item, a widget, an option
 * or a theme setting.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SiteScan;

final class ScannedObject {

	public const POST      = 'post';
	public const MENU_ITEM = 'menu_item';
	public const WIDGET    = 'widget';
	public const OPTION    = 'option';
	public const THEME_MOD = 'theme_mod';

	/**
	 * @param string $type    One of the constants above.
	 * @param string $title   What to show in the report.
	 * @param int    $id      Post or menu item ID; 0 for options.
	 * @param string $key     Option name or theme mod key; '' for posts.
	 * @param string $subtype Post type, or menu name for menu items.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $title,
		public readonly int $id = 0,
		public readonly string $key = '',
		public readonly string $subtype = ''
	) {}
}
