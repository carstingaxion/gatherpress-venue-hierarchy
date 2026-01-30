/**
 * File: src/edit.js
 * Path: src/edit.js
 * 
 * Main edit component for the Location Hierarchy Display block.
 * Contains all editor functionality including:
 * - Block inspector controls (settings panel)
 * - Custom dual-range control component
 * - Custom hooks for data fetching and hierarchy building
 * - Main edit component rendering
 */

/**
 * Retrieves the translation of text.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-i18n/
 */
import { __ } from '@wordpress/i18n';

/**
 * React hook that is used to mark the block wrapper element.
 * It provides all the necessary props like the class name.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-block-editor/#useblockprops
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { 
	PanelBody, 
	ToggleControl, 
	TextControl,
	Spinner 
} from '@wordpress/components';
import { RawHTML, useState, useEffect } from '@wordpress/element';
import { useEntityProp } from '@wordpress/core-data';
import { useSelect, useDispatch } from '@wordpress/data';

/**
 * Lets webpack process CSS, SASS or SCSS files referenced in JavaScript files.
 * Those files can contain any CSS code that gets applied to the editor.
 *
 * @see https://www.npmjs.com/package/@wordpress/scripts#using-css
 */
import './editor.scss';

/**
 * ============================================================================
 * CUSTOM HOOKS
 * ============================================================================
 */

/**
 * Hook: useAllowedLevels
 * File: Originally src/hooks/useAllowedLevels.js
 * 
 * Gets allowed hierarchy levels from localized script data.
 * 
 * The PHP side filters which hierarchy levels are available (e.g., continent to city only).
 * This hook provides that configuration to the editor so the dual-range control can adjust
 * its bounds and prevent users from selecting unavailable levels.
 * 
 * Reads from window.gatherPressLocationHierarchy object, which is populated via
 * wp_localize_script() in PHP. Provides fallback defaults (1-6) if data is missing.
 * 
 * @since 0.1.0
 * @return {{minLevel: number, maxLevel: number}} Object with minLevel and maxLevel properties.
 *                                                 minLevel: Minimum allowed hierarchy level (1=Continent).
 *                                                 maxLevel: Maximum allowed hierarchy level (6=Street Number).
 * 
 * @example
 * const { minLevel, maxLevel } = useAllowedLevels();
 * console.log(minLevel); // 1 (or configured minimum)
 * console.log(maxLevel); // 6 (or configured maximum)
 */
function useAllowedLevels() {
	const { allowedLevels } = window.gatherPressLocationHierarchy || {};
	const minLevel = allowedLevels?.min || 1;
	const maxLevel = allowedLevels?.max || 6;
	
	return { minLevel, maxLevel };
}

/**
 * Hook: usePostContext
 * File: Originally src/hooks/usePostContext.js
 * 
 * Gets post context information including post ID, type, and query loop status.
 * 
 * The block needs to know which post it's rendering in to fetch the correct location
 * data. Context behavior differs between single posts and query loops, requiring different
 * handling for each case.
 * 
 * Reads from the block's context object (provided by WordPress), extracts post ID,
 * post type, and query ID. Determines if block is in a query loop and validates that the
 * post is a GatherPress event.
 * 
 * @since 0.1.0
 * @param {Object} context Block context from WordPress containing post information.
 * @param {number} [context.postId] Post ID of the current post.
 * @param {string} [context.postType] Post type of the current post.
 * @param {number} [context.queryId] Query ID if block is inside a query loop.
 * @return {{postId: number, postType: string, isInQueryLoop: boolean, isValidContext: boolean}} Context information.
 *         postId: The post ID (0 if not available).
 *         postType: The post type string (empty if not available).
 *         isInQueryLoop: True if block is inside a query loop.
 *         isValidContext: True if post is a gatherpress_event with valid ID.
 * 
 * @example
 * const { postId, postType, isInQueryLoop, isValidContext } = usePostContext( context );
 * if ( isValidContext ) {
 *   // Fetch and display location data
 * }
 */
function usePostContext( context ) {
	const postId = context?.postId || 0;
	const postType = context?.postType || '';
	const queryId = context?.queryId;
	
	const isInQueryLoop = !! queryId;
	const isValidContext = postType === 'gatherpress_event' && postId > 0;
	
	return {
		postId,
		postType,
		isInQueryLoop,
		isValidContext,
	};
}

