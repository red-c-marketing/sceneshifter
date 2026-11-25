/**
 * Sceneshifter panel for the block editor: a Check button in the document
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
			path: '/sceneshifter/v1/check',
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
						__( 'The check failed.', 'sceneshifter' ),
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
				{ __(
					'No links to other environments found.',
					'sceneshifter'
				) }
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
				__( '%1$s, %2$s', 'sceneshifter' ),
				sprintf(
					/* translators: %d: number of errors. */
					_n( '%d error', '%d errors', counts.error, 'sceneshifter' ),
					counts.error
				),
				sprintf(
					/* translators: %d: number of warnings. */
					_n(
						'%d warning',
						'%d warnings',
						counts.warning,
						'sceneshifter'
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
		<ul className="sceneshifter-findings">
			{ findings.map( ( finding, i ) => {
				const clientId = finding.blockPath
					? clientIdAtPath( blocks, finding.blockPath )
					: null;
				const body = (
					<>
						<span
							className="sceneshifter-badge"
							style={ { background: finding.sister.colour } }
						>
							{ finding.sister.label }
						</span>
						<span className="sceneshifter-url">
							{ finding.url }
						</span>
						<span className="sceneshifter-location">
							{ finding.location }
							{ finding.count > 1 ? ` × ${ finding.count }` : '' }
						</span>
					</>
				);
				return (
					<li
						key={ i }
						className={
							'sceneshifter-finding' +
							( finding.severity === 'error' ? ' is-error' : '' )
						}
					>
						{ clientId ? (
							<button
								type="button"
								className="sceneshifter-finding__select"
								onClick={ () => selectBlock( clientId ) }
								title={ __(
									'Select this block',
									'sceneshifter'
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
		<div className="sceneshifter-summary">
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
			name="sceneshifter"
			title={ __( 'Sceneshifter', 'sceneshifter' ) }
		>
			<p>
				{ __(
					'Find links and images that point at another environment of this site.',
					'sceneshifter'
				) }
			</p>
			<Button
				variant="secondary"
				onClick={ check.run }
				isBusy={ check.status === 'loading' }
				disabled={ check.status === 'loading' }
			>
				{ __( 'Check', 'sceneshifter' ) }
			</Button>
			<Results { ...check } />
		</PluginDocumentSettingPanel>
	);
}

const blockPublish = Boolean( window.sceneshifterEditor?.blockPublish );

function PrePublishPanel() {
	const check = useSisterCheck();
	const { run } = check;
	const { lockPostSaving, unlockPostSaving } = useDispatch( 'core/editor' );
	const blocked =
		blockPublish && check.status === 'done' && check.data?.counts.error > 0;

	// Check once when the pre-publish panel opens.
	useEffect( () => {
		run();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// Hold the Publish button while there are errors and this site blocks
	// them. The server refuses the save anyway; this explains why first.
	useEffect( () => {
		if ( ! blocked ) {
			return undefined;
		}
		lockPostSaving( 'sceneshifter' );
		return () => unlockPostSaving( 'sceneshifter' );
	}, [ blocked, lockPostSaving, unlockPostSaving ] );

	return (
		<PluginPrePublishPanel
			title={ __( 'Sceneshifter', 'sceneshifter' ) }
			initialOpen={ true }
		>
			{ blocked && (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'Publishing is blocked on this site until the errors below are fixed.',
						'sceneshifter'
					) }
				</Notice>
			) }
			<Results { ...check } />
			{ check.status === 'done' && (
				<Button variant="link" onClick={ run }>
					{ __( 'Check again', 'sceneshifter' ) }
				</Button>
			) }
		</PluginPrePublishPanel>
	);
}

registerPlugin( 'sceneshifter', {
	render: () => (
		<>
			<SidebarPanel />
			<PrePublishPanel />
		</>
	),
} );
