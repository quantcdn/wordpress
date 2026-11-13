import {
	Card,
	CardHeader,
	CardBody,
	Spinner,
	Button,
	Notice,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { useNavigate } from 'react-router-dom';

function Stat( { label, value, tone } ) {
	return (
		<div className={ `quant-stat quant-stat--${ tone || 'neutral' }` }>
			<div className="quant-stat__value">{ value }</div>
			<div className="quant-stat__label">{ label }</div>
		</div>
	);
}

export function Dashboard() {
	const navigate = useNavigate();
	const [ status, setStatus ] = useState( null );
	const [ errors, setErrors ] = useState( [] );
	const [ processing, setProcessing ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ loadError, setLoadError ] = useState( null );

	const load = useCallback( async () => {
		try {
			const [ data, recent ] = await Promise.all( [
				apiFetch( { path: '/quant/v1/status' } ),
				apiFetch( { path: '/quant/v1/queue/errors' } ),
			] );
			setStatus( data );
			setErrors( recent );
			setLoadError( null );
		} catch ( e ) {
			setLoadError(
				e.message || __( 'Failed to load the plugin status.', 'quant' )
			);
			setStatus( {
				connected: false,
				configured: false,
				mode: 'static',
				queue: {},
			} );
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	useEffect( () => {
		if ( status?.configured === false ) {
			apiFetch( { path: '/quant/v1/onboarding/status' } )
				.then( ( onboarding ) => {
					if ( onboarding.state === 'onboarding' ) {
						navigate( '/onboarding' );
					} else if ( onboarding.state === 'migration' ) {
						navigate( '/migration' );
					}
				} )
				.catch( () => {} );
		}
	}, [ status, navigate ] );

	const processNow = async () => {
		setProcessing( true );
		setNotice( null );
		try {
			const result = await apiFetch( {
				path: '/quant/v1/queue/process',
				method: 'POST',
				data: { limit: 20 },
			} );
			setNotice( {
				status: result.failed > 0 ? 'warning' : 'success',
				message: sprintf(
					/* translators: 1: processed items, 2: failed items, 3: items still pending */
					__(
						'Processed %1$d items, %2$d failed, %3$d still pending.',
						'quant'
					),
					result.processed || 0,
					result.failed || 0,
					result.remaining || 0
				),
			} );
		} catch ( e ) {
			setNotice( {
				status: 'error',
				message:
					e.message || __( 'Failed to process the queue.', 'quant' ),
			} );
		} finally {
			setProcessing( false );
			await load();
		}
	};

	const retryFailed = async () => {
		setNotice( null );
		try {
			const result = await apiFetch( {
				path: '/quant/v1/queue/retry',
				method: 'POST',
			} );
			setNotice( {
				status: 'info',
				message: sprintf(
					/* translators: %d: number of items */
					__( '%d failed items were queued again.', 'quant' ),
					result.queued || 0
				),
			} );
		} catch ( e ) {
			setNotice( {
				status: 'error',
				message: e.message || __( 'Failed to retry.', 'quant' ),
			} );
		}
		await load();
	};

	if ( ! status ) {
		return (
			<div className="quant-dashboard">
				<h1>{ __( 'Dashboard', 'quant' ) }</h1>
				<Spinner />
			</div>
		);
	}

	const queue = status.queue || {};
	const isStatic = status.mode === 'static';

	return (
		<div className="quant-dashboard">
			<h1>{ __( 'Dashboard', 'quant' ) }</h1>

			{ loadError && (
				<Notice status="error" isDismissible={ false }>
					{ loadError }
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

			{ status.cron_disabled && queue.pending > 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'WP-Cron is disabled on this site. Pending items are only processed by "Process Queue", a system cron that calls wp-cron.php, or WP-CLI.',
						'quant'
					) }
				</Notice>
			) }

			<div className="quant-dashboard__cards">
				<Card>
					<CardHeader>
						<h2 style={ { margin: 0 } }>
							{ __( 'Connection', 'quant' ) }
						</h2>
					</CardHeader>
					<CardBody>
						{ status.connected ? (
							<p className="quant-status--success">
								{ __( 'Connected to Quant', 'quant' ) }
								{ status.search_enabled
									? ' · ' + __( 'Search enabled', 'quant' )
									: '' }
							</p>
						) : (
							<p className="quant-status--error">
								{ status.configured
									? sprintf(
											/* translators: %s: error message */
											__( 'Not connected: %s', 'quant' ),
											status.error || ''
									  )
									: __( 'Not configured yet.', 'quant' ) }
							</p>
						) }
						<Button
							variant="link"
							onClick={ () => navigate( '/settings' ) }
						>
							{ __( 'Settings', 'quant' ) }
						</Button>
					</CardBody>
				</Card>

				<Card>
					<CardHeader>
						<h2 style={ { margin: 0 } }>
							{ __( 'Mode', 'quant' ) }
						</h2>
					</CardHeader>
					<CardBody>
						<p>
							<strong>
								{ isStatic
									? __( 'Static Site', 'quant' )
									: __( 'CDN Only', 'quant' ) }
							</strong>
							<br />
							<span style={ { color: '#666', fontSize: '13px' } }>
								{ isStatic
									? __(
											'Pages are published to the Quant edge.',
											'quant'
									  )
									: __(
											'Quant caches WordPress and purges on change.',
											'quant'
									  ) }
							</span>
						</p>
					</CardBody>
				</Card>

				<Card>
					<CardHeader>
						<h2 style={ { margin: 0 } }>
							{ __( 'Quick Actions', 'quant' ) }
						</h2>
					</CardHeader>
					<CardBody>
						<p>
							{ isStatic && (
								<>
									<Button
										variant="link"
										onClick={ () => navigate( '/content' ) }
									>
										{ __( 'Push content', 'quant' ) }
									</Button>
									<br />
								</>
							) }
							<Button
								variant="link"
								onClick={ () => navigate( '/cache' ) }
							>
								{ __( 'Purge cache', 'quant' ) }
							</Button>
							<br />
							<Button
								variant="link"
								onClick={ () => navigate( '/diagnostics' ) }
							>
								{ __( 'Diagnostics', 'quant' ) }
							</Button>
						</p>
					</CardBody>
				</Card>
			</div>

			{ isStatic && (
				<Card style={ { marginTop: '16px' } }>
					<CardHeader>
						<h2 style={ { margin: 0 } }>
							{ __( 'Sync Queue', 'quant' ) }
						</h2>
						<div>
							<Button
								variant="secondary"
								size="small"
								onClick={ processNow }
								disabled={ processing || ! queue.pending }
								isBusy={ processing }
							>
								{ __( 'Process now', 'quant' ) }
							</Button>{ ' ' }
							{ queue.failed > 0 && (
								<Button
									variant="tertiary"
									size="small"
									onClick={ retryFailed }
								>
									{ __( 'Retry failed', 'quant' ) }
								</Button>
							) }
						</div>
					</CardHeader>
					<CardBody>
						<div className="quant-stats">
							<Stat
								label={ __( 'Pending', 'quant' ) }
								value={ queue.pending ?? 0 }
								tone="info"
							/>
							<Stat
								label={ __( 'Processing', 'quant' ) }
								value={ queue.processing ?? 0 }
								tone="warning"
							/>
							<Stat
								label={ __( 'Failed', 'quant' ) }
								value={ queue.failed ?? 0 }
								tone="error"
							/>
							<Stat
								label={ __( 'Completed', 'quant' ) }
								value={ queue.completed ?? 0 }
								tone="success"
							/>
						</div>
						{ status.next_cron && (
							<p style={ { color: '#666', fontSize: '13px' } }>
								{ sprintf(
									/* translators: %s: time of the next cron run */
									__( 'Next background run: %s', 'quant' ),
									status.next_cron_label ||
										new Date(
											status.next_cron * 1000
										).toLocaleTimeString()
								) }
							</p>
						) }
						{ errors.length > 0 && (
							<table
								className="widefat striped"
								style={ { marginTop: '12px' } }
							>
								<thead>
									<tr>
										<th>{ __( 'Type', 'quant' ) }</th>
										<th>{ __( 'Item', 'quant' ) }</th>
										<th>{ __( 'Error', 'quant' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ errors.slice( 0, 5 ).map( ( e, i ) => (
										<tr key={ i }>
											<td>{ e.type }</td>
											<td>
												<code>{ e.object_id }</code>
											</td>
											<td style={ { color: '#721c24' } }>
												{ e.error_message }
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						) }
					</CardBody>
				</Card>
			) }
		</div>
	);
}
