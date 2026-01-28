<?php
/**
 * Plugin Name:       GatherPress Venue Hierarchy
 * Plugin URI:        https://github.com/automattic/gatherpress-venue-hierarchy
 * Description:       Adds hierarchical location taxonomy to GatherPress with automatic geocoding
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WordPress Telex
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gatherpress-venue-hierarchy
 *
 * @package GatherPressVenueHierarchy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class using Singleton pattern.
 *
 * **What:** Core plugin controller that manages the hierarchical location taxonomy system.
 *
 * **Why:** Provides a single point of initialization and coordination for all plugin functionality,
 * ensuring only one instance exists (Singleton pattern) to prevent duplicate registrations and
 * conflicts. This is critical for WordPress hooks and taxonomy registration.
 *
 * **How:** Registers WordPress hooks on instantiation, coordinates geocoding and hierarchy building
 * through specialized singleton classes (Geocoder and Hierarchy_Builder), and manages the custom
 * taxonomy lifecycle. Uses the Singleton pattern to guarantee single instantiation via
 * get_instance() static method and private constructor.
 *
 * @since 0.1.0
 */
class GatherPress_Venue_Hierarchy {
	
	/**
	 * Single instance of the class.
	 *
	 * **What:** Holds the single instance of this class.
	 *
	 * **Why:** Part of the Singleton pattern implementation to ensure only one instance
	 * exists throughout the WordPress request lifecycle.
	 *
	 * @since 0.1.0
	 * @var GatherPress_Venue_Hierarchy|null
	 */
	private static $instance = null;
	
