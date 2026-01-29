/**
 * Registers a new block provided a unique name and an object defining its behavior.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-registration/
 */
import { registerBlockType } from '@wordpress/blocks';
import { registerPlugin } from '@wordpress/plugins';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { useState, useEffect } from '@wordpress/element';

import { useRef } from '@wordpress/element';
import { useEntityProp } from '@wordpress/core-data';

/**
 * Lets webpack process CSS, SASS or SCSS files referenced in JavaScript files.
 * All files containing `style` keyword are bundled together. The code used
 * gets applied both to the front of your site and to the editor.
 *
 * @see https://www.npmjs.com/package/@wordpress/scripts#using-css
 */
import './style.scss';

/**
 * Internal dependencies
 */
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
		'gatherpress-location',
		postId
	);

	const previousVenueIds = useRef( null );

	useEffect( () => {
		// console.log(
		// 	'[VenueTermChangeListener] useEffect running...',
		// 	{ postId, postType, venueTaxonomyIds }
		// );

		if ( postType !== 'gatherpress_event' ) {
			return;
		}

		const currentVenue =
			Array.isArray( venueTaxonomyIds ) && venueTaxonomyIds.length
				? venueTaxonomyIds[ 0 ]
				: null;

		// First meaningful run
		if ( previousVenueIds.current === undefined ) {
			// console.log(
			// 	'[VenueTermChangeListener] Initializing previous venue.',
			// 	{ currentVenue }
			// );
			previousVenueIds.current = currentVenue;
			return;
		}

		const prev = previousVenueIds.current;

		// console.log(
		// 	'[VenueTermChangeListener] Comparing venues',
		// 	{ prev, currentVenue }
		// );

		const venueChanged = prev !== currentVenue;

		if ( venueChanged && prev !== null ) {
			// console.log(
			// 	'[VenueTermChangeListener] Venue changed → clearing location terms'
			// );
			// 1️⃣ Update entity (data truth)
			setLocationIds( [] );

			// 2️⃣ Force editor taxonomy UI to sync
			editPost( {
				taxonomies: {
					"gatherpress-location": [],
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
 * **What:** Registers the venue term change listener as an editor plugin.
 * 
 * **Why:** Using registerPlugin ensures the listener:
 * - Works globally in the editor (not tied to block instances)
 * - Properly integrates with WordPress's plugin system
 * - Automatically cleans up when editor unmounts
 * - Follows WordPress best practices for editor extensions
 * 
 * **How:** Calls registerPlugin with a unique name and our listener component.
 * WordPress handles the mounting/unmounting lifecycle automatically.
 */
registerPlugin( 'gatherpress-venue-hierarchy-listener', {
	render: VenueTermChangeListener,
} );


