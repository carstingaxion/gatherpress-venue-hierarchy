# Developer Documentation

## Data Structure

**Taxonomy:**
* Name: gatherpress_location
* Hierarchical: true
* Post types: gatherpress_event
* REST API: enabled
* URL structure: /events/in/{term}/{child-term}/
* Rewrite: hierarchical (pretty URLs)
* Admin column: visible
* Default ordering: by parent (maintains hierarchy)

**Location Data Array:**
```php
array(
    'continent'      => string, // e.g., "Europe" (translated)
    'country'        => string, // e.g., "Germany"
    'country_code'   => string, // e.g., "de" (lowercase)
    'state'          => string, // e.g., "Bavaria" (or city name for city-states)
    'city'           => string, // e.g., "Munich" (or suburb for city-states)
    'street'         => string, // e.g., "Marienplatz"
    'street_number'  => string, // e.g., "1"
)
```

## Technical Implementation

**Geocoding Process:**

1. When a GatherPress event is saved (priority 20, after GatherPress core processes at priority 10), the plugin retrieves venue information via GatherPress\Core\Event::get_venue_information()
2. The full address is sent to Nominatim API (https://nominatim.openstreetmap.org/search) with format=json, addressdetails=1, and site language
3. API response includes address components: house_number, road/street, city/town/village, state/region/province, country, country_code
4. Continent is derived from country_code using an internal mapping with WordPress core translations
5. Results are cached as transients with key format: gpvh_geocode_{md5(address)}
6. Cache duration: 3600 seconds (1 hour)
7. Only geocodes when location terms are missing (checks wp_get_object_terms)

**Hierarchy Building:**

1. Terms are created in top-down order: Continent → Country → State → City → Street → Street+Number
2. Each term stores its parent's term_id, creating a parent-child chain
3. Street number terms combine street name and number (e.g., "Main St 123") to avoid numerous single-digit terms
4. Before creating a term, the plugin checks if it exists by slug (not name)
5. Uses sanitize_title() for proper slug generation (handles ß→ss, accents→ascii, special characters)
6. If a term exists with incorrect parent, the plugin updates the parent relationship
7. All term IDs are associated with the event using wp_set_object_terms()
8. Respects hierarchy level filter to only create terms within configured range
9. Applies 'gatherpress_location_hierarchy_term_args' filter before term insertion
10. Country terms use country_code as slug via filter

**Special Handling:**

* German-speaking regions (DE, AT, CH, LU): Uses 'state' field directly for Bundesland/Canton
* City-states (e.g., Berlin where state field is empty):
  - Uses city name as state level
  - Uses suburb (fallback: borough) as city level
  - Creates hierarchy: Europe > Germany > Berlin > Prenzlauer Berg > Street > Number
* Other countries: Falls back through state → region → province for administrative divisions
* City extraction: city → town → village → county (urban to rural priority)
* Street extraction: road → street → pedestrian (common field name variations)
* Slug generation: Uses remove_accents() with locale parameter for consistent transliteration

**Canonical URL Handling:**

* Adds canonical link tags to taxonomy archive pages when a term has only one child
* Points to child term's archive to consolidate SEO value and prevent duplicate content
* Creates canonical chains: grandparent → parent → child (leaf)
* Runs on wp_head hook (priority 1) for early placement in <head>
* Only affects location taxonomy archives

**Hierarchy Level Filtering:**

* WordPress filter: 'gatherpress_location_hierarchy_levels'
* Default range: [1, 6] (all levels)
* Example: [2, 4] restricts to Country, State, City only
* Affects both term creation and block display
* Level mapping:
  - 1 = Continent
  - 2 = Country
  - 3 = State
  - 4 = City
  - 5 = Street
  - 6 = Street Number

## Class Architecture

**Setup** (Singleton)
* Registers taxonomy and block
* Hooks into save_post_gatherpress_event (priority 20)
* Manages settings page
* Coordinates geocoding and hierarchy building
* Provides get_allowed_levels() method
* Localizes filter data to block editor
* Adds canonical URLs for single-child terms

**Geocoder** (Singleton)
* Handles Nominatim API communication
* Manages transient caching (1-hour duration)
* Parses API responses
* Maps countries to continents using WordPress i18n
* Sends site language to API for localized results
* Handles German-speaking regions specially
* Handles city-states (Berlin) with suburb fallback

**Builder** (Singleton)
* Creates taxonomy terms
* Establishes parent-child relationships
* Updates incorrect parent assignments
* Associates terms with events
* Uses sanitize_title() for proper slug generation
* Checks allowed levels before creating terms
* Applies filter before term insertion
* Uses country codes as slugs for countries

**Block_Renderer** (Singleton)
* Renders block on frontend
* Retrieves location terms
* Builds hierarchical paths
* Formats output with optional links
* Preserves whitespace in separator
* Accounts for allowed level range offset
* Validates post context (must be gatherpress_event)

## Code Examples

**Get all location terms for an event:**
```php
$terms = wp_get_object_terms(
    $event_id,
    'gatherpress_location',
    array( 'orderby' => 'parent', 'order' => 'ASC' )
);
```

**Query events in a specific country:**
```php
$events = new WP_Query( array(
    'post_type' => 'gatherpress_event',
    'tax_query' => array(
        array(
            'taxonomy' => 'gatherpress_location',
            'field'    => 'slug',
            'terms'    => 'de', // Country code as slug
        ),
    ),
) );
```

**Get hierarchical term path:**
```php
$term = get_term_by( 'slug', 'munich', 'gatherpress_location' );
$path = array();
$current = $term;
while ( $current ) {
    array_unshift( $path, $current->name );
    $current = $current->parent ? get_term( $current->parent ) : null;
}
// Result: ['Europe', 'Germany', 'Bavaria', 'Munich']
```

**Configure hierarchy levels:**
```php
add_filter( 'gatherpress_location_hierarchy_levels', function() {
    return [2, 4]; // Country, State, City only
} );
```

**Customize term attributes:**
```php
add_filter( 'gatherpress_location_hierarchy_term_args', function( $args ) {
    // Example: Add custom meta or modify name
    if ( 2 === $args['level'] ) { // Country level
        // Country already uses country_code as slug by default
        $args['slug'] = $args['location']['country_code'];
    }
    return $args;
} );
```

**Query events by multiple locations:**
```php
$events = new WP_Query( array(
    'post_type' => 'gatherpress_event',
    'tax_query' => array(
        'relation' => 'OR',
        array(
            'taxonomy' => 'gatherpress_location',
            'field'    => 'slug',
            'terms'    => 'bavaria',
        ),
        array(
            'taxonomy' => 'gatherpress_location',
            'field'    => 'slug',
            'terms'    => 'saxony',
        ),
    ),
) );
```

## Hooks and Filters

**Filters:**

* `gatherpress_location_hierarchy_term_args` - Customize term attributes
  - Receives: ['name', 'slug', 'parent', 'taxonomy', 'level', 'location']
  - Return: Modified args array
  - Used for country code slugs
