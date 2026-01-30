import { registerBlockType } from '@wordpress/blocks';
import { registerPlugin } from '@wordpress/plugins';
import { useSelect, useDispatch } from '@wordpress/data';
import { useRef, useEffect } from '@wordpress/element';
import { useEntityProp } from '@wordpress/core-data';

/**
 * Internal dependencies
*/
import './style.scss';
import Edit from './edit';
import metadata from './block.json';

const VenueTermChangeListener = () => {
	const { editPost } = useDispatch( 'core/editor' );
	const postId = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostId(),
		[]
	);

	const postType = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostType(),
		[]
	);

	const [ venueTaxonomyIds ] = useEntityProp(
		'postType',
		'gatherpress_event',
		'_gatherpress_venue',
		postId
	);

	const [ , setLocationIds ] = useEntityProp(
		'postType',
		'gatherpress_event',
		'gatherpress_location',
		postId
	);

	const previousVenueIds = useRef( null );

	useEffect( () => {
		if ( postType !== 'gatherpress_event' ) {
			return;
		}

		const currentVenue =
			Array.isArray( venueTaxonomyIds ) && venueTaxonomyIds.length
				? venueTaxonomyIds[ 0 ]
				: null;

		// First meaningful run
		if ( previousVenueIds.current === undefined ) {
			previousVenueIds.current = currentVenue;
			return;
		}

		const prev = previousVenueIds.current;
		const venueChanged = prev !== currentVenue;

		if ( venueChanged && prev !== null ) {
			// 1️. Update entity (data truth)
			setLocationIds( [] );

			// 2️. Force editor taxonomy UI to sync
			editPost( {
				taxonomies: {
					gatherpress_location: [],
				},
			} );
		}

		previousVenueIds.current = currentVenue;
	}, [ venueTaxonomyIds, postType, postId ] );

	return null;
};
/**
 * Every block starts by registering a new block type definition.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-registration/
 */
registerBlockType( metadata.name, {
	/**
	 * @see ./edit.js
	 */
	edit: Edit,
} );

/**
 * Register the venue term change listener as a plugin.
 * 
 * Registers the venue term change listener as an editor plugin.
 * 
 * Using registerPlugin ensures the listener:
 * - Works globally in the editor (not tied to block instances)
 * - Properly integrates with WordPress's plugin system
 * - Automatically cleans up when editor unmounts
 * - Follows WordPress best practices for editor extensions
 * 
 * Calls registerPlugin with a unique name and our listener component.
 * WordPress handles the mounting/unmounting lifecycle automatically.
 */
registerPlugin( 'gatherpress-location-hierarchy-listener', {
	render: VenueTermChangeListener,
} );