	/**
	 * Taxonomy name for hierarchical locations.
	 *
	 * **What:** The slug identifier for the custom location taxonomy.
	 *
	 * **Why:** Centralized constant prevents typos and makes refactoring easier.
	 * This taxonomy stores geographical hierarchy (continent > country > state > city > street > street-number).
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $taxonomy = 'gatherpress-location';
	
	/**
	 * Get singleton instance.
	 *
	 * **What:** Returns the single instance of the class, creating it if necessary.
	 *
	 * **Why:** Ensures only one instance of the plugin class exists, preventing duplicate
	 * hook registrations and taxonomy definitions that could cause WordPress conflicts.
	 *
	 * **How:** Checks if instance exists in static property; if not, creates new instance
	 * via private constructor. Always returns the same instance on subsequent calls.
	 *
	 * @since 0.1.0
	 * @return GatherPress_Venue_Hierarchy The singleton instance.
	 */
	public static function get_instance(): GatherPress_Venue_Hierarchy {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	/**
	 * Constructor.
	 *
	 * **What:** Initializes the plugin by registering all WordPress hooks.
	 *
	 * **Why:** Private to enforce Singleton pattern - prevents external instantiation.
	 * All WordPress integration points must be registered during construction to ensure
	 * they're active when WordPress processes its action/filter queues.
	 *
	 * **How:** Adds action/filter callbacks for:
	 * - init: Register taxonomy and block
	 * - admin_menu: Add settings page
	 * - admin_init: Register settings
	 * - save_post_gatherpress_event (priority 20): Trigger geocoding after event save
	 * - enqueue_block_editor_assets: Localize script with filter data
	 *
	 * Priority 20 ensures GatherPress core has saved venue data first (default priority 10).
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'save_post_gatherpress_event', array( $this, 'maybe_geocode_event_venue' ), 20, 2 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'localize_block_editor_script' ) );
	}
	
	/**
	 * Initialize plugin.
	 *
	 * **What:** Registers the location taxonomy and block type with WordPress.
	 *
	 * **Why:** Must run on 'init' hook (earliest point WordPress allows taxonomy/block registration)
	 * to ensure taxonomy is available for queries and block is available in the editor.
	 *
	 * **How:** Calls register_location_taxonomy() to define the hierarchical taxonomy structure,
	 * then register_block() to make the display block available in Gutenberg.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function init(): void {
		$this->register_location_taxonomy();
		$this->register_block();
	}
	
	/**
	 * Get allowed hierarchy levels.
	 *
	 * **What:** Returns the configured range of hierarchy levels to use.
	 *
	 * **Why:** Allows filtering which levels are saved and displayed, providing flexibility
	 * for different use cases (e.g., only continent to city, or only city to street number).
	 *
	 * **How:** Applies the 'gatherpress_venue_hierarchy_levels' filter with default range [1, 7].
	 * Sites can hook this filter to restrict levels, e.g., [1, 4] for continent to city only.
	 *
	 * Level mapping:
	 * 1 = Continent
	 * 2 = Country  
	 * 3 = State
	 * 4 = City
	 * 5 = Street
	 * 6 = Street Number
	 *
	 * Example filter usage:
	 * add_filter( 'gatherpress_venue_hierarchy_levels', function() {
	 *     return [2, 4]; // Only Country, State, City
	 * } );
	 *
	 * @since 0.1.0
	 * @return array{0: int, 1: int} Array with [min_level, max_level].
	 */
	public function get_allowed_levels(): array {
		/**
		 * Filter the allowed hierarchy levels.
		 *
		 * @since 0.1.0
		 * @param array{0: int, 1: int} $levels Array with [min_level, max_level].
		 */
		return apply_filters( 'gatherpress_venue_hierarchy_levels', array( 1, 7 ) );
	}
	
	/**
	 * Localize block editor script with filter data.
	 *
	 * **What:** Makes PHP filter values available to JavaScript in the block editor.
	 *
	 * **Why:** The editor needs to know the allowed hierarchy levels to configure the
	 * dual-range control properly. Using wp_localize_script() is the standard WordPress
	 * way to pass PHP data to JavaScript without creating custom REST endpoints.
	 *
	 * **How:** Uses wp_localize_script() to attach data to the block's editor script.
	 * The data becomes available in JavaScript as window.gatherPressVenueHierarchy.
	 * Called on enqueue_block_editor_assets hook to ensure it runs after script registration.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function localize_block_editor_script(): void {
		// Get the allowed levels from the filter
		list( $min_level, $max_level ) = $this->get_allowed_levels();
		
		// Localize the script with the filter data
		wp_localize_script(
			'telex-block-gatherpress-venue-hierarchy-editor-script',
			'gatherPressVenueHierarchy',
			array(
				'allowedLevels' => array(
					'min' => $min_level,
					'max' => $max_level,
				),
			)
		);
	}
	
	/**
	 * Register the hierarchical location taxonomy.
	 *
	 * **What:** Creates a hierarchical taxonomy for organizing venues by geographical location.
	 *
	 * **Why:** WordPress's default taxonomy system requires explicit registration before use.
	 * Hierarchical structure allows parent-child relationships (Europe > Germany > Bavaria > Munich > Main St > 123),
	 * enabling filtering at any level and proper breadcrumb-style display.
	 *
	 * **How:** Uses register_taxonomy() with carefully configured args:
	 * - hierarchical: true - Enables parent-child relationships like categories
	 * - show_in_rest: true - Exposes to Gutenberg block editor and REST API
	 * - orderby: parent, order: ASC - Ensures terms display in geographical hierarchy order
	 * - rewrite: hierarchical - Creates pretty URLs like /location/europe/germany/
	 *
	 * The 'args' parameter contains WP_Term_Query args that control how terms are retrieved
	 * globally, ensuring consistent hierarchical ordering throughout WordPress.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function register_location_taxonomy(): void {
		$labels = array(
			'name'                       => __( 'Locations', 'gatherpress-venue-hierarchy' ),
			'singular_name'              => __( 'Location', 'gatherpress-venue-hierarchy' ),
			'search_items'               => __( 'Search Locations', 'gatherpress-venue-hierarchy' ),
			'all_items'                  => __( 'All Locations', 'gatherpress-venue-hierarchy' ),
			'parent_item'                => __( 'Parent Location', 'gatherpress-venue-hierarchy' ),
			'parent_item_colon'          => __( 'Parent Location:', 'gatherpress-venue-hierarchy' ),
			'edit_item'                  => __( 'Edit Location', 'gatherpress-venue-hierarchy' ),
			'update_item'                => __( 'Update Location', 'gatherpress-venue-hierarchy' ),
			'add_new_item'               => __( 'Add New Location', 'gatherpress-venue-hierarchy' ),
			'new_item_name'              => __( 'New Location Name', 'gatherpress-venue-hierarchy' ),
			'menu_name'                  => __( 'Locations', 'gatherpress-venue-hierarchy' ),
		);
		$wp_term_query_args            = array();
		$wp_term_query_args['orderby'] = 'parent';
		$wp_term_query_args['order']   = 'ASC';

		$settings      = \GatherPress\Core\Settings::get_instance();
		$events_slug   = $settings->get_value( 'general', 'urls', 'events' );
		$events_slug   = ! empty( $events_slug ) ? $events_slug  : '';
		$location_slug = $events_slug . '/in';

		$args = array(
			'labels'                     => $labels,
			'hierarchical'               => true,
			'public'                     => true,
			'show_ui'                    => true,
			'show_admin_column'          => true,
			'show_in_nav_menus'          => true,
			'show_tagcloud'              => true,
			'show_in_rest'               => true,
			'rewrite'                    => array(
				'slug' => $location_slug,
				'hierarchical' => true,
			),
			'sort'                       => true,
			'args'                       => $wp_term_query_args,
		);
		
		register_taxonomy( $this->taxonomy, array( 'gatherpress_event' ), $args );
	}
	
	
	/**
	 * Register the location hierarchy display block.
	 *
	 * **What:** Registers the Gutenberg block for displaying location hierarchies.
	 *
	 * **Why:** Block-based themes and the Gutenberg editor require explicit block registration
	 * to make blocks available in the block inserter. Using the build directory ensures
	 * the block includes compiled assets (JS, CSS) from the build process.
	 *
	 * **How:** Uses register_block_type() with build directory path. WordPress automatically
	 * loads block.json from that directory, which defines the block's metadata, scripts,
	 * and render callback (render.php).
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function register_block(): void {
		register_block_type( __DIR__ . '/build/' );
	}
	
	/**
	 * Add admin menu for plugin settings.
	 *
	 * **What:** Creates a settings page under WordPress Settings menu.
	 *
	 * **Why:** Provides UI for configuring default geographic locations that can be used
	 * when venue addresses don't contain complete information or as fallbacks.
	 *
	 * **How:** Uses add_options_page() to create submenu under Settings. Requires
	 * 'manage_options' capability (typically only administrators) and renders via
	 * render_admin_page() callback.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function add_admin_menu(): void {
		add_options_page(
			__( 'GatherPress Location', 'gatherpress-venue-hierarchy' ),
			__( 'GatherPress Location', 'gatherpress-venue-hierarchy' ),
			'manage_options',
			'gatherpress-venue-hierarchy',
			array( $this, 'render_admin_page' )
		);
	}
	
	/**
	 * Register plugin settings.
	 *
	 * **What:** Registers settings with WordPress Settings API for storing default location values.
	 *
	 * **Why:** WordPress Settings API provides secure storage, automatic sanitization hooks,
	 * and integration with WordPress admin forms. Storing as array keeps related settings together
	 * in a single option, reducing database queries.
	 *
	 * **How:** Uses register_setting() with:
	 * - Option group: gatherpress_venue_hierarchy (matches settings_fields() in form)
	 * - Option name: gatherpress_venue_hierarchy_defaults (database key)
	 * - Type: array - Stores continent, country, state, city, street, street_number as associative array
	 * - Sanitize callback: Runs before saving to database, ensures data integrity
	 * - Default: Empty strings for all fields
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'gatherpress_venue_hierarchy',
			'gatherpress_venue_hierarchy_defaults',
			array(
				'type' => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default' => array(
					'continent' => '',
					'country' => '',
					'state' => '',
					'city' => '',
					'street' => '',
					'street_number' => '',
				),
			)
		);
	}
	
	/**
	 * Sanitize settings input.
	 *
	 * **What:** Validates and sanitizes user input from settings form before saving.
	 *
	 * **Why:** Critical security function - prevents XSS attacks and SQL injection by cleaning
	 * all user input. Ensures data integrity by handling unexpected input gracefully.
	 *
	 * **How:** 
	 * 1. Validates input is array (handles corrupted/malicious POST data)
	 * 2. Uses null coalescing operator (??) for safe array access
	 * 3. Applies sanitize_text_field() to each value (strips HTML/PHP tags, encodes special chars)
	 * 4. Returns structured array matching expected format
	 *
	 * Example input: ['continent' => '<script>alert(1)</script>', 'country' => 'Germany']
	 * Example output: ['continent' => '', 'country' => 'Germany', 'state' => '', 'city' => '', 'street' => '', 'street_number' => '']
	 *
	 * @since 0.1.0
	 * @param mixed $input Raw settings input from form submission.
	 * @return array<string, string> Sanitized settings array with keys: continent, country, state, city, street, street_number.
	 */
	public function sanitize_settings( $input ): array {
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		
		return array(
			'continent' => sanitize_text_field( $input['continent'] ?? '' ),
			'country' => sanitize_text_field( $input['country'] ?? '' ),
			'state' => sanitize_text_field( $input['state'] ?? '' ),
			'city' => sanitize_text_field( $input['city'] ?? '' ),
			'street' => sanitize_text_field( $input['street'] ?? '' ),
			'street_number' => sanitize_text_field( $input['street_number'] ?? '' ),
		);
	}
	
	/**
	 * Render the admin settings page.
	 *
	 * **What:** Outputs the HTML form for configuring default location settings.
	 *
	 * **Why:** Provides user interface for administrators to set default geographical terms
	 * used as fallbacks during geocoding or when venue addresses are incomplete.
	 *
	 * **How:** 
	 * 1. Checks user capability (security - only admins)
	 * 2. Retrieves current settings from database with fallback defaults
	 * 3. Validates data structure (handles corrupted options)
	 * 4. Renders standard WordPress settings form using:
	 *    - settings_fields(): Outputs nonces and hidden fields
	 *    - Input names match array structure: name="option_name[key]"
	 *    - submit_button(): WordPress-styled save button
	 *
	 * Form submits to options.php which handles validation and saving via Settings API.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		
		$defaults = get_option( 'gatherpress_venue_hierarchy_defaults', array(
			'continent' => '',
			'country' => '',
			'state' => '',
			'city' => '',
			'street' => '',
			'street_number' => '',
		) );
		
		if ( ! is_array( $defaults ) ) {
			$defaults = array(
				'continent' => '',
				'country' => '',
				'state' => '',
				'city' => '',
				'street' => '',
				'street_number' => '',
			);
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'gatherpress_venue_hierarchy' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="default_continent"><?php esc_html_e( 'Default Continent', 'gatherpress-venue-hierarchy' ); ?></label>
						</th>
						<td>
							<input type="text" id="default_continent" name="gatherpress_venue_hierarchy_defaults[continent]" 
								value="<?php echo esc_attr( $defaults['continent'] ?? '' ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Default continent for new venues', 'gatherpress-venue-hierarchy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="default_country"><?php esc_html_e( 'Default Country', 'gatherpress-venue-hierarchy' ); ?></label>
						</th>
						<td>
							<input type="text" id="default_country" name="gatherpress_venue_hierarchy_defaults[country]" 
								value="<?php echo esc_attr( $defaults['country'] ?? '' ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Default country for new venues', 'gatherpress-venue-hierarchy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="default_state"><?php esc_html_e( 'Default State/Region', 'gatherpress-venue-hierarchy' ); ?></label>
						</th>
						<td>
							<input type="text" id="default_state" name="gatherpress_venue_hierarchy_defaults[state]" 
								value="<?php echo esc_attr( $defaults['state'] ?? '' ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Default state or region for new venues', 'gatherpress-venue-hierarchy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="default_city"><?php esc_html_e( 'Default City', 'gatherpress-venue-hierarchy' ); ?></label>
						</th>
						<td>
							<input type="text" id="default_city" name="gatherpress_venue_hierarchy_defaults[city]" 
								value="<?php echo esc_attr( $defaults['city'] ?? '' ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Default city for new venues', 'gatherpress-venue-hierarchy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="default_street"><?php esc_html_e( 'Default Street', 'gatherpress-venue-hierarchy' ); ?></label>
						</th>
						<td>
							<input type="text" id="default_street" name="gatherpress_venue_hierarchy_defaults[street]" 
								value="<?php echo esc_attr( $defaults['street'] ?? '' ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Default street for new venues', 'gatherpress-venue-hierarchy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="default_street_number"><?php esc_html_e( 'Default Street Number', 'gatherpress-venue-hierarchy' ); ?></label>
						</th>
						<td>
							<input type="text" id="default_street_number" name="gatherpress_venue_hierarchy_defaults[street_number]" 
								value="<?php echo esc_attr( $defaults['street_number'] ?? '' ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Default street number for new venues', 'gatherpress-venue-hierarchy' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
	
	/**
	 * Maybe geocode event venue on save.
	 *
	 * **What:** Triggers geocoding and location term creation when a GatherPress event is saved.
	 *
	 * **Why:** Automates the location hierarchy creation process so users don't have to manually
	 * create taxonomy terms. Checks for existing terms to avoid re-geocoding on every save,
	 * but will recreate terms if they were manually deleted.
	 *
	 * **How:**
	 * 1. Early returns for autosave, non-event posts, missing GatherPress class
	 * 2. Gets venue information via GatherPress Event class
	 * 3. Checks if location terms already exist using wp_get_object_terms()
	 * 4. Only geocodes if terms are missing/deleted, preventing unnecessary API calls
	 * 5. Priority 20 ensures this runs AFTER GatherPress saves venue data (priority 10)
	 *
	 * Flow example:
	 * - User saves event with venue "123 Main St, Munich, Germany"
	 * - GatherPress saves venue data (priority 10)
	 * - This function runs (priority 20)
	 * - Checks for existing terms (none found)
	 * - Calls geocode_and_create_hierarchy()
	 * - Nominatim API returns coordinates + address components
	 * - Creates terms: Europe > Germany > Bavaria > Munich > Main St > 123
	 * - Associates all terms with the event
	 *
	 * @since 0.1.0
	 * @param int     $post_id Post ID of the event.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function maybe_geocode_event_venue( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		
		if ( 'gatherpress_event' !== $post->post_type ) {
			return;
		}
		
		if ( ! class_exists( 'GatherPress\Core\Event' ) ) {
			error_log( 'GatherPress Venue Hierarchy: GatherPress Event class not found' );
			return;
		}
		
		$event = new \GatherPress\Core\Event( $post_id );
		$venue_info = $event->get_venue_information();
		
		if ( ! is_array( $venue_info ) || empty( $venue_info['full_address'] ) ) {
			return;
		}
		
		// Check if location terms already exist for this event
		$existing_terms = wp_get_object_terms(
			$post_id,
			$this->taxonomy,
			array( 'fields' => 'ids' )
		);
		
		// If terms already exist and are valid, skip geocoding
		if ( ! is_wp_error( $existing_terms ) && ! empty( $existing_terms ) ) {
			return;
		}
		
		// Terms don't exist or were deleted - geocode and create them
		$this->geocode_and_create_hierarchy( $post_id, $venue_info['full_address'] );
	}
	
	/**
	 * Geocode address and create location hierarchy.
	 *
	 * **What:** Coordinates the geocoding process and subsequent hierarchy term creation.
	 *
	 * **Why:** Separates concerns by delegating to specialized singleton classes:
	 * Geocoder handles API communication, Hierarchy_Builder handles term creation.
	 * This keeps the main class focused on coordination rather than implementation details.
	 *
	 * **How:**
	 * 1. Gets Geocoder singleton instance
	 * 2. Geocodes address via Nominatim API (or retrieves from cache)
	 * 3. If geocoding succeeds, gets Hierarchy_Builder singleton
	 * 4. Passes location data to builder for term creation
	 * 5. Logs error if geocoding fails
	 *
	 * Example flow:
	 * Input: $post_id=123, $address="Marienplatz 1, Munich, Germany"
	 * Geocoder returns: ['continent'=>'Europe', 'country'=>'Germany', 'state'=>'Bavaria', 'city'=>'Munich', 'street'=>'Marienplatz', 'street_number'=>'1']
	 * Hierarchy_Builder creates: Europe (parent:0) > Germany (parent:Europe_ID) > Bavaria (parent:Germany_ID) > Munich (parent:Bavaria_ID) > Marienplatz (parent:Munich_ID) > 1 (parent:Marienplatz_ID)
	 * All term IDs associated with post 123
	 *
	 * @since 0.1.0
	 * @param int    $post_id Post ID to associate terms with.
	 * @param string $address Address to geocode (e.g., "123 Main St, City, Country").
	 * @return void
	 */
	private function geocode_and_create_hierarchy( int $post_id, string $address ): void {
		$geocoder = GatherPress_Venue_Geocoder::get_instance();
		$location = $geocoder->geocode( $address );
		
		if ( ! $location ) {
			error_log( 'GatherPress Venue Hierarchy: Failed to geocode address for event ' . $post_id );
			return;
		}
		
		$hierarchy_builder = GatherPress_Venue_Hierarchy_Builder::get_instance();
		$hierarchy_builder->create_hierarchy_terms( $post_id, $location, $this->taxonomy );
	}
}

/**
 * Geocoder class using Singleton pattern.
 *
 * **What:** Handles address geocoding via Nominatim OpenStreetMap API with caching.
 *
 * **Why:** Geocoding is rate-limited and slow - caching prevents repeated API calls for
 * the same address. Nominatim provides free, open-source geocoding that returns detailed
 * address components (country, state, city, street, house_number) needed for hierarchy building.
 * Special handling for German-speaking regions accounts for different administrative structures.
 *
 * **How:** 
 * - Uses WordPress transients for 1-hour caching (balance between freshness and API load)
 * - Generates cache keys from address MD5 hash
 * - Parses Nominatim's addressdetails response into standardized location array
 * - Maps country codes to translated continent names using WordPress i18n
 * - Handles regional variations (e.g., "state" vs "region" vs "province")
 * - Extracts street and house_number for complete address hierarchy
 * - Sends site language to API for localized results
 *
 * @since 0.1.0
 */
class GatherPress_Venue_Geocoder {
	
