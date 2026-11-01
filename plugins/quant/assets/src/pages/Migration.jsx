import { useState, useEffect } from '@wordpress/element';
import {
	Card,
	CardHeader,
	CardBody,
	Button,
	Notice,
	Spinner,
	CheckboxControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useNavigate } from 'react-router-dom';

export function Migration() {
	const navigate = useNavigate();
	const [ status, setStatus ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ migrating, setMigrating ] = useState( false );
	const [ cleanupNotice, setCleanupNotice ] = useState( null );
	const [ cleaning, setCleaning ] = useState( false );
	const [ cleanupAfter, setCleanupAfter ] = useState( false );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ step, setStep ] = useState( 'detect' ); // detect, preview, migrate, complete

	useEffect( () => {
		checkMigrationStatus();
	}, [] );

	const checkMigrationStatus = async () => {
		setLoading( true );
		setError( null );
		try {
			const response = await apiFetch( {
				path: '/quant/v1/migration/status',
			} );
			setStatus( response );

			if ( response.migration_complete ) {
				setStep( 'complete' );
			} else if ( response.needs_migration ) {
				setStep( 'preview' );
			} else if ( ! response.has_v1_settings ) {
				setStep( 'no-migration' );
			}
		} catch ( err ) {
			setError(
				err.message || __( 'Failed to check migration status', 'quant' )
			);
		} finally {
			setLoading( false );
		}
	};

	const runMigration = async () => {
		setMigrating( true );
		setError( null );
		try {
			const response = await apiFetch( {
				path: '/quant/v1/migration/run',
				method: 'POST',
			} );
			setResult( response );

			if ( cleanupAfter && response.success ) {
				await cleanupV1Data();
			}

			setStep( 'complete' );
		} catch ( err ) {
			setError( err.message || __( 'Migration failed', 'quant' ) );
		} finally {
			setMigrating( false );
		}
	};

	const cleanupV1Data = async () => {
		setCleaning( true );
		setCleanupNotice( null );
		try {
			await apiFetch( {
				path: '/quant/v1/migration/cleanup',
				method: 'POST',
			} );
			setCleanupNotice( {
				status: 'success',
				message: __( 'The 1.x settings were removed.', 'quant' ),
			} );
			const refreshed = await apiFetch( {
				path: '/quant/v1/migration/status',
			} );
			setStatus( refreshed );
		} catch ( err ) {
			setCleanupNotice( {
				status: 'error',
				message:
					err.message ||
					__( 'The 1.x settings could not be removed.', 'quant' ),
			} );
		} finally {
			setCleaning( false );
		}
	};

	if ( loading ) {
		return (
			<div className="quant-migration">
				<h1>{ __( 'Migration', 'quant' ) }</h1>
				<Card>
					<CardBody>
						<div
							style={ {
								display: 'flex',
								alignItems: 'center',
								gap: '10px',
							} }
						>
							<Spinner />
							<span>
								{ __( 'Checking for v1 settings…', 'quant' ) }
							</span>
						</div>
					</CardBody>
				</Card>
			</div>
		);
	}

	if ( step === 'no-migration' ) {
		return (
			<div className="quant-migration">
				<h1>{ __( 'Migration', 'quant' ) }</h1>
				<Notice status="info" isDismissible={ false }>
					{ __(
						'No v1 settings found. Nothing to migrate.',
						'quant'
					) }
				</Notice>
				<div style={ { marginTop: '16px' } }>
					<Button
						variant="primary"
						onClick={ () => navigate( '/settings' ) }
					>
						{ __( 'Go to Settings', 'quant' ) }
					</Button>
				</div>
			</div>
		);
	}

	if ( step === 'complete' ) {
		return (
			<div className="quant-migration">
				<h1>{ __( 'Migration Complete', 'quant' ) }</h1>
				{ cleanupNotice && (
					<Notice
						status={ cleanupNotice.status }
						onRemove={ () => setCleanupNotice( null ) }
						isDismissible
					>
						{ cleanupNotice.message }
					</Notice>
				) }
				<Notice status="success" isDismissible={ false }>
					{ __(
						'Your settings have been successfully migrated to v2!',
						'quant'
					) }
				</Notice>

				{ result && result.migrated && (
					<Card style={ { marginTop: '16px' } }>
						<CardHeader>
							<h2 style={ { margin: 0 } }>
								{ __( 'Migration Summary', 'quant' ) }
							</h2>
						</CardHeader>
						<CardBody>
							<ul style={ { margin: 0, paddingLeft: '20px' } }>
								{ result.migrated.credentials && (
									<li>
										{ __(
											'Credentials migrated:',
											'quant'
										) }{ ' ' }
										{
											result.migrated.credentials
												.organization
										}{ ' ' }
										/{ ' ' }
										{ result.migrated.credentials.project }
									</li>
								) }
								{ result.migrated.mode && (
									<li>
										{ __( 'Mode set to:', 'quant' ) }{ ' ' }
										{ result.migrated.mode }
									</li>
								) }
								{ result.migrated.migration_date && (
									<li>
										{ __( 'Migrated at:', 'quant' ) }{ ' ' }
										{ result.migrated.migration_date }
									</li>
								) }
							</ul>
						</CardBody>
					</Card>
				) }

				<Card style={ { marginTop: '16px' } }>
					<CardHeader>
						<h2 style={ { margin: 0 } }>
							{ __( 'Next Steps', 'quant' ) }
						</h2>
					</CardHeader>
					<CardBody>
						<p>
							{ __(
								'Your migration is complete. Here are some recommended next steps:',
								'quant'
							) }
						</p>
						<ul style={ { paddingLeft: '20px' } }>
							<li>
								{ __(
									'Verify your connection in Settings',
									'quant'
								) }
							</li>
							<li>
								{ __(
									'Review your content sync status',
									'quant'
								) }
							</li>
							<li>
								{ __(
									'Run a full site sync if needed',
									'quant'
								) }
							</li>
						</ul>
						<div
							style={ {
								display: 'flex',
								gap: '10px',
								marginTop: '16px',
							} }
						>
							<Button
								variant="primary"
								onClick={ () => navigate( '/dashboard' ) }
							>
								{ __( 'Go to Dashboard', 'quant' ) }
							</Button>
							<Button
								variant="secondary"
								onClick={ () => navigate( '/settings' ) }
							>
								{ __( 'Review Settings', 'quant' ) }
							</Button>
						</div>
					</CardBody>
				</Card>

				{ status && status.has_v1_settings && (
					<Card style={ { marginTop: '16px' } }>
						<CardHeader>
							<h2 style={ { margin: 0 } }>
								{ __( 'Cleanup', 'quant' ) }
							</h2>
						</CardHeader>
						<CardBody>
							<p>
								{ __(
									'Old v1 data still exists. You can clean it up now or later.',
									'quant'
								) }
							</p>
							<Button
								variant="secondary"
								onClick={ cleanupV1Data }
								disabled={ cleaning }
								isBusy={ cleaning }
							>
								{ cleaning
									? __( 'Cleaning up…', 'quant' )
									: __( 'Clean Up v1 Data', 'quant' ) }
							</Button>
						</CardBody>
					</Card>
				) }
			</div>
		);
	}

	// Preview step
	return (
		<div className="quant-migration">
			<h1>{ __( 'Migrate from v1', 'quant' ) }</h1>

			{ error && (
				<Notice
					status="error"
					onRemove={ () => setError( null ) }
					isDismissible
				>
					{ error }
				</Notice>
			) }

			<Notice status="info" isDismissible={ false }>
				{ __(
					"We detected settings from v1 of the QuantCDN plugin. Let's migrate them to v2.",
					'quant'
				) }
			</Notice>

			<Card style={ { marginTop: '16px' } }>
				<CardHeader>
					<h2 style={ { margin: 0 } }>
						{ __( 'Settings to Migrate', 'quant' ) }
					</h2>
				</CardHeader>
				<CardBody>
					{ status && status.v1_settings && (
						<table
							className="widefat"
							style={ { marginBottom: '16px' } }
						>
							<tbody>
								<tr>
									<th
										style={ {
											textAlign: 'left',
											padding: '8px',
										} }
									>
										{ __( 'Organization', 'quant' ) }
									</th>
									<td style={ { padding: '8px' } }>
										{ status.v1_settings.organization ||
											'-' }
									</td>
								</tr>
								<tr>
									<th
										style={ {
											textAlign: 'left',
											padding: '8px',
										} }
									>
										{ __( 'Project', 'quant' ) }
									</th>
									<td style={ { padding: '8px' } }>
										{ status.v1_settings.project || '-' }
									</td>
								</tr>
								<tr>
									<th
										style={ {
											textAlign: 'left',
											padding: '8px',
										} }
									>
										{ __( 'API Token', 'quant' ) }
									</th>
									<td style={ { padding: '8px' } }>
										{ status.v1_settings.token || '-' }
									</td>
								</tr>
								<tr>
									<th
										style={ {
											textAlign: 'left',
											padding: '8px',
										} }
									>
										{ __( 'Endpoint', 'quant' ) }
									</th>
									<td style={ { padding: '8px' } }>
										{ status.v1_settings.endpoint ||
											'https://api.quantcdn.io' }
									</td>
								</tr>
							</tbody>
						</table>
					) }

					{ status && status.v1_queue_count > 0 && (
						<p style={ { color: '#666' } }>
							{ sprintf(
								/* translators: %d: number of v1 batch rows */
								__(
									'Note: %d rows of 1.x batch state are still in wp_options. Cleanup removes them.',
									'quant'
								),
								status.v1_queue_count
							) }
						</p>
					) }

					<CheckboxControl
						label={ __(
							'Remove old v1 data after migration',
							'quant'
						) }
						checked={ cleanupAfter }
						onChange={ setCleanupAfter }
						help={ __(
							'This will delete old options and tables after successful migration',
							'quant'
						) }
					/>
				</CardBody>
			</Card>

			<Card style={ { marginTop: '16px' } }>
				<CardHeader>
					<h2 style={ { margin: 0 } }>
						{ __( 'What will happen', 'quant' ) }
					</h2>
				</CardHeader>
				<CardBody>
					<ul style={ { margin: 0, paddingLeft: '20px' } }>
						<li>
							{ __(
								'Your API credentials will be copied to the new format',
								'quant'
							) }
						</li>
						<li>
							{ __(
								'Plugin mode will be set to "Static" (default)',
								'quant'
							) }
						</li>
						<li>
							{ __(
								'Auto-sync and auto-purge will be enabled',
								'quant'
							) }
						</li>
						<li>
							{ __(
								'A new sync queue table will be used',
								'quant'
							) }
						</li>
					</ul>
				</CardBody>
			</Card>

			<div style={ { marginTop: '16px', display: 'flex', gap: '10px' } }>
				<Button
					variant="primary"
					onClick={ runMigration }
					disabled={ migrating }
					isBusy={ migrating }
				>
					{ migrating
						? __( 'Migrating…', 'quant' )
						: __( 'Start Migration', 'quant' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ () => navigate( '/settings' ) }
					disabled={ migrating }
				>
					{ __( 'Skip & Configure Manually', 'quant' ) }
				</Button>
			</div>
		</div>
	);
}
