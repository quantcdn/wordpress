import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import {
	Card,
	CardHeader,
	CardBody,
	Button,
	Notice,
	Spinner,
	ToggleControl,
	CheckboxControl,
	SelectControl,
	Flex,
	FlexItem,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { SearchPageEditor } from '../components/SearchPageEditor/SearchPageEditor';
import { Progress } from '../components/Progress/Progress';
import { REFRESH_DELAYS, indexMayStillChange } from './indexRefresh';

/**
 * Project search status: enabled flag and index statistics.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.status    Status from the API.
 * @param {Function} props.onRefresh Refresh handler.
 */
function StatusCard( { status, onRefresh } ) {
	if ( ! status ) {
		return (
			<Card style={ { marginBottom: '16px' } }>
				<CardBody>
					<Spinner />
				</CardBody>
			</Card>
		);
	}

	return (
		<Card style={ { marginBottom: '16px' } }>
			<CardHeader>
				<h2 style={ { margin: 0 } }>
					{ __( 'Quant Search', 'quant' ) }
				</h2>
				<Button variant="tertiary" size="small" onClick={ onRefresh }>
					{ __( 'Refresh', 'quant' ) }
				</Button>
			</CardHeader>
			<CardBody>
				{ ! status.configured && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Configure the project credentials first.',
							'quant'
						) }
					</Notice>
				) }
				{ status.configured && ! status.connected && (
					<Notice status="error" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: error message */
							__( 'Cannot reach the Quant API: %s', 'quant' ),
							status.error || ''
						) }
					</Notice>
				) }
				{ status.connected && ! status.enabled && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Search is not enabled for this project. Enable it in the Quant dashboard under the project settings.',
							'quant'
						) }
					</Notice>
				) }
				{ status.enabled && (
					<table className="widefat quant-kv-table">
						<tbody>
							<tr>
								<th>{ __( 'Index', 'quant' ) }</th>
								<td>
									{ status.index?.algolia_index ||
										status.stats?.name ||
										'-' }
								</td>
							</tr>
							<tr>
								<th>{ __( 'Search engine', 'quant' ) }</th>
								<td>
									{ status.backend === 'typesense'
										? 'Typesense'
										: 'Algolia' }
								</td>
							</tr>
							<tr>
								<th>{ __( 'Records', 'quant' ) }</th>
								<td>{ status.stats?.entries ?? '-' }</td>
							</tr>
							<tr>
								<th>{ __( 'Pending tasks', 'quant' ) }</th>
								<td>
									{ status.stats?.numberOfPendingTasks ??
										'-' }
								</td>
							</tr>
							<tr>
								<th>{ __( 'Last updated', 'quant' ) }</th>
								<td>{ status.stats?.updatedAt || '-' }</td>
							</tr>
						</tbody>
					</table>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * Table of search pages.
 *
 * @param {Object}   props          Component props.
 * @param {Array}    props.pages    Search pages.
 * @param {Function} props.onEdit   Edit handler.
 * @param {Function} props.onDelete Delete handler.
 */
function PagesTable( { pages, onEdit, onDelete } ) {
	if ( pages.length === 0 ) {
		return (
			<p style={ { color: '#666' } }>
				{ __(
					'No search pages yet. A search page is a WordPress page that shows Quant Search results with facets. You can also place the "Quant Search" block or the [quant_search] shortcode on any page.',
					'quant'
				) }
			</p>
		);
	}

	return (
		<table className="widefat striped">
			<thead>
				<tr>
					<th>{ __( 'Label', 'quant' ) }</th>
					<th>{ __( 'Route', 'quant' ) }</th>
					<th>{ __( 'Status', 'quant' ) }</th>
					<th>{ __( 'Facets', 'quant' ) }</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				{ pages.map( ( page ) => (
					<tr key={ page.id }>
						<td>
							<strong>{ page.label }</strong>
							<br />
							<code>{ `[quant_search page="${ page.id }"]` }</code>
						</td>
						<td>
							{ page.url ? (
								<a
									href={ page.url }
									target="_blank"
									rel="noreferrer"
								>
									{ page.route }
								</a>
							) : (
								page.route
							) }
						</td>
						<td>
							{ page.status
								? __( 'Enabled', 'quant' )
								: __( 'Disabled', 'quant' ) }
						</td>
						<td>{ page.facets.length }</td>
						<td style={ { textAlign: 'right' } }>
							<Button
								variant="link"
								onClick={ () => onEdit( page ) }
							>
								{ __( 'Edit', 'quant' ) }
							</Button>{ ' ' }
							<Button
								variant="link"
								isDestructive
								onClick={ () => onDelete( page ) }
							>
								{ __( 'Delete', 'quant' ) }
							</Button>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

export function Search() {
	const [ status, setStatus ] = useState( null );
	const [ config, setConfig ] = useState( null );
	const [ pages, setPages ] = useState( [] );
	const [ editing, setEditing ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ progressRun, setProgressRun ] = useState( 0 );
	// The server checks the saved value, so actions follow it, not the draft.
	const [ savedEnabled, setSavedEnabled ] = useState( false );

	const notify = ( st, message ) => setNotice( { status: st, message } );
	const handleProgressDismiss = useCallback( () => setProgressRun( 0 ), [] );

	const loadStatus = useCallback(
		() =>
			apiFetch( { path: '/quant/v1/search/status' } )
				.then( ( data ) => {
					setStatus( data );
					return data;
				} )
				.catch( () => {
					const failed = { configured: false };
					setStatus( failed );
					return failed;
				} ),
		[]
	);

	// Records before the last Rebuild, and the follow-up status reads.
	const recordsBeforeRef = useRef( null );
	const refreshTimerRef = useRef( null );
	useEffect( () => () => clearTimeout( refreshTimerRef.current ), [] );

	const refreshAfterRun = useCallback( () => {
		clearTimeout( refreshTimerRef.current );
		const read = ( step, previous ) => {
			loadStatus().then( ( current ) => {
				const next = step + 1;
				if (
					next < REFRESH_DELAYS.length &&
					indexMayStillChange(
						recordsBeforeRef.current,
						previous,
						current
					)
				) {
					refreshTimerRef.current = setTimeout(
						() => read( next, current?.stats?.entries ?? null ),
						REFRESH_DELAYS[ next ]
					);
				}
			} );
		};
		read( 0, recordsBeforeRef.current );
	}, [ loadStatus ] );

	useEffect( () => {
		loadStatus();
		apiFetch( { path: '/quant/v1/search/settings' } )
			.then( ( data ) => {
				setConfig( data );
				setSavedEnabled( !! data?.settings?.enabled );
			} )
			.catch( ( e ) => notify( 'error', e.message ) );
		apiFetch( { path: '/quant/v1/search/pages' } )
			.then( setPages )
			.catch( () => setPages( [] ) );
	}, [ loadStatus ] );

	const settings = config?.settings;
	const updateSetting = ( key, value ) =>
		setConfig( ( prev ) => ( {
			...prev,
			settings: { ...prev.settings, [ key ]: value },
		} ) );

	const saveSettings = async () => {
		setSaving( true );
		try {
			const response = await apiFetch( {
				path: '/quant/v1/search/settings',
				method: 'POST',
				data: settings,
			} );
			setConfig( ( prev ) => ( {
				...prev,
				settings: response.settings,
			} ) );
			setSavedEnabled( !! response.settings?.enabled );
			if ( response.push_needed ) {
				notify(
					'warning',
					__(
						'Search settings saved. The search form is part of every page, so pages on the edge keep the old form until you push them again. On the Content page, select Push everything.',
						'quant'
					)
				);
				return;
			}
			notify( 'success', __( 'Search settings saved.', 'quant' ) );
		} catch ( e ) {
			notify( 'error', e.message || __( 'Failed to save', 'quant' ) );
		} finally {
			setSaving( false );
		}
	};

	const reindex = async () => {
		setBusy( true );
		recordsBeforeRef.current = status?.stats?.entries ?? null;
		try {
			const response = await apiFetch( {
				path: '/quant/v1/search/reindex',
				method: 'POST',
			} );
			notify(
				'success',
				sprintf(
					/* translators: %d: number of records */
					__( 'Queued %d records. Processing…', 'quant' ),
					response.queued
				)
			);
			setProgressRun( ( n ) => n + 1 );
		} catch ( e ) {
			notify( 'error', e.message || __( 'Reindex failed', 'quant' ) );
		} finally {
			setBusy( false );
		}
	};

	const clearIndex = async () => {
		const question = __(
			'Remove every record from the search index?',
			'quant'
		);
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( question ) ) {
			return;
		}
		setBusy( true );
		try {
			await apiFetch( {
				path: '/quant/v1/search/clear',
				method: 'POST',
			} );
			notify( 'success', __( 'Search index cleared.', 'quant' ) );
			loadStatus();
		} catch ( e ) {
			notify( 'error', e.message || __( 'Clear failed', 'quant' ) );
		} finally {
			setBusy( false );
		}
	};

	const savePage = async ( draft ) => {
		setSaving( true );
		try {
			const saved = await apiFetch( {
				path: '/quant/v1/search/pages',
				method: 'POST',
				data: draft,
			} );
			setPages( ( prev ) => [
				...prev.filter( ( p ) => p.id !== saved.id ),
				saved,
			] );
			setEditing( null );
			notify(
				'success',
				__(
					'Search page saved. Its WordPress page was queued for publishing.',
					'quant'
				)
			);
		} catch ( e ) {
			notify(
				'error',
				e.message || __( 'Failed to save the search page', 'quant' )
			);
		} finally {
			setSaving( false );
		}
	};

	const deletePage = async ( page ) => {
		const message = sprintf(
			/* translators: %s: search page label */
			__(
				'Delete the search page "%s"? Its WordPress page is moved to the trash.',
				'quant'
			),
			page.label
		);
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( message ) ) {
			return;
		}
		try {
			await apiFetch( {
				path: `/quant/v1/search/pages/${ page.id }`,
				method: 'DELETE',
			} );
			setPages( ( prev ) => prev.filter( ( p ) => p.id !== page.id ) );
			notify( 'success', __( 'Search page deleted.', 'quant' ) );
		} catch ( e ) {
			notify( 'error', e.message || __( 'Delete failed', 'quant' ) );
		}
	};

	const togglePostType = ( name, checked ) =>
		updateSetting(
			'post_types',
			checked
				? [ ...settings.post_types, name ]
				: settings.post_types.filter( ( t ) => t !== name )
		);

	return (
		<div className="quant-search-admin">
			<h1>{ __( 'Search', 'quant' ) }</h1>

			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
					isDismissible
				>
					{ notice.message }
				</Notice>
			) }
			{ progressRun > 0 && (
				<Progress
					key={ progressRun }
					autoProcess
					onFinished={ refreshAfterRun }
					onComplete={ handleProgressDismiss }
				/>
			) }

			<StatusCard status={ status } onRefresh={ loadStatus } />

			{ settings && (
				<Card style={ { marginBottom: '16px' } }>
					<CardHeader>
						<h2 style={ { margin: 0 } }>
							{ __( 'Search Records', 'quant' ) }
						</h2>
					</CardHeader>
					<CardBody>
						<ToggleControl
							label={ __(
								'Keep search records updated',
								'quant'
							) }
							checked={ !! settings.enabled }
							onChange={ ( v ) => updateSetting( 'enabled', v ) }
							help={ __(
								'Send a search record with every page push so the index follows your content.',
								'quant'
							) }
						/>
						<p>
							<strong>
								{ __( 'Indexed content types', 'quant' ) }
							</strong>
						</p>
						{ config.post_types.map( ( type ) => (
							<CheckboxControl
								key={ type.name }
								label={ type.label }
								checked={ settings.post_types.includes(
									type.name
								) }
								onChange={ ( checked ) =>
									togglePostType( type.name, checked )
								}
							/>
						) ) }
						<SelectControl
							label={ __( 'Summary source', 'quant' ) }
							value={ settings.summary_source }
							options={ [
								{
									label: __(
										'Excerpt, or the first words of the content',
										'quant'
									),
									value: 'excerpt',
								},
								{
									label: __(
										'First words of the content',
										'quant'
									),
									value: 'content',
								},
							] }
							onChange={ ( v ) =>
								updateSetting( 'summary_source', v )
							}
						/>
						<SelectControl
							label={ __( 'Image size', 'quant' ) }
							value={ settings.image_size }
							options={ ( config.image_sizes || [] ).map(
								( s ) => ( { label: s, value: s } )
							) }
							onChange={ ( v ) =>
								updateSetting( 'image_size', v )
							}
							help={ __(
								'Featured image size stored in the record.',
								'quant'
							) }
						/>
						<ToggleControl
							label={ __(
								'Send the WordPress search form to a Quant Search page',
								'quant'
							) }
							checked={ !! settings.replace_wp_search }
							onChange={ ( v ) =>
								updateSetting( 'replace_wp_search', v )
							}
							help={ __(
								'The theme search form and ?s= searches use the default search page below, so search works on the static site.',
								'quant'
							) }
						/>
						{ settings.replace_wp_search && (
							<SelectControl
								label={ __( 'Default search page', 'quant' ) }
								value={ settings.default_page }
								options={ [
									{
										label: __(
											'First enabled page',
											'quant'
										),
										value: '',
									},
								].concat(
									pages.map( ( p ) => ( {
										label: p.label,
										value: p.id,
									} ) )
								) }
								onChange={ ( v ) =>
									updateSetting( 'default_page', v )
								}
							/>
						) }
						<Flex
							justify="flex-start"
							gap={ 2 }
							style={ { marginTop: '16px' } }
						>
							<FlexItem>
								<Button
									variant="primary"
									onClick={ saveSettings }
									disabled={ saving }
									isBusy={ saving }
								>
									{ __( 'Save', 'quant' ) }
								</Button>
							</FlexItem>
							<FlexItem>
								<Button
									variant="secondary"
									onClick={ reindex }
									disabled={
										busy ||
										! savedEnabled ||
										! status?.enabled
									}
								>
									{ __( 'Rebuild index', 'quant' ) }
								</Button>
							</FlexItem>
							<FlexItem>
								<Button
									variant="secondary"
									isDestructive
									onClick={ clearIndex }
									disabled={ busy || ! status?.enabled }
								>
									{ __( 'Clear index', 'quant' ) }
								</Button>
							</FlexItem>
						</Flex>
					</CardBody>
				</Card>
			) }

			<Card>
				<CardHeader>
					<h2 style={ { margin: 0 } }>
						{ __( 'Search Pages', 'quant' ) }
					</h2>
					{ ! editing && (
						<Button
							variant="primary"
							size="small"
							onClick={ () => setEditing( {} ) }
						>
							{ __( 'Add search page', 'quant' ) }
						</Button>
					) }
				</CardHeader>
				<CardBody>
					{ editing ? (
						<SearchPageEditor
							page={ editing.id ? editing : null }
							postTypes={ config?.post_types || [] }
							taxonomies={ config?.taxonomies || [] }
							onSave={ savePage }
							onCancel={ () => setEditing( null ) }
							saving={ saving }
						/>
					) : (
						<PagesTable
							pages={ pages }
							onEdit={ setEditing }
							onDelete={ deletePage }
						/>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
