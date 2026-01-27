# GatherPress Venue Hierarchy

**Contributors:**      carstenbach & WordPress Telex  
**Tags:**              block, gatherpress, venue, hierarchy, geocoding, location, events  
**Tested up to:**      6.8  
**Stable tag:**        0.1.0  
**License:**           GPLv2 or later  
**License URI:**       https://www.gnu.org/licenses/gpl-2.0.html  
**Requires Plugins:**  gatherpress  

Automatically creates hierarchical location taxonomy for GatherPress events using geocoded address data.

## Description

This plugin extends GatherPress by adding a hierarchical location taxonomy. When an event is saved, the plugin geocodes the venue address and creates taxonomy terms organized by continent, country, state, city, street, and street number. A Gutenberg block displays these hierarchies with configurable level filtering.

### What This Plugin Does

* Creates a custom hierarchical taxonomy "gatherpress-location"
* Geocodes venue addresses using the Nominatim (OpenStreetMap) API
* Automatically generates taxonomy terms in 7 levels: Continent > Country > State > City > Street > Street+Number
* Establishes parent-child relationships between terms
* Associates created terms with events
* Provides a Gutenberg block for displaying location hierarchies
* Caches API responses for 1 hour using WordPress transients

### Technical Implementation

**Geocoding Process:**

