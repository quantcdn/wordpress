import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import {
	Card,
	CardBody,
	TabPanel,
	Button,
	Notice,
	Spinner,
	DropdownMenu,
	MenuGroup,
	MenuItem,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { ContentBrowser } from '../components/ContentBrowser/ContentBrowser';
import { Progress } from '../components/Progress/Progress';

export function Content() {
	const [ status, setStatus ] = useState( {
		loading: true,
		mode: 'static',
		connected: false,
	} );
	const [ contentTypes, setContentTypes ] = useState( [] );
	const [ selectedItems, setSelectedItems ] = useState( [] );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ showProgress, setShowProgress ] = useState( false );
	const [ autoProcessing, setAutoProcessing ] = useState( false );

	useEffect( () => {
		Promise.all( [
			apiFetch( { path: '/quant/v1/status' } ),
			apiFetch( { path: '/quant/v1/content-types' } ),
		] )
			.then( ( [ statusData, typesData ] ) => {
				setStatus( { loading: false, ...statusData } );
				setContentTypes( typesData );
			} )
			.catch( () =>
				setStatus( {
					loading: false,
					mode: 'cdn_only',
					connected: false,
				} )
			);
	}, [] );

	const startProcessing = ( message ) => {
		setNotice( { status: 'success', message } );
		setAutoProcessing( true );
		setShowProgress( true );
	};

	const seed = async ( types, confirmMessage ) => {
		// eslint-disable-next-line no-alert
		if ( confirmMessage && ! window.confirm( confirmMessage ) ) {
			return;
		}
		setBusy( true );
		setNotice( null );
		try {
			const response = await apiFetch( {
				path: '/quant/v1/seed',
				method: 'POST',
				data: { types },
			} );
			startProcessing(
				sprintf(
					/* translators: %d: number of queued items */
					__( 'Queued %d items. Processing…', 'quant' ),
					response.queued
				)
			);
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error.message || __( 'Failed to queue content', 'quant' ),
			} );
		} finally {
			setBusy( false );
		}
	};

	const syncSelected = async ( action ) => {
		if ( selectedItems.length === 0 ) {
			return;
		}
		setBusy( true );
		setNotice( null );
		try {
			const response = await apiFetch( {
				path: '/quant/v1/sync',
				method: 'POST',
				data: { items: selectedItems, action },
			} );
			setSelectedItems( [] );
			const template =
				action === 'unpublish'
					? /* translators: %d: number of items */ __(
							'Queued %d items to unpublish. Processing…',
							'quant'
					  )
					: /* translators: %d: number of items */ __(
							'Queued %d items. Processing…',
							'quant'
					  );
			startProcessing( sprintf( template, response.queued ) );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error.message || __( 'Failed to queue items', 'quant' ),
			} );
		} finally {
			setBusy( false );
		}
	};

	const processQueue = async () => {
		try {
			const queue = await apiFetch( { path: '/quant/v1/queue/status' } );
			if ( queue.pending === 0 && queue.processing === 0 ) {
				setNotice( {
					status: 'info',
					message: __(
						'The queue is empty. Use "Push everything" or select items to push.',
						'quant'
					),
				} );
				return;
			}
			startProcessing(
				sprintf(
					/* translators: %d: number of pending items */
					__( 'Processing %d pending items…', 'quant' ),
					queue.pending
				)
			);
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error.message || __( 'Failed to read the queue', 'quant' ),
			} );
		}
	};

	const handleSelectionChange = useCallback(
		( items ) => setSelectedItems( items ),
		[]
	);

	const tabs = useMemo( () => {
		const result = [];
		const byType = ( type ) =>
			contentTypes.find( ( t ) => t.type === type );

		for ( const type of [ 'post', 'page' ] ) {
			const entry = byType( type );
			if ( entry ) {
				result.push( {
					name: type,
					title: `${ entry.label } (${ entry.count })`,
					contentType: entry,
				} );
			}
		}
		for ( const cpt of contentTypes.filter( ( t ) => t.type === 'cpt' ) ) {
			result.push( {
				name: `cpt_${ cpt.subtype }`,
				title: `${ cpt.label } (${ cpt.count })`,
				contentType: cpt,
			} );
		}
		const taxonomies = contentTypes.filter(
			( t ) => t.type === 'taxonomy'
		);
		if ( taxonomies.length > 0 ) {
			result.push( {
				name: 'taxonomies',
				title: `${ __( 'Taxonomies', 'quant' ) } (${ taxonomies.reduce(
					( s, t ) => s + t.count,
					0
				) })`,
				contentTypes: taxonomies,
			} );
		}
		const media = byType( 'media' );
		if ( media ) {
			result.push( {
				name: 'media',
				title: `${ media.label } (${ media.count })`,
				contentType: media,
			} );
		}
		const themeAssets = byType( 'theme_assets' );
		if ( themeAssets ) {
			result.push( {
				name: 'theme_assets',
				title: `${ themeAssets.label } (${ themeAssets.count })`,
				contentType: themeAssets,
				specialType: 'theme_assets',
			} );
		}
		const redirects = byType( 'redirects' );
		if ( redirects && redirects.count > 0 ) {
			result.push( {
				name: 'redirects',
				title: `${ redirects.label } (${ redirects.count })`,
				contentType: redirects,
				specialType: 'redirects',
			} );
		}
		return result;
	}, [ contentTypes ] );

	const specialTypes = contentTypes.filter(
		( t ) => t.category === 'special'
	);

	if ( status.loading ) {
		return (
			<div className="quant-content">
				<h1>{ __( 'Content', 'quant' ) }</h1>
				<Spinner />
			</div>
		);
	}

	if ( status.mode !== 'static' ) {
		return (
			<div className="quant-content">
				<h1>{ __( 'Content', 'quant' ) }</h1>
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Content publishing is available in Static Site mode. In CDN Only mode the plugin purges the Quant cache when content changes.',
						'quant'
					) }
				</Notice>
			</div>
		);
	}

	return (
		<div className="quant-content">
			<h1>{ __( 'Content', 'quant' ) }</h1>

			{ ! status.connected && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Not connected to the Quant API. Check the settings before you push content.',
						'quant'
					) }
				</Notice>
			) }

			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
					isDismissible
				>
					{ notice.message }
				</Notice>
			) }

			{ showProgress && (
				<Progress
					onComplete={ () => {
						setShowProgress( false );
						setAutoProcessing( false );
					} }
					autoProcess={ autoProcessing }
				/>
			) }

			<Card className="quant-content__header-card">
				<CardBody>
					<div className="quant-content__actions">
						<div className="quant-content__mode-indicator">
							<span className="quant-content__mode-badge quant-content__mode-badge--static">
								{ __( 'Static Mode', 'quant' ) }
							</span>
							<span className="quant-content__mode-description">
								{ __(
									'Pages, files and redirects are published to the Quant edge.',
									'quant'
								) }
							</span>
						</div>
						<div className="quant-content__batch-actions">
							<Button
								variant="primary"
								onClick={ () =>
									seed(
										[ 'all' ],
										__(
											'Queue the whole site for publishing: posts, pages, custom post types, terms, media, home, archives, author pages, theme assets, custom routes, sitemaps and redirects. Continue?',
											'quant'
										)
									)
								}
								disabled={ busy || autoProcessing }
								isBusy={ busy }
							>
								{ __( 'Push everything', 'quant' ) }
							</Button>
							<DropdownMenu
								icon="arrow-down-alt2"
								label={ __( 'Push one type', 'quant' ) }
								toggleProps={ {
									variant: 'secondary',
									children: __( 'Push…', 'quant' ),
								} }
							>
								{ () => (
									<MenuGroup>
										{ specialTypes.map( ( type ) => (
											<MenuItem
												key={ type.type }
												onClick={ () =>
													seed( [ type.type ] )
												}
											>{ `${ type.label } (${ type.count })` }</MenuItem>
										) ) }
										<MenuItem
											onClick={ () =>
												seed( [ 'taxonomy' ] )
											}
										>
											{ __(
												'All taxonomy terms',
												'quant'
											) }
										</MenuItem>
										<MenuItem
											onClick={ () =>
												seed( [ 'media' ] )
											}
										>
											{ __( 'All media', 'quant' ) }
										</MenuItem>
									</MenuGroup>
								) }
							</DropdownMenu>
							<Button
								variant="secondary"
								onClick={ () => syncSelected( 'sync' ) }
								disabled={
									busy ||
									selectedItems.length === 0 ||
									autoProcessing
								}
							>
								{ __( 'Push selected', 'quant' ) }
								{ selectedItems.length > 0 &&
									` (${ selectedItems.length })` }
							</Button>
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () => syncSelected( 'unpublish' ) }
								disabled={
									busy ||
									selectedItems.length === 0 ||
									autoProcessing
								}
							>
								{ __( 'Unpublish selected', 'quant' ) }
							</Button>
							<Button
								variant="tertiary"
								onClick={ processQueue }
								disabled={ autoProcessing }
								isBusy={ autoProcessing }
							>
								{ autoProcessing
									? __( 'Processing…', 'quant' )
									: __( 'Process queue', 'quant' ) }
							</Button>
						</div>
					</div>
				</CardBody>
			</Card>

			{ tabs.length > 0 && (
				<Card className="quant-content__browser-card">
					<TabPanel
						tabs={ tabs }
						onSelect={ () => setSelectedItems( [] ) }
					>
						{ ( tab ) => (
							<ContentBrowser
								contentType={ tab.contentType }
								contentTypes={ tab.contentTypes }
								specialType={ tab.specialType }
								onSelectionChange={ handleSelectionChange }
								key={ tab.name }
							/>
						) }
					</TabPanel>
				</Card>
			) }
		</div>
	);
}
