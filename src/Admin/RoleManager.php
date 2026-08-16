<?php
/**
 * Per-role access control for the plugin's own admin surfaces.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Admin;

use WP_User;

/**
 * Grants a set of virtual, plugin-only capabilities (seo_wp_*) per role via
 * the user_has_cap filter, rather than writing them into the persisted
 * wp_user_roles option — reversible with zero cleanup on uninstall, and
 * never conflicts with another plugin's own use of the same role objects.
 * Every other admin screen in this plugin checks one of these capabilities
 * instead of a hardcoded manage_options/edit_posts, so a change here takes
 * effect everywhere immediately. Until an admin ever saves a matrix here,
 * default_matrix() derives one from each role's real, existing
 * capabilities, so installing this feature never silently changes who can
 * already do what.
 */
final class RoleManager {

	/**
	 * Option key the configured matrix is stored under.
	 */
	private const OPTION_KEY = 'seo_wp_role_caps';

	/**
	 * Submenu page slug.
	 */
	private const PAGE_SLUG = 'seo-wp-roles';

	/**
	 * The capability that gates this page itself.
	 */
	private const MANAGE_ROLES_CAP = 'seo_wp_manage_roles';

	/**
	 * Nonce action/field name.
	 */
	private const NONCE_ACTION = 'seo_wp_role_manager';

	/**
	 * Nonce field name.
	 */
	private const NONCE_NAME = 'seo_wp_role_manager_nonce';

	/**
	 * Every controllable area: area key => virtual capability name.
	 *
	 * @var array<string,string>
	 */
	public const AREAS = array(
		'settings'     => 'seo_wp_manage_settings',
		'meta'         => 'seo_wp_edit_meta',
		'bulk_editor'  => 'seo_wp_bulk_edit',
		'import'       => 'seo_wp_import',
		'diagnostics'  => 'seo_wp_view_diagnostics',
		'redirects'    => 'seo_wp_manage_redirects',
		'404_monitor'  => 'seo_wp_view_404_monitor',
		'duplicates'   => 'seo_wp_view_duplicates',
		'link_audit'   => 'seo_wp_view_link_audit',
		'freshness'    => 'seo_wp_view_freshness',
		'role_manager' => self::MANAGE_ROLES_CAP,
	);