	/**
	 * Single instance.
	 *
	 * **Why:** Singleton prevents multiple instances with potentially different configurations
	 * and ensures consistent caching behavior across the request.
	 *
	 * @since 0.1.0
	 * @var GatherPress_Venue_Geocoder|null
	 */
	private static $instance = null;
	
	/**
	 * Nominatim API endpoint.
	 *
	 * **What:** Base URL for OpenStreetMap's Nominatim geocoding service.
	 *
	 * **Why:** Nominatim is free, open-source, and returns detailed address components.
	 * The /search endpoint accepts address queries and returns coordinates + detailed location data.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $api_endpoint = 'https://nominatim.openstreetmap.org/search';
	
	/**
	 * Cache duration in seconds (1 hour).
	 *
	 * **What:** How long to cache geocoding results in WordPress transients.
	 *
	 * **Why:** Balance between data freshness and API load reduction:
	 * - Too short: Excessive API calls, possible rate limiting
	 * - Too long: Outdated results if addresses change
	 * - 1 hour: Good compromise for venue addresses (rarely change within an hour)
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private $cache_duration = 3600;
	
	/**
	 * Get singleton instance.
	 *
	 * @since 0.1.0
	 * @return GatherPress_Venue_Geocoder The singleton instance.
	 */
	public static function get_instance(): GatherPress_Venue_Geocoder {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	/**
	 * Constructor.
	 *
	 * Private constructor to enforce singleton pattern.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}
	
	/**
	 * Get country to continent mapping.
	 *
	 * **What:** Maps ISO 3166-1 alpha-2 country codes to translated continent names.
	 *
	 * **Why:** Nominatim API doesn't return continent information, so we must derive it
	 * from country codes. Using WordPress's default translation domain (__()) ensures
	 * continent names match WordPress core's translations, maintaining consistency.
	 *
	 * **How:** Returns comprehensive array covering ~200 countries organized by continent.
	 * Uses lowercase country codes to match Nominatim's response format. Includes:
	 * - All European countries (including special regions like Gibraltar, Vatican)
	 * - North/South American countries
	 * - Asian countries (including Middle East)
	 * - African countries
	 * - Oceanic nations
	 * - Antarctica (for completeness)
	 *
	 * Example usage:
	 * $mapping = $this->get_country_continents();
	 * $continent = $mapping['de'] ?? __( 'Unknown' ); // Returns "Europe" (translated)
	 *
	 * @since 0.1.0
	 * @return array<string, string> Mapping of lowercase country codes to translated continent names.
	 */
	private function get_country_continents(): array {
		return array(
			// Europe
			'de' => __( 'Europe' ), 'at' => __( 'Europe' ), 'ch' => __( 'Europe' ), 'fr' => __( 'Europe' ),
			'it' => __( 'Europe' ), 'es' => __( 'Europe' ), 'pt' => __( 'Europe' ), 'uk' => __( 'Europe' ),
			'gb' => __( 'Europe' ), 'ie' => __( 'Europe' ), 'nl' => __( 'Europe' ), 'be' => __( 'Europe' ),
			'lu' => __( 'Europe' ), 'se' => __( 'Europe' ), 'no' => __( 'Europe' ), 'dk' => __( 'Europe' ),
			'fi' => __( 'Europe' ), 'pl' => __( 'Europe' ), 'cz' => __( 'Europe' ), 'sk' => __( 'Europe' ),
			'hu' => __( 'Europe' ), 'ro' => __( 'Europe' ), 'bg' => __( 'Europe' ), 'gr' => __( 'Europe' ),
			'hr' => __( 'Europe' ), 'si' => __( 'Europe' ), 'rs' => __( 'Europe' ), 'ba' => __( 'Europe' ),
			'me' => __( 'Europe' ), 'mk' => __( 'Europe' ), 'al' => __( 'Europe' ), 'tr' => __( 'Europe' ),
			'ru' => __( 'Europe' ), 'ua' => __( 'Europe' ), 'by' => __( 'Europe' ), 'md' => __( 'Europe' ),
			'ee' => __( 'Europe' ), 'lv' => __( 'Europe' ), 'lt' => __( 'Europe' ), 'is' => __( 'Europe' ),
			// North America
			'us' => __( 'North America' ), 'ca' => __( 'North America' ), 'mx' => __( 'North America' ),
			'gt' => __( 'North America' ), 'bz' => __( 'North America' ), 'sv' => __( 'North America' ),
			'hn' => __( 'North America' ), 'ni' => __( 'North America' ), 'cr' => __( 'North America' ),
			'pa' => __( 'North America' ), 'cu' => __( 'North America' ), 'jm' => __( 'North America' ),
			'ht' => __( 'North America' ), 'do' => __( 'North America' ), 'pr' => __( 'North America' ),
			// South America
			'br' => __( 'South America' ), 'ar' => __( 'South America' ), 'cl' => __( 'South America' ),
			'co' => __( 'South America' ), 'pe' => __( 'South America' ), 've' => __( 'South America' ),
			'ec' => __( 'South America' ), 'bo' => __( 'South America' ), 'py' => __( 'South America' ),
			'uy' => __( 'South America' ), 'gy' => __( 'South America' ), 'sr' => __( 'South America' ),
			// Asia
			'cn' => __( 'Asia' ), 'jp' => __( 'Asia' ), 'in' => __( 'Asia' ), 'id' => __( 'Asia' ),
			'pk' => __( 'Asia' ), 'bd' => __( 'Asia' ), 'ph' => __( 'Asia' ), 'vn' => __( 'Asia' ),
			'th' => __( 'Asia' ), 'mm' => __( 'Asia' ), 'kr' => __( 'Asia' ), 'af' => __( 'Asia' ),
			'kp' => __( 'Asia' ), 'tw' => __( 'Asia' ), 'my' => __( 'Asia' ), 'np' => __( 'Asia' ),
			'lk' => __( 'Asia' ), 'kh' => __( 'Asia' ), 'la' => __( 'Asia' ), 'sg' => __( 'Asia' ),
			'mn' => __( 'Asia' ), 'bt' => __( 'Asia' ), 'mv' => __( 'Asia' ), 'bn' => __( 'Asia' ),
			'il' => __( 'Asia' ), 'jo' => __( 'Asia' ), 'lb' => __( 'Asia' ), 'sy' => __( 'Asia' ),
			'iq' => __( 'Asia' ), 'ir' => __( 'Asia' ), 'sa' => __( 'Asia' ), 'ye' => __( 'Asia' ),
			'om' => __( 'Asia' ), 'ae' => __( 'Asia' ), 'qa' => __( 'Asia' ), 'kw' => __( 'Asia' ),
			'bh' => __( 'Asia' ), 'am' => __( 'Asia' ), 'az' => __( 'Asia' ), 'ge' => __( 'Asia' ),
			'kz' => __( 'Asia' ), 'uz' => __( 'Asia' ), 'tm' => __( 'Asia' ), 'kg' => __( 'Asia' ),
			'tj' => __( 'Asia' ),
			// Africa
			'ng' => __( 'Africa' ), 'et' => __( 'Africa' ), 'eg' => __( 'Africa' ), 'cd' => __( 'Africa' ),
			'za' => __( 'Africa' ), 'tz' => __( 'Africa' ), 'ke' => __( 'Africa' ), 'ug' => __( 'Africa' ),
			'dz' => __( 'Africa' ), 'sd' => __( 'Africa' ), 'ma' => __( 'Africa' ), 'ao' => __( 'Africa' ),
			'gh' => __( 'Africa' ), 'mz' => __( 'Africa' ), 'mg' => __( 'Africa' ), 'cm' => __( 'Africa' ),
			'ci' => __( 'Africa' ), 'ne' => __( 'Africa' ), 'bf' => __( 'Africa' ), 'ml' => __( 'Africa' ),
			'mw' => __( 'Africa' ), 'zm' => __( 'Africa' ), 'so' => __( 'Africa' ), 'sn' => __( 'Africa' ),
			'td' => __( 'Africa' ), 'zw' => __( 'Africa' ), 'gn' => __( 'Africa' ), 'rw' => __( 'Africa' ),
			'bj' => __( 'Africa' ), 'tn' => __( 'Africa' ), 'bi' => __( 'Africa' ), 'ss' => __( 'Africa' ),
			'tg' => __( 'Africa' ), 'sl' => __( 'Africa' ), 'ly' => __( 'Africa' ), 'lr' => __( 'Africa' ),
			'mr' => __( 'Africa' ), 'cf' => __( 'Africa' ), 'er' => __( 'Africa' ), 'gm' => __( 'Africa' ),
			'bw' => __( 'Africa' ), 'ga' => __( 'Africa' ), 'gw' => __( 'Africa' ), 'mu' => __( 'Africa' ),
			'sz' => __( 'Africa' ), 'dj' => __( 'Africa' ), 'gq' => __( 'Africa' ), 'km' => __( 'Africa' ),
			// Oceania
			'au' => __( 'Oceania' ), 'pg' => __( 'Oceania' ), 'nz' => __( 'Oceania' ), 'fj' => __( 'Oceania' ),
			'sb' => __( 'Oceania' ), 'nc' => __( 'Oceania' ), 'pf' => __( 'Oceania' ), 'vu' => __( 'Oceania' ),
			'ws' => __( 'Oceania' ), 'ki' => __( 'Oceania' ), 'fm' => __( 'Oceania' ), 'to' => __( 'Oceania' ),
			'pw' => __( 'Oceania' ), 'mh' => __( 'Oceania' ), 'nr' => __( 'Oceania' ), 'tv' => __( 'Oceania' ),
			// Antarctica
			'aq' => __( 'Antarctica' ),
		);
	}
	
	/**
	 * Geocode an address.
	 *
	 * **What:** Converts a text address to geographic coordinates and location components.
	 *
	 * **Why:** Need structured location data (continent, country, state, city, street, street_number)
	 * to build the taxonomy hierarchy. Caching prevents hitting API rate limits and improves performance.
	 * Site language is sent to API to get localized place names.
	 *
	 * **How:**
	 * 1. Sanitizes input address
	 * 2. Generates cache key from MD5 hash (prevents cache collision for similar addresses)
	 * 3. Checks transient cache (1-hour expiration)
	 * 4. If cache miss, queries Nominatim API with:
	 *    - q: Address query
	 *    - format: json (structured response)
	 *    - addressdetails: 1 (include address components)
	 *    - limit: 1 (only need best match)
	 *    - accept-language: WordPress site language (for localized results)
	 * 5. Parses response via parse_location_data()
	 * 6. Caches successful result
	 * 7. Returns location array or false on failure
	 *
	 * Example API response:
	 * [{
	 *   "address": {
	 *     "house_number": "1",
	 *     "road": "Marienplatz",
	 *     "city": "Munich",
	 *     "state": "Bavaria",
	 *     "country": "Germany",
	 *     "country_code": "de"
	 *   }
	 * }]
	 *
	 * Example return value:
	 * [
	 *   'continent' => 'Europe',
	 *   'country' => 'Germany',
	 *   'country_code' => 'de',
	 *   'state' => 'Bavaria',
	 *   'city' => 'Munich',
	 *   'street' => 'Marienplatz',
	 *   'street_number' => '1'
	 * ]
	 *
	 * @since 0.1.0
	 * @param string $address Full address to geocode (e.g., "Marienplatz 1, 80331 Munich, Germany").
	 * @return array<string, string>|false Location data array or false on failure.
	 */
	public function geocode( string $address ) {
		$address = sanitize_text_field( $address );
		$cache_key = 'gpvh_geocode_' . md5( $address );
		
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		
		// Get WordPress site language in format that Nominatim accepts (e.g., 'de', 'en', 'fr')
		$site_locale = get_locale();
		// Convert locale like 'de_DE' to language code 'de'
		$language = explode( '_', $site_locale )[0];
		
		$response = wp_remote_get(
			add_query_arg(
				array(
					'q' => $address,
					'format' => 'json',
					'addressdetails' => '1',
					'limit' => '1',
					'accept-language' => $language,
					// 'polygon_geojson' => 1,
					'email' => get_bloginfo( 'admin_email' ), // Nominatim requires an email for identification
				),
				$this->api_endpoint
			),
			array(
				'timeout' => 10,
				'headers' => array(
					'User-Agent' => 'GatherPress Venue Hierarchy WordPress Plugin',
				),
			)
		);
		
		if ( is_wp_error( $response ) ) {
			error_log( 'GatherPress Venue Hierarchy: Geocoding API error - ' . $response->get_error_message() );
			return false;
		}
		
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		
		if ( empty( $data ) || ! is_array( $data ) ) {
			error_log( 'GatherPress Venue Hierarchy: Invalid API response for address: ' . $address );
			return false;
		}
		
		$location = $this->parse_location_data( $data[0] );
		
		if ( $location ) {
			set_transient( $cache_key, $location, $this->cache_duration );
		}
		
		return $location;
	}
	
	/**
	 * Parse location data from API response.
	 *
	 * **What:** Extracts and normalizes location components from Nominatim API response.
	 *
	 * **Why:** Nominatim's response structure varies by country and address type. Need to:
	 * 1. Handle different field names (state vs region vs province, road vs street)
	 * 2. Add continent information (not in API response)
	 * 3. Normalize to consistent structure for hierarchy building
	 * 4. Account for German-speaking regions' unique administrative structure
	 * 5. Handle city-states like Berlin where state and city are identical
	 * 6. Extract street and house_number for complete address hierarchy
	 *
	 * **How:**
	 * 1. Validates 'address' field exists in response
	 * 2. Extracts country_code and uses it to lookup continent
	 * 3. For German-speaking regions (DE, AT, CH, LU):
	 *    - Uses 'state' field if present (Bundesland/Canton)
	 *    - For city-states (e.g., Berlin where state is missing):
	 *      - Uses city name as state to maintain hierarchy consistency
	 *      - Uses suburb (with fallback to borough) as city
	 *      - This prevents duplicate entries and creates proper hierarchy:
	 *        Europe > Germany > Berlin > Prenzlauer Berg > Street > Number
	 * 4. For other countries:
	 *    - Falls back through state > region > province (handles naming variations)
	 * 5. Extracts city from city > town > village > county (urban to rural fallback)
	 * 6. Extracts street from road > street > pedestrian (common field names)
	 * 7. Extracts house_number for street number
	 * 8. Sanitizes all values
	 * 9. Filters out empty values with array_filter()
	 *
	 * Example input (Berlin - city-state):
	 * [
	 *   'address' => [
	 *     'house_number' => '81-84',
	 *     'road' => 'Greifswalder Straße',
	 *     'suburb' => 'Prenzlauer Berg',
	 *     'borough' => 'Pankow',
	 *     'city' => 'Berlin',
	 *     // Note: NO 'state' field for Berlin
	 *     'country' => 'Germany',
	 *     'country_code' => 'de'
	 *   ]
	 * ]
	 *
	 * Example output (Berlin - city-state):
	 * [
	 *   'continent' => 'Europe',
	 *   'country' => 'Germany',
	 *   'country_code' => 'de',
	 *   'state' => 'Berlin',              // City name used as state
	 *   'city' => 'Prenzlauer Berg',      // Suburb used as city (avoids duplication)
	 *   'street' => 'Greifswalder Straße',
	 *   'street_number' => '81-84'
	 * ]
	 *
	 * Example input (Munich - has separate state):
	 * [
	 *   'address' => [
	 *     'house_number' => '1',
	 *     'road' => 'Marienplatz',
	 *     'city' => 'Munich',
	 *     'state' => 'Bavaria',   // Separate state field present
	 *     'country' => 'Germany',
	 *     'country_code' => 'de'
	 *   ]
	 * ]
	 *
	 * Example output (Munich - normal case):
	 * [
	 *   'continent' => 'Europe',
	 *   'country' => 'Germany',
	 *   'country_code' => 'de',
	 *   'state' => 'Bavaria',
	 *   'city' => 'Munich',
	 *   'street' => 'Marienplatz',
	 *   'street_number' => '1'
	 * ]
	 *
	 * @since 0.1.0
	 * @param array<string, mixed> $data API response data (first result from Nominatim).
	 * @return array<string, string>|false Parsed location data or false on failure.
	 */
	private function parse_location_data( array $data ) {
		if ( empty( $data['address'] ) || ! is_array( $data['address'] ) ) {
			return false;
		}
		
		$address = $data['address'];
		$country_code = strtolower( $address['country_code'] ?? '' );
		
		// Get continent from country code using translated names
		$country_continents = $this->get_country_continents();
		$continent = $country_continents[ $country_code ] ?? __( 'Unknown' );
		
		$location = array(
			'continent' => $continent,
			'country' => sanitize_text_field( $address['country'] ?? '' ),
			'country_code' => $country_code,
			'state' => '',
			'city' => '',
			'street' => '',
			'street_number' => '',
		);
		
		$german_regions = array( 'de', 'at', 'ch', 'lu' );
		$is_german_region = in_array( $country_code, $german_regions, true );
		
		if ( $is_german_region ) {
			// For German-speaking regions, try to get state field
			$state_value = sanitize_text_field( $address['state'] ?? '' );
			
			// If state is missing, we're dealing with a city-state (like Berlin)
			if ( empty( $state_value ) ) {
				// Get the city name for the state level
				$city_name = sanitize_text_field(
					$address['city'] ?? $address['town'] ?? $address['village'] ?? ''
				);
				
				if ( ! empty( $city_name ) ) {
					// Use city name as state
					$location['state'] = $city_name;
					
					// Use suburb (or borough as fallback) as city to avoid duplication
					$location['city'] = sanitize_text_field(
						$address['suburb'] ?? $address['borough'] ?? ''
					);
				}
			} else {
				// Normal case: state exists separately from city
				$location['state'] = $state_value;
				
				// Extract city normally
				$location['city'] = sanitize_text_field(
					$address['city'] ?? $address['town'] ?? $address['village'] ?? $address['county'] ?? ''
				);
			}
		} else {
			// Non-German regions: use standard fallback chain
			$location['state'] = sanitize_text_field( 
				$address['state'] ?? $address['region'] ?? $address['province'] ?? '' 
			);
			
			// Extract city normally
			$location['city'] = sanitize_text_field(
				$address['city'] ?? $address['town'] ?? $address['village'] ?? $address['county'] ?? ''
			);
		}
		
		// Extract street name (road is most common, but also check street and pedestrian)
		$location['street'] = sanitize_text_field(
			$address['road'] ?? $address['street'] ?? $address['pedestrian'] ?? ''
		);
		
		// Extract house/street number
		$location['street_number'] = sanitize_text_field(
			$address['house_number'] ?? ''
		);
		
		return array_filter( $location );
	}
}

/**
 * Hierarchy builder class using Singleton pattern.
 *
 * **What:** Creates and manages hierarchical taxonomy terms for geographical locations.
 *
 * **Why:** Separates term creation logic from geocoding logic (Single Responsibility Principle).
 * Handles the complex task of establishing parent-child relationships between terms
 * and ensuring terms exist before referencing them as parents. This prevents orphaned
 * terms and maintains data integrity across 7 levels of hierarchy.
 *
 * **How:** 
 * - Creates terms in top-down order (continent > country > state > city > street > street-number)
 * - Each level uses parent's term_id as parent parameter
 * - Checks for existing terms before creating (prevents duplicates)
 * - Updates parent relationships if term exists with wrong parent
 * - Associates all created terms with the event post
 * - Uses sanitize_title() for proper slug generation (handles ß, accents, etc.)
 * - Respects allowed level range via filter
 *
 * @since 0.1.0
 */
class GatherPress_Venue_Hierarchy_Builder {
	