1. When a GatherPress event is saved (priority 20, after GatherPress core processes at priority 10), the plugin retrieves venue information via GatherPress\Core\Event::get_venue_information()
2. The full address is sent to Nominatim API (https://nominatim.openstreetmap.org/search) with format=json and addressdetails=1
3. API response includes address components: house_number, road/street, city/town/village, state/region/province, country, country_code
4. Continent is derived from country_code using an internal mapping with WordPress core translations
5. Results are cached as transients with key format: gpvh_geocode_{md5(address)}
6. Cache duration: 3600 seconds (1 hour)

**Hierarchy Building:**

1. Terms are created in top-down order: Continent → Country → State → City → Street → Street+Number
2. Each term stores its parent's term_id, creating a parent-child chain
3. Street number terms combine street name and number (e.g., "Main St 123") to avoid numerous single-digit terms
4. Before creating a term, the plugin checks if it exists by name
5. If a term exists with incorrect parent, the plugin updates the parent relationship
6. All term IDs are associated with the event using wp_set_object_terms()

**Special Handling:**

* German-speaking regions (DE, AT, CH, LU): Uses 'state' field directly for Bundesland/Canton
* Other countries: Falls back through state → region → province for administrative divisions
* City extraction: city → town → village → county (urban to rural priority)
* Street extraction: road → street → pedestrian (common field name variations)

### Display Block Features

**Hierarchy Level Control:**

* Dual-handle range control for selecting start and end levels
* 7 levels available: Continent, Country, State, City, Street, Number
* Staggered label layout (alternating top/bottom rows) for readability
* Real-time preview of selected range

**Display Options:**

* Customizable separator between terms (default: " > ")
* Optional term links to taxonomy archive pages
* Optional venue display at end of hierarchy
* Venue links to GatherPress venue post when enabled
* Full WordPress block editor support (alignment, colors, spacing)

**Implementation Details:**

* Editor: Uses useSelect hook to query location terms and venue taxonomy
* Frontend: Singleton renderer class (GatherPress_Venue_Hierarchy_Block_Renderer)
* Term retrieval: Ordered by parent relationship to maintain hierarchy
* Path building: Identifies leaf terms (deepest in hierarchy), builds paths by traversing parents
* Level filtering: Uses array_slice on complete paths based on startLevel/endLevel

### Use Cases

**Multi-Location Event Series:**
Organizations running events in multiple cities can filter by geographic level. Events are queryable at any hierarchy level using standard WordPress taxonomy queries.

**Regional Event Management:**
Local chapters or regional groups can organize events by state or city while maintaining connection to parent geographic regions.

**International Event Coordination:**
Global organizations can display continent-level groupings while drilling down to specific cities and venues.

## Installation

### Requirements

* WordPress 6.0 or higher
* PHP 7.4 or higher
* GatherPress plugin installed and activated

### Installation Steps

1. Upload plugin files to `/wp-content/plugins/gatherpress-venue-hierarchy/`
2. Activate the plugin through the WordPress Plugins menu
3. Plugin registers taxonomy and block automatically on activation

### Configuration

1. Navigate to Settings > GatherPress Location
2. Configure default geographic terms (optional)
3. Create or edit GatherPress events with venue addresses
4. Location terms are generated automatically on save
5. Add "Location Hierarchy Display" block to templates or posts

## Frequently Asked Questions

### How does geocoding work?

The plugin sends venue addresses to Nominatim API (OpenStreetMap). Response includes coordinates and address components. Results are cached as WordPress transients for 1 hour. Cache key format: `gpvh_geocode_{md5(address)}`.

### What happens if geocoding fails?

Events without location terms can have terms added manually through WordPress admin. Geocoding will retry automatically when the event is saved again.

### Can I manually edit location terms?

Yes. Terms are standard WordPress taxonomy terms accessible through admin interface. Manual edits persist unless geocoding recreates terms (occurs when all terms are deleted and event is resaved).

### What is the API usage policy?

Nominatim is free but has usage limits. Review OpenStreetMap's Nominatim Usage Policy. For high-volume sites, consider:
* Self-hosted Nominatim instance
* Commercial geocoding service
* Extended cache duration

### What regions are supported?

All regions returned by Nominatim are supported. Enhanced handling for German-speaking regions (DE, AT, CH, LU) uses specific administrative structure (Bundesländer/Cantons).

### How does this relate to GatherPress venues?

This plugin creates a separate "gatherpress-location" taxonomy. GatherPress's venue system (venue post type and `_gatherpress_venue` taxonomy) remains unchanged. The location taxonomy provides geographic organization while GatherPress manages venue details (address, phone, website).

### How do I query events by location?

Use standard WordPress taxonomy queries:

```php
$args = array(
    'post_type' => 'gatherpress_event',
    'tax_query' => array(
        array(
            'taxonomy' => 'gatherpress-location',
            'field'    => 'slug',
            'terms'    => 'bavaria',
        ),
    ),
);
$query = new WP_Query( $args );
```

### How do I display only specific hierarchy levels?

Use the block's dual-range control:
* Continent only: Levels 1-1
* Country through City: Levels 2-4
* City and Street: Levels 4-5
* Full hierarchy: Levels 1-7 (or use default 1-999)

### Can I customize block appearance?

The block supports WordPress color controls (text, background, link). Custom CSS can target `.wp-block-telex-block-gatherpress-venue-hierarchy` class.

### Does this affect performance?

Performance considerations:
* API calls only occur when events are saved (not on page load)
* 1-hour caching reduces API requests
* Singleton pattern prevents duplicate queries
* Standard WordPress taxonomy queries (optimized)
* No frontend JavaScript required

## Screenshots

1. Settings page for default geographic terms
2. Block editor showing dual-range level control
3. Block inspector with display options
4. Frontend display with linked terms
5. Location taxonomy in WordPress admin
6. Event list with location hierarchy column
7. Staggered label layout in dual-range control

## Changelog

### 0.1.0
* Initial release
* Hierarchical gatherpress-location taxonomy (7 levels)
* Nominatim API integration with 1-hour caching
* Automatic term creation with parent relationships
* Country-to-continent mapping with WordPress i18n
* Gutenberg block with dual-range level control
* Customizable separator between terms
* Optional term links to archive pages
* Optional venue display with link support
* Enhanced German-speaking region support
* Street and street number handling
* Combined street+number display option
* Staggered label layout for 7-level range control
* REST API integration
* PHPStan-compatible type hints
* Comprehensive docblocks
* Singleton pattern implementation

## Developer Documentation

### Data Structure

**Taxonomy:**
* Name: gatherpress-location
* Hierarchical: true
* Post types: gatherpress_event
* REST API: enabled
* URL structure: /location/{term}/{child-term}/

**Location Data Array:**
```php
array(
    'continent'      => string, // e.g., "Europe"
    'country'        => string, // e.g., "Germany"
    'country_code'   => string, // e.g., "de"
    'state'          => string, // e.g., "Bavaria"
    'city'           => string, // e.g., "Munich"
    'street'         => string, // e.g., "Marienplatz"
    'street_number'  => string, // e.g., "1"
)
```

### Class Architecture

**GatherPress_Venue_Hierarchy** (Singleton)
* Registers taxonomy and block
* Hooks into save_post_gatherpress_event (priority 20)
* Manages settings page
* Coordinates geocoding and hierarchy building

**GatherPress_Venue_Geocoder** (Singleton)
* Handles Nominatim API communication
* Manages transient caching
* Parses API responses
* Maps countries to continents

**GatherPress_Venue_Hierarchy_Builder** (Singleton)
* Creates taxonomy terms
* Establishes parent-child relationships
* Updates incorrect parent assignments
* Associates terms with events

**GatherPress_Venue_Hierarchy_Block_Renderer** (Singleton)
* Renders block on frontend
* Retrieves location terms
* Builds hierarchical paths
* Formats output with optional links

### Code Examples

**Get all location terms for an event:**
```php
$terms = wp_get_object_terms(
    $event_id,
    'gatherpress-location',
    array( 'orderby' => 'parent', 'order' => 'ASC' )
);
```

**Query events in a specific country:**
```php
$events = new WP_Query( array(
    'post_type' => 'gatherpress_event',
    'tax_query' => array(
        array(
            'taxonomy' => 'gatherpress-location',
            'field'    => 'slug',
            'terms'    => 'germany',
        ),
    ),
) );
```

**Get hierarchical term path:**
```php
$term = get_term_by( 'slug', 'munich', 'gatherpress-location' );
$path = array();
$current = $term;
while ( $current ) {
    array_unshift( $path, $current->name );
    $current = $current->parent ? get_term( $current->parent ) : null;
}
// Result: ['Europe', 'Germany', 'Bavaria', 'Munich']
```

### Extending Functionality

The plugin uses WordPress hooks and standard taxonomy functions. Custom functionality can be added using standard WordPress filters and actions. All classes use singleton pattern with private constructors.

## Privacy

This plugin sends venue addresses to Nominatim API (https://nominatim.openstreetmap.org) when events are saved. Only venue addresses are transmitted. No user data or personal information is sent.

Geocoding results are cached locally in the WordPress database using transients (1-hour expiration). No data is sent to services other than Nominatim.

Review OpenStreetMap's privacy policy at: https://wiki.osmfoundation.org/wiki/Privacy_Policy

## Credits

This plugin uses the Nominatim API provided by OpenStreetMap Foundation. Nominatim is licensed under GPL v2.