/**
 * Hook: useLocationData
 * File: Originally src/hooks/useLocationData.js
 * 
 * Fetches location terms and venue information for an event using useEntityProp for direct reactivity.
 * 
 * The editor needs to display location hierarchy and optionally venue information.
 * Using useEntityProp provides direct access to post entity properties with automatic reactivity,
 * ensuring immediate updates when terms are modified in the sidebar panel.
 * 
 * 
 * - Uses useEntityProp to directly access taxonomy term IDs from the post entity
 * - Fetches gatherpress_location taxonomy term IDs
 * - Optionally fetches _gatherpress_venue taxonomy term ID if showVenue is true
 * - Uses useSelect to resolve term IDs into full term objects with names and links
 * - Returns both data and loading state for proper loading UI
 * - Automatically re-renders when term IDs change (sidebar edits)
 * - Monitors post save status to trigger data refresh
 * 
 * @since 0.1.0
 * @param {number} postId Post ID of the event to fetch data for.
 * @param {boolean} showVenue Whether to fetch venue information.
 * @return {{locationTerms: Array<Object>, venueName: string, venueLink: string, isLoading: boolean}} Location data.
 *         locationTerms: Array of term objects with properties: id, name, parent, link.
 *         venueName: Name of the venue (empty string if not available or not requested).
 *         venueLink: URL to venue post (empty string if not available or not requested).
 *         isLoading: True while data is being fetched from the store.
 * 
 * @example
 * const { locationTerms, venueName, venueLink, isLoading } = useLocationData( postId, true );
 * if ( isLoading ) {
 *   return <Spinner />;
 * }
 * console.log( locationTerms ); // [{ id: 1, name: 'Europe', parent: 0, link: '/events/in/europe/' }, ...]
 * console.log( venueName ); // 'Main Conference Hall'
 */
function useLocationData( postId, showVenue, refreshTrigger ) {
// console.log( '[useLocationData] Called with postId:', postId, 'showVenue:', showVenue, 'refreshTrigger:', refreshTrigger );

	const { invalidateResolution } = useDispatch( 'core' );
	
	// When refreshTrigger changes, invalidate the entity record 
	useEffect( () => {
		if ( refreshTrigger > 0 && postId ) {
			// Invalidate the entity record to force refetch 
			invalidateResolution( 'getEntityRecord', [
				'postType', 
				'gatherpress_event', 
				postId
			] );
		}
	}, [ refreshTrigger, postId, invalidateResolution ] );
	

// Get location term IDs using useEntityProp for direct reactivity
	const [ locationTermIds ] = useEntityProp(
		'postType',
		'gatherpress_event',
		'gatherpress_location',
		postId
	);
	
	// Get venue term IDs using useEntityProp if showVenue is true
	const [ venueTermIds ] = useEntityProp(
		'postType',
		'gatherpress_event',
		'_gatherpress_venue',
		showVenue ? postId : null
	);
	
	// Resolve term IDs to full term objects
	const { locationTerms, venueName, venueLink, isResolving } = useSelect(
		( select ) => {
			if ( ! postId ) {
				return {
					locationTerms: [],
					venueName: '',
					venueLink: '',
					isResolving: false,
				};
			}
			
			const { getEntityRecord, isResolving: checkResolving } = select( 'core' );
			
			// Resolve location term IDs to full term objects
			const terms = [];
			let termsResolving = false;
			
			if ( locationTermIds && Array.isArray( locationTermIds ) && locationTermIds.length > 0 ) {
				for ( const termId of locationTermIds ) {
					const term = getEntityRecord( 'taxonomy', 'gatherpress_location', termId );
					if ( term ) {
						terms.push( term );
					}
					if ( checkResolving( 'getEntityRecord', [ 'taxonomy', 'gatherpress_location', termId ] ) ) {
						termsResolving = true;
					}
				}
			}
			
			// Resolve venue term ID to name and link
			let venue = '';
			let link = '';
			let venueResolving = false;
			
			if ( showVenue && venueTermIds && Array.isArray( venueTermIds ) && venueTermIds.length > 0 ) {
				const venueTermId = venueTermIds[ 0 ];
				const venueTerm = getEntityRecord( 'taxonomy', '_gatherpress_venue', venueTermId );
				
				if ( venueTerm ) {
					venue = venueTerm.name || '';
					link = venueTerm.link || '';
				}
				
				if ( checkResolving( 'getEntityRecord', [ 'taxonomy', '_gatherpress_venue', venueTermId ] ) ) {
					venueResolving = true;
				}
			}
			
			return {
				locationTerms: terms,
				venueName: venue,
				venueLink: link,
				isResolving: termsResolving || venueResolving,
			};
		},
		[ postId, showVenue, locationTermIds, venueTermIds, refreshTrigger ]
	);
	
	return {
		locationTerms,
		venueName,
		venueLink,
		isLoading: isResolving,
	};
}