	/**
	 * Single instance.
	 *
	 * **Why:** Singleton ensures consistent term creation behavior and prevents
	 * potential race conditions from multiple instances creating duplicate terms.
	 *
	 * @since 0.1.0
	 * @var GatherPress_Venue_Hierarchy_Builder|null
	 */
	private static $instance = null;
	
	/**
	 * Get singleton instance.
	 *
	 * @since 0.1.0
	 * @return GatherPress_Venue_Hierarchy_Builder The singleton instance.
	 */
	public static function get_instance(): GatherPress_Venue_Hierarchy_Builder {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	/**
	 * Constructor.
	 *
	 * Private constructor to enforce singleton pattern.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}
	
	/**
	 * Check if a specific hierarchy level is allowed.
	 *
	 * **What:** Determines if a given level number should be processed based on configuration.
	 *
	 * **Why:** Allows sites to restrict which hierarchy levels are created, providing flexibility
	 * for different use cases without code changes.
	 *
	 * **How:** Gets the allowed range via filter and checks if the level falls within bounds.
	 *
	 * Level numbers:
	 * 1 = Continent
	 * 2 = Country  
	 * 3 = State
	 * 4 = City
	 * 5 = Street
	 * 6 = Street Number
	 *
	 * @since 0.1.0
	 * @param int $level Level number to check (1-7).
	 * @return bool True if level is allowed, false otherwise.
	 */
	private function is_level_allowed( int $level ): bool {
		$hierarchy = GatherPress_Venue_Hierarchy::get_instance();
		list( $min_level, $max_level ) = $hierarchy->get_allowed_levels();
		
		return $level >= $min_level && $level <= $max_level;
	}
	
	/**
	 * Create hierarchy terms.
	 *
	 * **What:** Generates complete hierarchical taxonomy term structure from location data.
	 *
	 * **Why:** Automates the creation of properly nested geographic terms
	 * (continent > country > state > city > street > street-number) with correct parent-child relationships.
	 * This enables filtering events at any geographic level and provides structured data for the display block.
	 *
	 * **How:**
	 * 1. Checks allowed level range via filter
	 * 2. Creates terms in hierarchical order using cascading term IDs:
	 *    - Continent (level 1, parent: 0 = root level)
	 *    - Country (level 2, parent: continent_term_id)
	 *    - State (level 3, parent: country_term_id) - may equal city for city-states
	 *    - City (level 4, parent: state_term_id) - may be suburb for city-states
	 *    - Street (level 5, parent: city_term_id)
	 *    - Street Number (level 6, parent: street_term_id)
	 * 3. Each step uses get_or_create_term() which:
	 *    - Checks if term exists by name
	 *    - Creates if missing with proper slug generation
	 *    - Updates parent if wrong
	 *    - Returns term_id for next level
	 * 4. Skips levels outside allowed range
	 * 5. Tracks last valid parent for proper relationships
	 * 6. Collects all valid term IDs (filters out 0s from failures)
	 * 7. Associates all terms with the event via wp_set_object_terms()
	 *    - false parameter: Replace existing terms (not append)
	 *
	 * Example flow for "81-84 Greifswalder Straße, Prenzlauer Berg, Berlin, Germany" (city-state):
	 * With default levels [1, 7]:
	 * - Create/get "Europe" term (ID: 100, parent: 0)
	 * - Create/get "Germany" term (ID: 101, parent: 100)
	 * - Create/get "Berlin" term as state (ID: 102, parent: 101)
	 * - Create/get "Prenzlauer Berg" term as city (ID: 103, parent: 102)
	 * - Create/get "Greifswalder Straße" term (ID: 104, parent: 103)
	 * - Create/get "Greifswalder Straße 81-84" term (ID: 105, parent: 104)
	 * - Associate post with terms [100, 101, 102, 103, 104, 105]
	 *
	 * With restricted levels [2, 4] (Country to City only):
	 * - Skip continent (level 1 not allowed)
	 * - Create/get "Germany" term (ID: 101, parent: 0) - root because continent skipped
	 * - Create/get "Berlin" term as state (ID: 102, parent: 101)
	 * - Create/get "Prenzlauer Berg" term as city (ID: 103, parent: 102)
	 * - Skip street levels (5-6 not allowed)
	 * - Associate post with terms [101, 102, 103]
	 *
	 * **Result:** Event is tagged with complete hierarchy, browseable at any level.
	 *
	 * @since 0.1.0
	 * @param int                  $post_id  Post ID to associate terms with.
	 * @param array<string, string> $location Location data array with keys:
	 *                                       'continent', 'country', 'state', 'city', 'street', 'street_number'.
	 * @param string               $taxonomy Taxonomy name (e.g., 'gatherpress-location').
	 * @return void
	 */
	public function create_hierarchy_terms( int $post_id, array $location, string $taxonomy ): void {
		$continent_term_id = 0;
		$country_term_id = 0;
		$state_term_id = 0;
		$city_term_id = 0;
		$street_term_id = 0;
		$street_number_term_id = 0;

		$locale =  ! empty( $location['country_code'] ) ? $location['country_code'] : '';
		
		// Track the last valid parent ID for proper hierarchy
		$last_parent_id = 0;
		
		// Level 1: Continent
		if ( ! empty( $location['continent'] ) && $this->is_level_allowed( 1 ) ) {
			$continent_term_id = $this->get_or_create_term( $location['continent'], $last_parent_id, $taxonomy, $locale );
			if ( $continent_term_id ) {
				$last_parent_id = $continent_term_id;
			}
		}
		
		// Level 2: Country
		if ( ! empty( $location['country'] ) && $this->is_level_allowed( 2 ) ) {
			$country_term_id = $this->get_or_create_term( $location['country'], $last_parent_id, $taxonomy, $locale );
			if ( $country_term_id ) {
				$last_parent_id = $country_term_id;
			}
		}
		
		// Level 3: State
		if ( ! empty( $location['state'] ) && $this->is_level_allowed( 3 ) ) {
			$state_term_id = $this->get_or_create_term( $location['state'], $last_parent_id, $taxonomy, $locale );
			if ( $state_term_id ) {
				$last_parent_id = $state_term_id;
			}
		}
		
		// Level 4: City
		if ( ! empty( $location['city'] ) && $this->is_level_allowed( 4 ) ) {
			$city_term_id = $this->get_or_create_term( $location['city'], $last_parent_id, $taxonomy, $locale );
			if ( $city_term_id ) {
				$last_parent_id = $city_term_id;
			}
		}
		
		// Level 5: Street
		if ( ! empty( $location['street'] ) && $this->is_level_allowed( 5 ) ) {
			$street_term_id = $this->get_or_create_term( $location['street'], $last_parent_id, $taxonomy, $locale );
			if ( $street_term_id ) {
				$last_parent_id = $street_term_id;
			}
		}
		
		// Level 6: Street Number
		if ( ! empty( $location['street_number'] ) && $this->is_level_allowed( 6 ) ) {
			$street_number_term_id = $this->get_or_create_term( $location['street_number'], $last_parent_id, $taxonomy, $locale );
		}
		
		$term_ids = array_filter( array( $continent_term_id, $country_term_id, $state_term_id, $city_term_id, $street_term_id, $street_number_term_id ) );
		
		if ( ! empty( $term_ids ) ) {
			wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
		}
	}
	
	/**
	 * Get or create term.
	 *
	 * **What:** Retrieves existing term or creates new one with proper parent relationship and slug.
	 *
	 * **Why:** Prevents duplicate terms and ensures consistent hierarchy. If a term exists
	 * but has the wrong parent (e.g., from manual creation), it fixes the relationship.
	 * Uses sanitize_title() for proper slug generation, ensuring special characters like
	 * German ß become "ss" and French accents are properly converted (e.g., é → e).
	 *
	 * **How:**
	 * 1. Sanitizes term name for display (security + consistency)
	 * 2. Generates proper slug using sanitize_title() (handles ß → ss, accents, etc.)
	 * 3. Checks if term exists using get_term_by('slug', ...)
	 *    - Looks up by slug (not name) to handle transliteration consistently
	 * 4. If term exists:
	 *    - Validates it's a WP_Term object
	 *    - Checks if parent matches expected parent_id
	 *    - Updates parent via wp_update_term() if mismatch
	 *    - Returns existing term_id
	 * 5. If term doesn't exist:
	 *    - Creates via wp_insert_term() with parent and explicit slug
	 *    - Handles errors (logs to error_log)
	 *    - Returns new term_id or 0 on failure
	 *
	 * Example scenarios:
	 * Scenario 1 - Create new with special chars:
	 *   Input: name="Große Straße", parent_id=101, taxonomy="gatherpress-location"
	 *   Slug: "grosse-strasse" (ß → ss)
	 *   Result: Creates term, returns ID 102
	 *
	 * Scenario 2 - Create with French accents:
	 *   Input: name="Café René", parent_id=101
	 *   Slug: "cafe-rene" (é → e)
	 *   Result: Creates term, returns ID 103
	 *
	 * Scenario 3 - Use existing:
	 *   Input: name="Bavaria", parent_id=101, term already exists with correct parent
	 *   Result: Returns existing ID 102
	 *
	 * Scenario 4 - Fix parent:
	 *   Input: name="Bavaria", parent_id=101, term exists with parent_id=0
	 *   Result: Updates parent to 101, returns ID 102
	 *
	 * @since 0.1.0
	 * @param string $name      Term name to find or create.
	 * @param int    $parent_id Parent term ID (0 for root level).
	 * @param string $taxonomy  Taxonomy name.
	 * @param string $locale    Country code of the retrieved address.
	 * @return int Term ID on success, 0 on failure.
	 */
	private function get_or_create_term( string $name, int $parent_id, string $taxonomy, string $locale = '' ): int {
		$name = sanitize_text_field( $name );
		// Generate proper slug using sanitize_title() which handles:
		// - German characters: ß → ss, ä → a, ö → o, ü → u
		// - French accents: é → e, è → e, ê → e, à → a, etc.
		// - Special characters: spaces → hyphens, removes unsafe chars
		
		// sanitize_title uses the sites locale to decide HOW to remove accents,
		// to stay consistent across different languages we use remove_accents directly.
		$slug = remove_accents( $name, $locale );
		$slug = sanitize_title( $slug );
		
		// Check by slug (not name) to handle transliteration consistently
		$existing_term = get_term_by( 'slug', $slug, $taxonomy );
		
		if ( $existing_term instanceof \WP_Term ) {
			if ( $existing_term->parent !== $parent_id ) {
				wp_update_term(
					$existing_term->term_id,
					$taxonomy,
					array( 'parent' => $parent_id )
				);
			}
			return $existing_term->term_id;
		}
		
		// Create term with explicit slug to ensure proper transliteration
		$term = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'parent' => $parent_id,
				'slug' => $slug,
			)
		);
		
