<?php
/**
 * The findings table on the Sister references report.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\SiteScan;

use RedC\SisterSync\Registry\Registry;
use RedC\SisterSync\Scanner\Finding;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class ReportTable extends \WP_List_Table {

	private const PER_PAGE = 50;

	/**
	 * @var array<string, string>
	 */
	private array $menuNames = array();

	public function __construct(
		private readonly ResultStore $store,
		private readonly Registry $registry,
		private readonly string $scanId,
		private readonly ReportFilters $filters
	) {
		parent::__construct(
			array(
				'singular' => 'sister-reference',
				'plural'   => 'sister-references',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'item'     => __( 'Item', 'sister-sync' ),
			'severity' => __( 'Severity', 'sister-sync' ),
			'sister'   => __( 'Points to', 'sister-sync' ),
			'url'      => __( 'URL', 'sister-sync' ),
			'location' => __( 'Where', 'sister-sync' ),
			'hits'     => __( 'Times', 'sister-sync' ),
		);
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'item'     => array( 'item', false ),
			'severity' => array( 'severity', false ),
			'sister'   => array( 'sister', false ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'item' );

		$result      = $this->store->query( $this->scanId, $this->filters, $this->get_pagenum(), self::PER_PAGE );
		$this->items = $result['rows'];
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	public function no_items(): void {
		esc_html_e( 'No sister links found.', 'sister-sync' );
	}

	/**
	 * Filter dropdowns and the CSV export link above the table.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="sister-sync-filter-sister"><?php esc_html_e( 'Filter by sister site', 'sister-sync' ); ?></label>
			<select name="sister" id="sister-sync-filter-sister">
				<option value=""><?php esc_html_e( 'All sister sites', 'sister-sync' ); ?></option>
				<?php foreach ( $this->store->distinctSisters( $this->scanId ) as $id ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $this->filters->sister, $id ); ?>><?php echo esc_html( $this->registry->get( $id )?->label ?? $id ); ?></option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="sister-sync-filter-severity"><?php esc_html_e( 'Filter by severity', 'sister-sync' ); ?></label>
			<select name="severity" id="sister-sync-filter-severity">
				<option value=""><?php esc_html_e( 'All severities', 'sister-sync' ); ?></option>
				<option value="<?php echo esc_attr( Finding::ERROR ); ?>" <?php selected( $this->filters->severity, Finding::ERROR ); ?>><?php esc_html_e( 'Errors', 'sister-sync' ); ?></option>
				<option value="<?php echo esc_attr( Finding::WARNING ); ?>" <?php selected( $this->filters->severity, Finding::WARNING ); ?>><?php esc_html_e( 'Warnings', 'sister-sync' ); ?></option>
			</select>

			<label class="screen-reader-text" for="sister-sync-filter-type"><?php esc_html_e( 'Filter by type', 'sister-sync' ); ?></label>
			<select name="type" id="sister-sync-filter-type">
				<option value=""><?php esc_html_e( 'All types', 'sister-sync' ); ?></option>
				<?php foreach ( $this->typeOptions() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $this->filters->type, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<?php submit_button( __( 'Filter', 'sister-sync' ), '', 'filter_action', false ); ?>

			<a class="button" href="<?php echo esc_url( ReportPage::exportUrl( $this->filters ) ); ?>"><?php esc_html_e( 'Export CSV', 'sister-sync' ); ?></a>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function column_item( $item ): string {
		$title = esc_html( (string) $item['object_title'] );
		$link  = self::editLink( $item );
		return sprintf(
			'<strong>%1$s</strong><br><span class="description">%2$s</span>',
			null !== $link ? sprintf( '<a href="%s">%s</a>', esc_url( $link ), $title ) : $title,
			esc_html( $this->typeLabel( $item ) )
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function column_severity( $item ): string {
		$isError = Finding::ERROR === $item['severity'];
		return sprintf(
			'<span style="color:%1$s;font-weight:600;">%2$s</span>',
			$isError ? '#d63638' : '#996800',
			esc_html( $isError ? __( 'Error', 'sister-sync' ) : __( 'Warning', 'sister-sync' ) )
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function column_sister( $item ): string {
		$site = $this->registry->get( (string) $item['sister_id'] );
		return sprintf(
			'<span style="background:%1$s;color:#fff;padding:1px 6px;border-radius:3px;white-space:nowrap;">%2$s</span>',
			esc_attr( $site?->colour() ?? '#787c82' ),
			esc_html( $site?->label ?? (string) $item['sister_id'] )
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	protected function column_url( $item ): string {
		return '<code style="word-break:break-all;">' . esc_html( (string) $item['url'] ) . '</code>';
	}

	/**
	 * @param array<string, mixed> $item
	 * @param string               $column_name
	 */
	protected function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * Where to fix a finding, when there is a screen for it.
	 *
	 * @param array<string, mixed> $item
	 */
	public static function editLink( array $item ): ?string {
		$id = (int) $item['object_id'];
		return match ( (string) $item['object_type'] ) {
			ScannedObject::POST      => current_user_can( 'edit_post', $id ) ? get_edit_post_link( $id, 'raw' ) : null,
			ScannedObject::MENU_ITEM => current_user_can( 'edit_theme_options' ) ? admin_url( 'nav-menus.php?action=edit&menu=' . (int) $item['object_subtype'] ) : null,
			ScannedObject::WIDGET    => current_user_can( 'edit_theme_options' ) ? admin_url( 'widgets.php' ) : null,
			ScannedObject::THEME_MOD => current_user_can( 'customize' ) ? admin_url( 'customize.php' ) : null,
			default                  => null,
		};
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function typeLabel( array $item ): string {
		$subtype = (string) $item['object_subtype'];
		return match ( (string) $item['object_type'] ) {
			ScannedObject::POST      => self::postTypeLabel( $subtype ),
			/* translators: %s: menu name. */
			ScannedObject::MENU_ITEM => sprintf( __( 'Menu item in "%s"', 'sister-sync' ), $this->menuName( $subtype ) ),
			/* translators: %s: widget type. */
			ScannedObject::WIDGET    => sprintf( __( 'Widget (%s)', 'sister-sync' ), $subtype ),
			ScannedObject::OPTION    => __( 'ACF options page', 'sister-sync' ),
			ScannedObject::THEME_MOD => __( 'Theme setting', 'sister-sync' ),
			default                  => (string) $item['object_type'],
		};
	}

	/**
	 * @return array<string, string> Filter value => label.
	 */
	private function typeOptions(): array {
		$options = array();
		foreach ( $this->store->distinctTypes( $this->scanId ) as $row ) {
			if ( ScannedObject::POST === $row['value'] ) {
				$options[ 'post:' . $row['subtype'] ] = self::postTypeLabel( (string) $row['subtype'] );
			} else {
				$options[ (string) $row['value'] ] = $this->typeLabel(
					array(
						'object_type'    => $row['value'],
						'object_subtype' => '',
					)
				);
			}
		}
		// Menu items and widgets have one entry per menu or widget type; collapse them.
		$options[ ScannedObject::MENU_ITEM ] = isset( $options[ ScannedObject::MENU_ITEM ] ) ? __( 'Menu items', 'sister-sync' ) : null;
		$options[ ScannedObject::WIDGET ]    = isset( $options[ ScannedObject::WIDGET ] ) ? __( 'Widgets', 'sister-sync' ) : null;
		return array_filter( $options );
	}

	private static function postTypeLabel( string $postType ): string {
		$object = get_post_type_object( $postType );
		return $object ? (string) $object->labels->singular_name : $postType;
	}

	private function menuName( string $termId ): string {
		if ( ! isset( $this->menuNames[ $termId ] ) ) {
			$menu                       = wp_get_nav_menu_object( (int) $termId );
			$this->menuNames[ $termId ] = $menu ? (string) $menu->name : $termId;
		}
		return $this->menuNames[ $termId ];
	}
}