/**
 * Hook: useLocationHierarchy
 * File: Originally src/hooks/useLocationHierarchy.js
 * 
 * Builds location hierarchy display string from terms, applying level filtering
 * and formatting with links/separators.
 * 
 * Terms come from the database as flat array with parent relationships. Need to:
 * - Build hierarchical paths (Europe > Germany > Bavaria > Munich)
 * - Filter to selected level range (e.g., only show Country to City)
 * - Format with custom separator and optional links
 * - Handle edge cases (no terms, query loop context, venue-only display)
 * 
 * **How:**
 * - Uses useState to store the built hierarchy string
 * - Uses useEffect to rebuild hierarchy when dependencies change
 * - Calls buildHierarchyPaths() helper to construct paths from terms
 * - Applies level filtering based on start/end levels and min/max constraints
 * - Appends venue information if requested
 * - Returns both hierarchy string and loading state
 * 
 * @since 0.1.0
 * @param {number} postId Post ID.
 * @param {Array<Object>} locationTerms Array of location term objects from useLocationData.
 * @param {string} venueName Venue name from useLocationData.
 * @param {string} venueLink Venue link URL from useLocationData.
 * @param {number} startLevel Starting hierarchy level (1-6).
 * @param {number} endLevel Ending hierarchy level (1-6).
 * @param {number} minLevel Minimum allowed level from filter.
 * @param {number} maxLevel Maximum allowed level from filter.
 * @param {boolean} enableLinks Whether to wrap terms in links.
 * @param {boolean} showVenue Whether to append venue to hierarchy.
 * @param {string} separator String to place between terms (e.g., " > ").
 * @param {boolean} isInQueryLoop Whether block is in a query loop (affects placeholder text).
 * @return {{locationHierarchy: string, isLoading: boolean}} Hierarchy display data.
 *         locationHierarchy: HTML string with formatted hierarchy (may contain <a> tags).
 *         isLoading: True while hierarchy is being built.
 * 
 * @example
 * const { locationHierarchy, isLoading } = useLocationHierarchy(
 *   123,
 *   locationTerms,
 *   'Main Hall',
 *   '/venue/main-hall/',
 *   1,
 *   4,
 *   1,
 *   6,
 *   true,
 *   true,
 *   ' > ',
 *   false
 * );
 * // Returns: { locationHierarchy: '<a href="...">Europe</a> > <a href="...">Germany</a> > <a href="...">Bavaria</a> > <a href="...">Munich</a> > <a href="...">Main Hall</a>', isLoading: false }
 */