		if ( is_wp_error( $term ) ) {
			error_log( 'GatherPress Venue Hierarchy: Failed to create term - ' . $term->get_error_message() );
			return 0;
		}
		
		if ( ! is_array( $term ) || ! isset( $term['term_id'] ) ) {
			return 0;
		}
		
		return $term['term_id'];
	}
}

if ( ! function_exists( 'gatherpress_venue_hierarchy_init' ) ) {
	/**
	 * Initialize the plugin.
	 *
	 * **What:** Bootstrap function that starts the plugin by initializing the main class.
	 *
	 * **Why:** Hooked to 'plugins_loaded' to ensure WordPress core is fully loaded before
	 * attempting to register taxonomies, blocks, or interact with other plugins (GatherPress).
	 * This prevents "class not found" errors and ensures proper initialization order.
	 *
	 * **How:** Gets singleton instance via get_instance(), which triggers the constructor
	 * and registers all hooks. Guarded by function_exists() to prevent fatal errors if
	 * plugin is loaded multiple times (rare but possible in some hosting environments).
	 *
	 * @since 0.1.0
	 * @return void
	 */
	function gatherpress_venue_hierarchy_init(): void {
		GatherPress_Venue_Hierarchy::get_instance();
	}
	add_action( 'plugins_loaded', 'gatherpress_venue_hierarchy_init' );
}