	/**
	 * Registers the capability filter, the submenu page and its save
	 * handler.
	 */
	public function register(): void {
		add_filter( 'user_has_cap', array( $this, 'grant_capabilities' ), 10, 4 );
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_seo_wp_role_manager_save', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Grants a requested seo_wp_* capability when at least one of the
	 * user's roles has that area enabled in the configured matrix.
	 *
	 * @param array<string,bool> $allcaps Capabilities already resolved for this user.
	 * @param string[]           $caps    Primitive caps being checked (unused; $args[0] is the original request).
	 * @param array<int,mixed>   $args    [0] is the originally requested capability.
	 * @param WP_User            $user    User being checked.
	 * @return array<string,bool>
	 */
	public function grant_capabilities( array $allcaps, array $caps, array $args, WP_User $user ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $caps is part of the user_has_cap filter signature; only $args[0] is needed here.
		$requested = isset( $args[0] ) ? (string) $args[0] : '';
		if ( ! in_array( $requested, self::AREAS, true ) ) {
			return $allcaps;
		}

		$matrix = self::matrix();

		// Role Manager itself, and the two newest areas (Link Audit,
		// Duplicate Checker), always follow whichever of these a role
		// already has: real manage_options, OR — just as trusted, and
		// the one that actually matters for a role managed entirely
		// through this plugin's own virtual system rather than real WP
		// capabilities — this plugin's own Settings area. A role a site
		// owner already trusts to configure SEO-WP is trusted with these
		// three too, full stop, without a per-area matrix lookup for
		// them specifically. That matters beyond first install: these
		// three are exactly the areas a matrix saved before they existed
		// in the UI could have on-disk as an *explicit* `false` (the
		// original bug rendered an unchecked box for a missing key, and
		// a save made *at that time* persists that false permanently —
		// matrix()'s own backfill only fills in a truly *missing* key,
		// so it can't undo an explicit one). $allcaps is what
		// user_has_cap() already resolved for this exact user before
		// this filter ran, so reading 'manage_options' from it here
		// can't recurse.
		$always_trusted_areas = array( self::MANAGE_ROLES_CAP, self::AREAS['link_audit'], self::AREAS['duplicates'] );
		if ( in_array( $requested, $always_trusted_areas, true ) ) {
			if ( ! empty( $allcaps['manage_options'] ) ) {
				$allcaps[ $requested ] = true;
				return $allcaps;
			}

			foreach ( (array) $user->roles as $role ) {
				if ( ! empty( $matrix[ $role ][ self::AREAS['settings'] ] ) ) {
					$allcaps[ $requested ] = true;
					return $allcaps;
				}
			}
		}

		foreach ( (array) $user->roles as $role ) {
			if ( ! empty( $matrix[ $role ][ $requested ] ) ) {
				$allcaps[ $requested ] = true;
				break;
			}
		}

		return $allcaps;
	}

	/**
	 * The currently effective matrix: the stored one, backfilled with the
	 * derived default for any role/area it doesn't have an entry for.
	 * That backfill matters beyond first install — every time this plugin
	 * adds a new controllable area (as it did for Duplicate Content and
	 * Link Audit after some sites had already saved a matrix), a
	 * previously-saved matrix predates that area entirely. Without the
	 * backfill, a missing entry reads as `false` — silently locking every
	 * role, including Administrator, out of a page that never existed
	 * when they last saved this screen.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function matrix(): array {
		$stored  = get_option( self::OPTION_KEY, null );
		$default = self::default_matrix();

		if ( ! is_array( $stored ) ) {
			return $default;
		}

		foreach ( $default as $role_slug => $caps ) {
			foreach ( $caps as $cap => $default_value ) {
				if ( ! isset( $stored[ $role_slug ][ $cap ] ) ) {
					$stored[ $role_slug ][ $cap ] = $default_value;
				}
			}
		}

		return $stored;
	}

	/**
	 * Builds a default matrix from each role's existing manage_options/
	 * edit_posts capabilities, so this feature is a no-op until an admin
	 * actively changes it.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private static function default_matrix(): array {
		$matrix = array();

		foreach ( wp_roles()->roles as $role_slug => $role_data ) {
			$role_caps          = isset( $role_data['capabilities'] ) && is_array( $role_data['capabilities'] ) ? $role_data['capabilities'] : array();
			$has_manage_options = ! empty( $role_caps['manage_options'] );
			$has_edit_posts     = ! empty( $role_caps['edit_posts'] );

			$matrix[ $role_slug ] = array(
				self::AREAS['settings']     => $has_manage_options,
				self::AREAS['meta']         => $has_edit_posts,
				self::AREAS['bulk_editor']  => $has_edit_posts,
				self::AREAS['import']       => $has_manage_options,
				self::AREAS['diagnostics']  => $has_manage_options,
				self::AREAS['redirects']    => $has_manage_options,
				self::AREAS['404_monitor']  => $has_manage_options,
				self::AREAS['duplicates']   => $has_manage_options,
				self::AREAS['link_audit']   => $has_manage_options,
				self::AREAS['freshness']    => $has_manage_options,
				self::AREAS['role_manager'] => $has_manage_options,
			);
		}

		return $matrix;
	}

	/**
	 * Adds the Role Manager submenu page. Registered with the virtual
	 * seo_wp_manage_roles capability — WordPress's own menu system uses
	 * this same parameter to decide whether the page is reachable at all
	 * (add_submenu_page() skips registering the hook name into
	 * $_registered_pages when the current user lacks it, which surfaces
	 * as the page simply not existing), so it has to go through
	 * grant_capabilities() the same way every other check here does.
	 * That filter's own "always trusted" rule (real manage_options, OR
	 * this role already trusted with Settings) is what actually
	 * guarantees this page is never lockable — not the capability string
	 * used here, which must stay virtual for a role managed entirely
	 * through this plugin's own system (no real manage_options at all)
	 * to reach it.
	 */
	public function add_menu(): void {
		// Register the page only for authorised users, but use the stable
		// primitive `read` capability for WordPress' page registry. Custom
		// virtual capabilities can otherwise cause admin.php to treat the
		// page as unregistered and return "not allowed"/404 before our
		// callback gets a chance to run.
		if ( ! current_user_can( self::MANAGE_ROLES_CAP ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_submenu_page(
			Settings::PAGE_SLUG,
			__( 'Role Manager', 'seo-wp' ),
			__( 'Role Manager', 'seo-wp' ),
			'read',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Loads the shared admin stylesheet on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'seo-wp-admin', plugins_url( 'assets/admin/admin.css', \SeoWp\FILE ), array(), \SeoWp\VERSION );
	}

	/**
	 * Human-readable labels for each area.
	 *
	 * @return array<string,string>
	 */
	private function area_labels(): array {
		return array(
			'settings'     => __( 'Settings', 'seo-wp' ),
			'meta'         => __( 'Edit SEO metabox fields', 'seo-wp' ),
			'bulk_editor'  => __( 'Bulk Editor', 'seo-wp' ),
			'import'       => __( 'Import', 'seo-wp' ),
			'diagnostics'  => __( 'Diagnostics', 'seo-wp' ),
			'redirects'    => __( 'Redirects', 'seo-wp' ),
			'404_monitor'  => __( '404 Monitor', 'seo-wp' ),
			'duplicates'   => __( 'Duplicate Content Checker', 'seo-wp' ),
			'link_audit'   => __( 'Link Audit (Broken Links / Orphan Pages)', 'seo-wp' ),
			'freshness'    => __( 'Content Freshness', 'seo-wp' ),
			'role_manager' => __( 'Role Manager', 'seo-wp' ),
		);
	}

	/**
	 * Renders the role manager page: one checkbox grid per role.
	 */
	public function render_page(): void {
		// Belt and suspenders: current_user_can( self::MANAGE_ROLES_CAP )
		// alone already resolves true for a manage_options user via
		// grant_capabilities()'s own bypass, but checking manage_options
		// again here directly means this check still passes even if that
		// filter somehow isn't registered or didn't run for this request.
		if ( ! current_user_can( self::MANAGE_ROLES_CAP ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$matrix = self::matrix();
		$labels = $this->area_labels();

		echo '<div class="wrap seo-wp-role-manager">';
		echo '<h1>' . esc_html__( 'SEO-WP Role Manager', 'seo-wp' ) . '</h1>';
		echo '<p>' . esc_html__( 'Control which roles can access which parts of SEO-WP. The Administrator role always keeps access to this page.', 'seo-wp' ) . '</p>';

		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success banner, not a state-changing action.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Roles saved.', 'seo-wp' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="seo_wp_role_manager_save" />';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		foreach ( wp_roles()->role_names as $role_slug => $role_name ) {
			$is_admin = 'administrator' === $role_slug;

			echo '<h2>' . esc_html( translate_user_role( $role_name ) ) . '</h2>';
			echo '<table class="form-table" role="presentation"><tbody><tr><td>';

			foreach ( self::AREAS as $area => $cap ) {
				$checked = $is_admin || ! empty( $matrix[ $role_slug ][ $cap ] );
				printf(
					'<label style="display:inline-block;margin:0 16px 8px 0;"><input type="checkbox" name="seo_wp_roles[%1$s][%2$s]" value="1" %3$s %4$s /> %5$s</label>',
					esc_attr( $role_slug ),
					esc_attr( $area ),
					checked( $checked, true, false ),
					disabled( $is_admin, true, false ),
					esc_html( $labels[ $area ] ?? $area )
				);
			}

			echo '</td></tr></tbody></table>';
		}

		submit_button( __( 'Save roles', 'seo-wp' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Handles the save form submission.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( self::MANAGE_ROLES_CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-wp' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$submitted = isset( $_POST['seo_wp_roles'] ) && is_array( $_POST['seo_wp_roles'] ) ? wp_unslash( $_POST['seo_wp_roles'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash() is called here; every value is reduced to a boolean below.

		$matrix = array();
		foreach ( array_keys( wp_roles()->roles ) as $role_slug ) {
			$role_input           = isset( $submitted[ $role_slug ] ) && is_array( $submitted[ $role_slug ] ) ? $submitted[ $role_slug ] : array();
			$matrix[ $role_slug ] = array();

			foreach ( self::AREAS as $area => $cap ) {
				$matrix[ $role_slug ][ $cap ] = ! empty( $role_input[ $area ] );
			}

			// The Administrator role can never lose access to this page —
			// otherwise a mistaken save could lock every admin out of it.
			if ( 'administrator' === $role_slug ) {
				$matrix[ $role_slug ][ self::MANAGE_ROLES_CAP ] = true;
			}
		}

		update_option( self::OPTION_KEY, $matrix, false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