function useLocationHierarchy(
	postId,
	locationTerms,
	venueName,
	venueLink,
	startLevel,
	endLevel,
	minLevel,
	maxLevel,
	enableLinks,
	showVenue,
	separator,
	isInQueryLoop
) {
	const [ hierarchy, setHierarchy ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( true );
	
	useEffect( () => {
		setIsLoading( true );
		
		if ( ! postId ) {
			setHierarchy( __( 'No post ID available', 'gatherpress-location-hierarchy' ) );
			setIsLoading( false );
			return;
		}
		
		if ( ! locationTerms || locationTerms.length === 0 ) {
			if ( showVenue && venueName ) {
				// Show just the venue if no location terms
				const venueText = enableLinks && venueLink
					? `<a href="${ venueLink }" class="gatherpress-location-link gatherpress-venue-link" onclick="event.preventDefault();">${ venueName }</a>`
					: venueName;
				setHierarchy( venueText );
			} else {
				const message = isInQueryLoop
					? __( 'Location hierarchy will appear here for matching events', 'gatherpress-location-hierarchy' )
					: __( 'No location hierarchy available for this event', 'gatherpress-location-hierarchy' );
				setHierarchy( message );
			}
			setIsLoading( false );
			return;
		}
		
		// Build hierarchy from terms
		const paths = buildHierarchyPaths(
			locationTerms,
			startLevel,
			endLevel,
			minLevel,
			enableLinks,
			separator
		);
		
		if ( paths.length === 0 ) {
			if ( showVenue && venueName ) {
				// Show just the venue if no paths after filtering
				const venueText = enableLinks && venueLink
					? `<a href="${ venueLink }" class="gatherpress-location-link gatherpress-venue-link" onclick="event.preventDefault();">${ venueName }</a>`
					: venueName;
				setHierarchy( venueText );
			} else {
				setHierarchy( __( 'No location hierarchy available for selected levels', 'gatherpress-location-hierarchy' ) );
			}
			setIsLoading( false );
			return;
		}
		
		let hierarchyText = paths.join( ', ' );
		
		// Add venue if requested
		if ( showVenue && venueName ) {
			const venueText = enableLinks && venueLink
				? `<a href="${ venueLink }" class="gatherpress-location-link gatherpress-venue-link" onclick="event.preventDefault();">${ venueName }</a>`
				: venueName;
			hierarchyText += separator + venueText;
		}
		
		setHierarchy( hierarchyText );
		setIsLoading( false );
	}, [
		postId,
		locationTerms,
		venueName,
		venueLink,
		startLevel,
		endLevel,
		minLevel,
		maxLevel,
		enableLinks,
		showVenue,
		separator,
		isInQueryLoop,
	] );
	
	return { locationHierarchy: hierarchy, isLoading };
}

/**
 * Helper function: buildHierarchyPaths
 * 
 * Constructs hierarchical path strings from flat term array.
 * 
 * Terms come as flat array with parent IDs. Need to:
 * - Identify leaf terms (most specific, deepest in hierarchy)
 * - Build full paths by traversing parent relationships
 * - Filter paths to selected level range
 * - Format with custom separator
 * 
 * **How:**
 * - Finds leaf terms (terms whose IDs don't appear as parents)
 * - For each leaf, calls buildTermPath() to construct full path
 * - Applies level filtering via array slicing
 * - Joins path segments with separator
 * - Returns array of formatted path strings
 * 
 * @since 0.1.0
 * @param {Array<Object>} terms Array of term objects with id, name, parent, link properties.
 * @param {number} startLevel Starting level for filtering (1-6).
 * @param {number} endLevel Ending level for filtering (1-6).
 * @param {number} minLevel Minimum level from configuration (offset for index calculations).
 * @param {boolean} enableLinks Whether to wrap terms in <a> tags.
 * @param {string} separator String to join path segments.
 * @return {Array<string>} Array of formatted hierarchy path strings.
 * 
 * @example
 * const paths = buildHierarchyPaths(
 *   [{ id: 1, name: 'Europe', parent: 0 }, { id: 2, name: 'Germany', parent: 1 }],
 *   1,
 *   2,
 *   1,
 *   false,
 *   ' > '
 * );
 * // Returns: ['Europe > Germany']
 */
function buildHierarchyPaths( terms, startLevel, endLevel, minLevel, enableLinks, separator ) {
	if ( ! terms || terms.length === 0 ) {
		return [];
	}
	
	// Find leaf terms
	const termIds = terms.map( ( t ) => t.id );
	const parentIds = terms.map( ( t ) => t.parent );
	
	const leafTerms = terms.filter( ( term ) => ! parentIds.includes( term.id ) );
	
	const useTerms = leafTerms.length > 0 ? leafTerms : terms;
	
	// Build paths
	const paths = [];
	
	useTerms.forEach( ( term ) => {
		const fullPath = buildTermPath( term, terms, enableLinks );
		
		if ( fullPath.length === 0 ) {
			return;
		}
		
		// Filter path by levels
		const pathLength = fullPath.length;
		const startIndex = Math.max( 0, startLevel - minLevel );
		const endIndex = Math.min( pathLength, endLevel - minLevel + 1 );
		
		if ( startIndex >= pathLength ) {
			return;
		}
		
		const filteredPath = fullPath.slice( startIndex, endIndex );
		
		if ( filteredPath.length > 0 ) {
			paths.push( filteredPath.join( separator ) );
		}
	} );
	
	return paths;
}

/**
 * Helper function: buildTermPath
 * 
 * Recursively builds complete path from child term to root by traversing parent relationships.
 * 
 * Each term only knows its immediate parent, not full ancestry. Need to traverse
 * up the hierarchy to build complete paths like "Europe > Germany > Bavaria > Munich".
 * Loop detection prevents infinite recursion if relationships are corrupted.
 * 
 * **How:**
 * - Starts with given term (usually a leaf)
 * - Loops while current term exists:
 *   - Checks if term already visited (prevents infinite loops)
 *   - Formats term name with optional link
 *   - Adds to path array (using unshift for root-to-leaf order)
 *   - If term has parent, finds parent in terms array and continues
 *   - If no parent, breaks loop (reached root)
 * - Max depth of 10 prevents runaway recursion
 * - Returns array of formatted term strings
 * 
 * @since 0.1.0
 * @param {Object} term Starting term object with id, name, parent, link properties.
 * @param {Array<Object>} allTerms Full array of all terms (needed to find parents).
 * @param {boolean} enableLinks Whether to wrap each term in <a> tag.
 * @return {Array<string>} Array of formatted term names/links from root to current term.
 * 
 * @example
 * const path = buildTermPath(
 *   { id: 4, name: 'Munich', parent: 3, link: '/events/in/.../munich/' },
 *   allTerms,
 *   true
 * );
 * // Returns: ['<a href="...">Europe</a>', '<a href="...">Germany</a>', '<a href="...">Bavaria</a>', '<a href="...">Munich</a>']
 */
function buildTermPath( term, allTerms, enableLinks ) {
	const path = [];
	let currentTerm = term;
	const visited = [];
	const maxDepth = 10;
	let depth = 0;
	
	while ( currentTerm && depth < maxDepth ) {
		if ( visited.includes( currentTerm.id ) ) {
			break;
		}
		
		visited.push( currentTerm.id );
		
		const termText = enableLinks && currentTerm.link
			? `<a href="${ currentTerm.link }" class="gatherpress-location-link" onclick="event.preventDefault();">${ currentTerm.name }</a>`
			: currentTerm.name;
		
		path.unshift( termText );
		
		if ( currentTerm.parent ) {
			const parentTerm = allTerms.find( ( t ) => t.id === currentTerm.parent );
			
			if ( ! parentTerm ) {
				break;
			}
			
			currentTerm = parentTerm;
		} else {
			break;
		}
		
		depth++;
	}
	
	return path;
}

/**
 * ============================================================================
 * COMPONENTS
 * ============================================================================
 */

/**
 * Component: DualRangeControl
 * File: Originally src/components/DualRangeControl.js
 * 
 * Custom dual-handle range control for selecting hierarchy level range with visual track.
 * 
 * WordPress doesn't provide a dual-range control component. Need to allow users to
 * select both start and end levels on a single slider to define which hierarchy levels to display.
 * Standard approach would require two separate controls, making the relationship unclear.
 * 
 * **How:**
 * - Renders a horizontal track with two draggable handles
 * - Displays level labels (Continent, Country, etc.) above/below track in staggered layout
 * - Handles mouse events for dragging handles and clicking track
 * - Converts mouse position to level values
 * - Enforces constraints (start <= end, within min/max bounds)
 * - Shows selected range in output display below control
 * - Uses CSS for visual styling and animations
 * - Uses semantic <label> elements for accessibility
 * 
 * @since 0.1.0
 * @param {Object} props Component props.
 * @param {string} [props.label] Label text to display above control.
 * @param {number} props.minLevel Minimum allowed level (e.g., 1 for Continent).
 * @param {number} props.maxLevel Maximum allowed level (e.g., 6 for Street Number).
 * @param {number} props.startLevel Currently selected start level.
 * @param {number} props.endLevel Currently selected end level.
 * @param {Function} props.onChange Callback function called when levels change.
 *                                   Receives object: { startLevel: number, endLevel: number }
 * @return {JSX.Element} Rendered dual-range control component.
 * 
 * @example
 * <DualRangeControl
 *   label="Hierarchy Levels"
 *   minLevel={1}
 *   maxLevel={6}
 *   startLevel={2}
 *   endLevel={4}
 *   onChange={({ startLevel, endLevel }) => setAttributes({ startLevel, endLevel })}
 * />
 */
function DualRangeControl( { label, minLevel, maxLevel, startLevel, endLevel, onChange } ) {
	const [ isDraggingStart, setIsDraggingStart ] = useState( false );
	const [ isDraggingEnd, setIsDraggingEnd ] = useState( false );
	const [ trackRef, setTrackRef ] = useState( null );
	
	const levelLabels = [
		__( 'Continent', 'gatherpress-location-hierarchy' ),
		__( 'Country', 'gatherpress-location-hierarchy' ),
		__( 'State', 'gatherpress-location-hierarchy' ),
		__( 'City', 'gatherpress-location-hierarchy' ),
		__( 'Street', 'gatherpress-location-hierarchy' ),
		__( 'Number', 'gatherpress-location-hierarchy' ),
	];
	
	// Filter labels to only show those within allowed range
	const visibleLabels = levelLabels.slice( minLevel - 1, maxLevel );
	const numLevels = maxLevel - minLevel + 1;
	
	/**
	 * Get handle position as percentage.
	 * 
	 * @param {number} level Level number to convert to percentage.
	 * @return {number} Position as percentage (0-100).
	 */
	const getPositionPercent = ( level ) => {
		if ( numLevels <= 1 ) return 0;
		return ( ( level - minLevel ) / ( numLevels - 1 ) ) * 100;
	};
	
	/**
	 * Get level number from mouse position.
	 * 
	 * @param {number} clientX Mouse X coordinate.
	 * @return {number} Calculated level number.
	 */
	const getLevelFromPosition = ( clientX ) => {
		if ( ! trackRef ) return minLevel;
		
		const rect = trackRef.getBoundingClientRect();
		const x = Math.max( 0, Math.min( clientX - rect.left, rect.width ) );
		const percent = x / rect.width;
		const level = Math.round( percent * ( numLevels - 1 ) ) + minLevel;
		
		return Math.max( minLevel, Math.min( maxLevel, level ) );
	};
	
	/**
	 * Handle mouse down on a handle.
	 * 
	 * @param {boolean} isStart True for start handle, false for end handle.
	 * @return {Function} Event handler function.
	 */
	const handleMouseDown = ( isStart ) => ( e ) => {
		e.preventDefault();
		if ( isStart ) {
			setIsDraggingStart( true );
		} else {
			setIsDraggingEnd( true );
		}
	};
	
	/**
	 * Handle mouse move while dragging.
	 * 
	 * @param {MouseEvent} e Mouse event.
	 */
	const handleMouseMove = ( e ) => {
		if ( ! isDraggingStart && ! isDraggingEnd ) return;
		
		const level = getLevelFromPosition( e.clientX );
		
		if ( isDraggingStart ) {
			const newStart = Math.min( level, endLevel );
			onChange( { startLevel: newStart, endLevel } );
		} else if ( isDraggingEnd ) {
			const newEnd = Math.max( level, startLevel );
			onChange( { startLevel, endLevel: newEnd } );
		}
	};
	
	/**
	 * Handle mouse up to stop dragging.
	 */
	const handleMouseUp = () => {
		setIsDraggingStart( false );
		setIsDraggingEnd( false );
	};
	
	/**
	 * Handle click on track to move nearest handle.
	 * 
	 * @param {MouseEvent} e Mouse event.
	 */
	const handleTrackClick = ( e ) => {
		if ( isDraggingStart || isDraggingEnd ) return;
		
		const level = getLevelFromPosition( e.clientX );
		const distToStart = Math.abs( level - startLevel );
		const distToEnd = Math.abs( level - endLevel );
		
		if ( distToStart < distToEnd ) {
			const newStart = Math.min( level, endLevel );
			onChange( { startLevel: newStart, endLevel } );
		} else {
			const newEnd = Math.max( level, startLevel );
			onChange( { startLevel, endLevel: newEnd } );
		}
	};
	
	// Set up mouse event listeners when dragging
	useEffect( () => {
		if ( isDraggingStart || isDraggingEnd ) {
			document.addEventListener( 'mousemove', handleMouseMove );
			document.addEventListener( 'mouseup', handleMouseUp );
			
			return () => {
				document.removeEventListener( 'mousemove', handleMouseMove );
				document.removeEventListener( 'mouseup', handleMouseUp );
			};
		}
	}, [ isDraggingStart, isDraggingEnd, startLevel, endLevel ] );
	// console.log( '[DualRangeControl] Rendering with startLevel:', startLevel, 'endLevel:', endLevel );
	const startPercent = getPositionPercent( startLevel );
	const endPercent = getPositionPercent( endLevel );
	
	return (
		<div className="dual-range-control">
			{/* { label && <div style={ { marginBottom: '12px', fontWeight: 500 } }>{ label }</div> } */}
			
			<div className="dual-range-control__labels">
				{ visibleLabels.map( ( levelLabel, index ) => {
					// Calculate position based on actual level index
					const levelPosition = ( index / Math.max( 1, numLevels - 1 ) ) * 100;
					
					return (
						<label 
							key={ index } 
							className="dual-range-control__label"
							style={ { left: `${ levelPosition }%` } }
						>
							{ levelLabel }
						</label>
					);
				} ) }
			</div>
			
			<div className="dual-range-control__track-container">
				<div
					ref={ setTrackRef }
					className="dual-range-control__track"
					onClick={ handleTrackClick }
				>
					<div
						className="dual-range-control__range"
						style={ {
							left: `${ startPercent }%`,
							width: `${ endPercent - startPercent }%`,
						} }
					/>
					<div
						className={ `dual-range-control__handle dual-range-control__handle--start ${
							isDraggingStart ? 'is-dragging' : ''
						}` }
						style={ { left: `${ startPercent }%` } }
						onMouseDown={ handleMouseDown( true ) }
						role="slider"
						aria-valuemin={ minLevel }
						aria-valuemax={ maxLevel }
						aria-valuenow={ startLevel }
						aria-label={ __( 'Start level', 'gatherpress-location-hierarchy' ) }
						tabIndex={ 0 }
					/>
					<div
						className={ `dual-range-control__handle dual-range-control__handle--end ${
							isDraggingEnd ? 'is-dragging' : ''
						}` }
						style={ { left: `${ endPercent }%` } }
						onMouseDown={ handleMouseDown( false ) }
						role="slider"
						aria-valuemin={ minLevel }
						aria-valuemax={ maxLevel }
						aria-valuenow={ endLevel }
						aria-label={ __( 'End level', 'gatherpress-location-hierarchy' ) }
						tabIndex={ 0 }
					/>
				</div>
			</div>
			
			<div className="dual-range-control__output">
				<span className="dual-range-control__output-label">
					{ __( 'Selected:', 'gatherpress-location-hierarchy' ) }
				</span>
				<strong>
					{ startLevel === endLevel
						? visibleLabels[ startLevel - minLevel ]
						: `${ visibleLabels[ startLevel - minLevel ] } - ${ visibleLabels[ endLevel - minLevel ] }` }
				</strong>
			</div>
		</div>
	);
}

/**
 * Component: BlockInspectorControls
 * File: Originally src/components/BlockInspectorControls.js
 * 
 * Inspector controls panel (settings sidebar) for the block.
 * 
 * WordPress blocks use the Inspector Controls to provide settings that affect
 * block behavior. This component groups all block settings in a collapsible panel in
 * the sidebar, following WordPress block editor conventions.
 * 
 * **How:**
 * - Wraps controls in InspectorControls component (provided by @wordpress/block-editor)
 * - Groups settings in PanelBody for collapsible section
 * - Renders DualRangeControl for level selection
 * - Renders TextControl for separator customization
 * - Renders ToggleControls for boolean options (links, venue)
 * - Each control updates block attributes via setAttributes callback
 * 
 * @since 0.1.0
 * @param {Object} props Component props.
 * @param {Object} props.attributes Block attributes object containing all settings.
 * @param {number} props.attributes.startLevel Starting hierarchy level.
 * @param {number} props.attributes.endLevel Ending hierarchy level.
 * @param {boolean} props.attributes.enableLinks Whether terms should be linked.
 * @param {boolean} props.attributes.showVenue Whether to show venue.
 * @param {string} props.attributes.separator Separator string between terms.
 * @param {Function} props.setAttributes Function to update block attributes.
 * @param {number} props.minLevel Minimum allowed level from configuration.
 * @param {number} props.maxLevel Maximum allowed level from configuration.
 * @return {JSX.Element} Rendered inspector controls component.
 * 
 * @example
 * <BlockInspectorControls
 *   attributes={attributes}
 *   setAttributes={setAttributes}
 *   minLevel={1}
 *   maxLevel={6}
 * />
 */
function BlockInspectorControls( { attributes, setAttributes, minLevel, maxLevel } ) {
	const { startLevel, endLevel, enableLinks, showVenue, separator } = attributes;
	
	/**
	 * Handle level change from dual-range control.
	 * 
	 * @param {Object} levels New level values.
	 * @param {number} levels.startLevel New start level.
	 * @param {number} levels.endLevel New end level.
	 */
	const handleLevelChange = ( { startLevel: newStart, endLevel: newEnd } ) => {
		setAttributes( {
			startLevel: newStart,
			endLevel: newEnd,
		} );
	};
	
	return (
		<InspectorControls>
			<PanelBody title={ __( 'Hierarchy Settings', 'gatherpress-location-hierarchy' ) }>
				<DualRangeControl
					label={ __( 'Hierarchy Levels', 'gatherpress-location-hierarchy' ) }
					minLevel={ minLevel }
					maxLevel={ maxLevel }
					startLevel={ startLevel }
					endLevel={ endLevel }
					onChange={ handleLevelChange }
				/>
				
				<TextControl
					label={ __( 'Separator', 'gatherpress-location-hierarchy' ) }
					value={ separator }
					onChange={ ( value ) => setAttributes( { separator: value } ) }
					help={ __( 'Text to display between hierarchy levels', 'gatherpress-location-hierarchy' ) }
				/>
				
				<ToggleControl
					label={ __( 'Enable term links', 'gatherpress-location-hierarchy' ) }
					checked={ enableLinks }
					onChange={ ( value ) => setAttributes( { enableLinks: value } ) }
					help={ __( 'Link each term to its archive page', 'gatherpress-location-hierarchy' ) }
				/>
				
				<ToggleControl
					label={ __( 'Show venue', 'gatherpress-location-hierarchy' ) }
					checked={ showVenue }
					onChange={ ( value ) => setAttributes( { showVenue: value } ) }
					help={ __( 'Display the venue name at the end of the hierarchy', 'gatherpress-location-hierarchy' ) }
				/>
			</PanelBody>
		</InspectorControls>
	);
}

/**
 * ============================================================================
 * MAIN EDIT COMPONENT
 * ============================================================================
 */

/**
 * Main Edit Component
 * 
 * The primary edit function that renders the block in the WordPress editor.
 * This is called by WordPress when the block is inserted or edited.
 * 
 * Every Gutenberg block requires an edit function that:
 * - Renders the block's editor UI
 * - Provides controls for modifying block attributes
 * - Shows a preview of how the block will appear on the frontend
 * - Integrates with WordPress's block editor APIs
 * 
 * **How:**
 * - Receives block attributes, setAttributes function, and context from WordPress
 * - Uses custom hooks to fetch and process data:
 *   - useAllowedLevels: Gets level constraints from PHP configuration
 *   - usePostContext: Validates we're in a GatherPress event context
 *   - useLocationData: Fetches location terms and venue data using useEntityProp
 *   - useLocationHierarchy: Builds formatted hierarchy display string
 * - Renders inspector controls (settings sidebar)
 * - Validates context before rendering (must be GatherPress event)
 * - Shows loading spinner while data is being fetched
 * - Renders hierarchy display with proper formatting (HTML links if enabled)
 * - Uses block wrapper props for WordPress core functionality (alignment, colors, etc.)
 * 
 * @since 0.1.0
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/#edit
 * 
 * @param {Object} props Block props provided by WordPress.
 * @param {Object} props.attributes Block attributes object defined in block.json.
 * @param {number} props.attributes.startLevel Starting hierarchy level (1-6).
 * @param {number} props.attributes.endLevel Ending hierarchy level (1-6).
 * @param {boolean} props.attributes.enableLinks Whether to link terms to archives.
 * @param {boolean} props.attributes.showVenue Whether to display venue name.
 * @param {string} props.attributes.separator String to place between terms.
 * @param {Function} props.setAttributes Function to update block attributes.
 * @param {Object} props.context Block context from WordPress containing post information.
 * @param {number} [props.context.postId] Current post ID.
 * @param {string} [props.context.postType] Current post type.
 * @param {number} [props.context.queryId] Query ID if in query loop.
 * @return {JSX.Element} Rendered block element for the editor.
 * 
 * @example
 * // WordPress calls this automatically when block is used
 * export default function Edit( { attributes, setAttributes, context } ) {
 *   // Component logic
 *   return <div {...useBlockProps()}>Block content</div>;
 * }
 */
export default function Edit( { attributes, setAttributes, context } ) {
	// Monitor post save status to trigger refresh
	const isSavingPost = useSelect(
		( select ) => select( 'core/editor' ).isSavingPost(),
		[]
	);
	
	const [ lastSaveState, setLastSaveState ] = useState( false );
	const [ refreshTrigger, setRefreshTrigger ] = useState( 0 );
	
	// Detect when save completes and trigger refresh
	useEffect( () => {
// console.log( '[Edit] isSavingPost changed:', isSavingPost, 'lastSaveState:', lastSaveState );
		if ( lastSaveState && ! isSavingPost ) {
// console.log( '[Edit] Post save completed, triggering data refresh' );
			// Save just completed, trigger refresh
			setRefreshTrigger( ( prev ) => prev + 1 );
		}
		setLastSaveState( isSavingPost );
	}, [ isSavingPost ] );


	const { startLevel, endLevel, enableLinks, showVenue, separator } = attributes;
	
	// Get allowed levels from localized script data
	const { minLevel, maxLevel } = useAllowedLevels();
	
	// Get post context (ID, type, query loop status)
	const { postId, postType, isInQueryLoop, isValidContext } = usePostContext( context );
	
	// Get location terms and venue data using useEntityProp for direct reactivity
	const { locationTerms, venueName, venueLink, isLoading: isDataLoading } = useLocationData(
		postId,
		showVenue,
		refreshTrigger
	);
	
	// Build location hierarchy display
	const { locationHierarchy, isLoading: isHierarchyLoading } = useLocationHierarchy(
		postId,
		locationTerms,
		venueName,
		venueLink,
		startLevel,
		endLevel,
		minLevel,
		maxLevel,
		enableLinks,
		showVenue,
		separator,
		isInQueryLoop
	);
	
	const isLoading = isDataLoading || isHierarchyLoading;
	
	// Validate context
	if ( ! isValidContext ) {
		return (
			<div { ...useBlockProps() }>
				{ __( 'This block must be used within a GatherPress event', 'gatherpress-location-hierarchy' ) }
			</div>
		);
	}
	
	return (
		<>
			<BlockInspectorControls
				attributes={ attributes }
				setAttributes={ setAttributes }
				minLevel={ minLevel }
				maxLevel={ maxLevel }
			/>
			<div { ...useBlockProps() }>
				{ isLoading ? (
					<Spinner />
				) : enableLinks ? (
					<RawHTML>{ locationHierarchy }</RawHTML>
				) : (
					locationHierarchy
				) }
			</div>
		</>
	);
}