/**
 * Sister Sync panel for the block editor: a Check button in the document
 * sidebar and an automatic check in the pre-publish panel.
 *
 * Both check the content as it is in the editor, including unsaved edits.
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import * as editorPackage from '@wordpress/editor';
import * as editPostPackage from '@wordpress/edit-post';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

// WordPress 6.6 moved these slots to @wordpress/editor; 6.4 and 6.5 only
// have them in @wordpress/edit-post.
const PluginDocumentSettingPanel =
	editorPackage.PluginDocumentSettingPanel ||
	editPostPackage.PluginDocumentSettingPanel;
const PluginPrePublishPanel =
	editorPackage.PluginPrePublishPanel ||
	editPostPackage.PluginPrePublishPanel;

/**
 * Runs a check against the content currently in the editor.
 */
function useSisterCheck() {
	const { postId, content, excerpt } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			postId: editor.getCurrentPostId(),
			content: editor.getEditedPostContent(),
			excerpt: editor.getEditedPostAttribute( 'excerpt' ),
		};
	}, [] );

	const [ state, setState ] = useState( {
		status: 'idle',
		data: null,
		error: null,
	} );

	const run = useCallback( () => {
		setState( { status: 'loading', data: null, error: null } );
		apiFetch( {
			path: '/sister-sync/v1/check',
			method: 'POST',
			data: { post_id: postId, content, excerpt },
		} )
			.then( ( data ) =>
				setState( { status: 'done', data, error: null } )
			)
			.catch( ( error ) =>
				setState( {
					status: 'error',
					data: null,
					error:
						error?.message ||
						__( 'The check failed.', 'sister-sync' ),
				} )
			);
	}, [ postId, content, excerpt ] );

	return { ...state, run };
}

/**
 * Finds the clientId of the block at a path of indexes through the tree.
 *
 * @param {Array}    blocks Top-level blocks from the block editor store.
 * @param {number[]} path   Zero-based indexes from the server.
 * @return {string|null} The block's clientId, or null if the tree changed.
 */
function clientIdAtPath( blocks, path ) {
	let level = blocks;
	let block = null;
	for ( const index of path ) {
		block = level?.[ index ];
		if ( ! block ) {
			return null;
		}
		level = block.innerBlocks;
	}
	return block ? block.clientId : null;
}

function Summary( { counts } ) {
	const total = counts.error + counts.warning;
	if ( total === 0 ) {
		return (
			<Notice status="success" isDismissible={ false }>
				{ __( 'No sister links found.', 'sister-sync' ) }
			</Notice>
		);
	}
	return (
		<Notice
			status={ counts.error > 0 ? 'error' : 'warning' }
			isDismissible={ false }
		>
			{ sprintf(
				/* translators: 1: number of errors, 2: number of warnings. */
				__( '%1$s, %2$s', 'sister-sync' ),
				sprintf(
					/* translators: %d: number of errors. */
					_n( '%d error', '%d errors', counts.error, 'sister-sync' ),
					counts.error
				),
				sprintf(
					/* translators: %d: number of warnings. */
					_n(
						'%d warning',
						'%d warnings',
						counts.warning,
						'sister-sync'
					),
					counts.warning
				)
			) }
		</Notice>
	);
}

function FindingList( { findings } ) {
	const blocks = useSelect(
		( select ) => select( 'core/block-editor' ).getBlocks(),
		[]
	);
	const { selectBlock } = useDispatch( 'core/block-editor' );

	return (
		<ul className="sister-sync-findings">
			{ findings.map( ( finding, i ) => {
				const clientId = finding.blockPath
					? clientIdAtPath( blocks, finding.blockPath )
					: null;
				const body = (
					<>
						<span
							className="sister-sync-badge"
							style={ { background: finding.sister.colour } }
						>
							{ finding.sister.label }
						</span>
						<span className="sister-sync-url">{ finding.url }</span>
						<span className="sister-sync-location">
							{ finding.location }
							{ finding.count > 1 ? ` × ${ finding.count }` : '' }
						</span>
					</>
				);
				return (
					<li
						key={ i }
						className={
							'sister-sync-finding' +
							( finding.severity === 'error' ? ' is-error' : '' )
						}
					>
						{ clientId ? (
							<button
								type="button"
								className="sister-sync-finding__select"
								onClick={ () => selectBlock( clientId ) }
								title={ __(
									'Select this block',
									'sister-sync'
								) }
							>
								{ body }
							</button>
						) : (
							body
						) }
					</li>
				);
			} ) }
		</ul>
	);
}

function Results( { status, data, error } ) {
	if ( status === 'loading' ) {
		return <Spinner />;
	}
	if ( status === 'error' ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( status !== 'done' || ! data ) {
		return null;
	}
	return (
		<div className="sister-sync-summary">
			<Summary counts={ data.counts } />
			{ data.findings.length > 0 && (
				<FindingList findings={ data.findings } />
			) }
		</div>
	);
}

function SidebarPanel() {
	const check = useSisterCheck();
	return (
		<PluginDocumentSettingPanel
			name="sister-sync"
			title={ __( 'Sister Sync', 'sister-sync' ) }
		>
			<p>
				{ __(
					'Find links and images that point at another environment of this site.',
					'sister-sync'
				) }
			</p>
			<Button
				variant="secondary"
				onClick={ check.run }
				isBusy={ check.status === 'loading' }
				disabled={ check.status === 'loading' }
			>
				{ __( 'Check', 'sister-sync' ) }
			</Button>
			<Results { ...check } />
		</PluginDocumentSettingPanel>
	);
}

function PrePublishPanel() {
	const check = useSisterCheck();
	const { run } = check;

	// Check once when the pre-publish panel opens.
	useEffect( () => {
		run();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return (
		<PluginPrePublishPanel
			title={ __( 'Sister Sync', 'sister-sync' ) }
			initialOpen={ true }
		>
			<Results { ...check } />
		</PluginPrePublishPanel>
	);
}

registerPlugin( 'sister-sync', {
	render: () => (
		<>
			<SidebarPanel />
			<PrePublishPanel />
		</>
	),
} );
