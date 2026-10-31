import { useState, useEffect } from '@wordpress/element';
import {
	Card,
	CardHeader,
	CardBody,
	Button,
	Notice,
	Spinner,
	TextControl,
	SelectControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useNavigate } from 'react-router-dom';

/**
 * Result panel of the connection test step.
 *
 * @param {Object} props        Component props.
 * @param {string} props.result 'success', 'error' or null.
 */
function TestResult( { result } ) {
	if ( result === 'success' ) {
		return (
			<div style={ { padding: '20px' } }>
				<span
					className="dashicons dashicons-yes-alt"
					style={ {
						fontSize: '48px',
						color: '#00a32a',
						display: 'block',
						marginBottom: '15px',
					} }
				></span>
				<p style={ { fontSize: '18px', color: '#00a32a', margin: 0 } }>
					{ __( 'Connection successful!', 'quant' ) }
				</p>
			</div>
		);
	}

	if ( result === 'error' ) {
		return (
			<div style={ { padding: '20px' } }>
				<span
					className="dashicons dashicons-dismiss"
					style={ {
						fontSize: '48px',
						color: '#d63638',
						display: 'block',
						marginBottom: '15px',
					} }
				></span>
				<p style={ { fontSize: '18px', color: '#d63638', margin: 0 } }>
					{ __( 'Connection failed', 'quant' ) }
				</p>
			</div>
		);
	}

	return (
		<div style={ { padding: '20px' } }>
			<p style={ { color: '#666', marginTop: 0 } }>
				{ __( "Let's verify your connection to QuantCDN.", 'quant' ) }
			</p>
		</div>
	);
}

const PLATFORMS = [
	{ label: 'QuantCDN', value: 'https://api.quantcdn.io' },
	{ label: 'QuantGov', value: 'https://api.quantgov.cloud' },
];

export function Onboarding() {
	const navigate = useNavigate();
	const [ step, setStep ] = useState( 1 ); // 1: welcome, 2: credentials, 3: mode, 4: test, 5: success
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ testResult, setTestResult ] = useState( null );

	const [ settings, setSettings ] = useState( {
		organization: '',
		project: '',
		token: '',
		endpoint: 'https://api.quantcdn.io',
		mode: 'static',
	} );

	useEffect( () => {
		checkOnboardingStatus();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const checkOnboardingStatus = async () => {
		try {
			const response = await apiFetch( {
				path: '/quant/v1/onboarding/status',
			} );

			if ( response.state === 'migration' ) {
				navigate( '/migration' );
				return;
			}

			if ( response.state === 'configured' ) {
				navigate( '/dashboard' );
			}
		} catch ( err ) {
			// Continue with onboarding if status check fails
		} finally {
			setLoading( false );
		}
	};

	const updateSetting = ( key, value ) => {
		setSettings( ( prev ) => ( { ...prev, [ key ]: value } ) );
	};

	const handleSaveCredentials = async () => {
		setSaving( true );
		setError( null );

		try {
			await apiFetch( {
				path: '/quant/v1/settings',
				method: 'POST',
				data: settings,
			} );
			setStep( 3 ); // Move to mode selection
		} catch ( err ) {
			setError(
				err.message || __( 'Failed to save credentials', 'quant' )
			);
		} finally {
			setSaving( false );
		}
	};

	const handleOAuthLogin = async () => {
		setSaving( true );
		setError( null );
		try {
			// Save the platform first: the login uses the dashboard that
			// belongs to the saved endpoint (QuantCDN or QuantGov).
			await apiFetch( {
				path: '/quant/v1/settings',
				method: 'POST',
				data: { endpoint: settings.endpoint, mode: settings.mode },
			} );
			const response = await apiFetch( {
				path: '/quant/v1/oauth/connect',
			} );
			if ( response.redirect_url ) {
				window.location.href = response.redirect_url;
				return;
			}
			setError( __( 'The login could not be started.', 'quant' ) );
		} catch ( err ) {
			setError(
				err.message || __( 'The login could not be started.', 'quant' )
			);
		}
		setSaving( false );
	};

	const platform = PLATFORMS.find( ( p ) => p.value === settings.endpoint )
		? settings.endpoint
		: 'custom';

	const handleSaveMode = async () => {
		setSaving( true );
		setError( null );

		try {
			await apiFetch( {
				path: '/quant/v1/settings',
				method: 'POST',
				data: { mode: settings.mode },
			} );
			setStep( 4 ); // Move to test
		} catch ( err ) {
			setError( err.message || __( 'Failed to save mode', 'quant' ) );
		} finally {
			setSaving( false );
		}
	};

	const handleTestConnection = async () => {
		setTesting( true );
		setError( null );
		setTestResult( null );

		try {
			const response = await apiFetch( { path: '/quant/v1/status' } );
			if ( response.connected ) {
				setTestResult( 'success' );
				setTimeout( () => setStep( 5 ), 1500 ); // Move to success after delay
			} else {
				setTestResult( 'error' );
				setError(
					__(
						'Connection failed. Please check your credentials.',
						'quant'
					)
				);
			}
		} catch ( err ) {
			setTestResult( 'error' );
			setError( err.message || __( 'Connection test failed', 'quant' ) );
		} finally {
			setTesting( false );
		}
	};

	if ( loading ) {
		return (
			<div className="quant-onboarding">
				<div
					style={ {
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'center',
						minHeight: '200px',
					} }
				>
					<Spinner />
				</div>
			</div>
		);
	}

	const renderStep = () => {
		switch ( step ) {
			case 1:
				return (
					<Card className="quant-onboarding__card">
						<CardBody
							style={ { textAlign: 'center', padding: '40px' } }
						>
							<svg
								width="64"
								height="64"
								viewBox="0 0 32 32"
								fill="none"
								style={ { marginBottom: '20px' } }
							>
								<rect
									width="32"
									height="32"
									rx="6"
									fill="#5c40ff"
								/>
								<text
									x="50%"
									y="55%"
									dominantBaseline="middle"
									textAnchor="middle"
									fill="white"
									fontSize="16"
									fontWeight="bold"
								>
									Q
								</text>
							</svg>
							<h1 style={ { marginBottom: '10px' } }>
								{ __( 'Welcome to QuantCDN', 'quant' ) }
							</h1>
							<p
								style={ {
									color: '#666',
									fontSize: '16px',
									maxWidth: '500px',
									margin: '0 auto 30px',
								} }
							>
								{ __(
									"Let's get your WordPress site connected to QuantCDN. This wizard will guide you through the setup process.",
									'quant'
								) }
							</p>
							<Button
								variant="primary"
								onClick={ () => setStep( 2 ) }
								style={ { padding: '10px 30px' } }
							>
								{ __( 'Get Started', 'quant' ) }
							</Button>
						</CardBody>
					</Card>
				);

			case 2:
				return (
					<Card className="quant-onboarding__card">
						<CardHeader>
							<h2 style={ { margin: 0 } }>
								{ __( 'Step 1: API Credentials', 'quant' ) }
							</h2>
						</CardHeader>
						<CardBody>
							{ error && (
								<Notice
									status="error"
									onRemove={ () => setError( null ) }
									isDismissible
								>
									{ error }
								</Notice>
							) }

							<SelectControl
								label={ __( 'Platform', 'quant' ) }
								value={ platform }
								options={ PLATFORMS.concat( [
									{
										label: __( 'Other endpoint', 'quant' ),
										value: 'custom',
									},
								] ) }
								onChange={ ( v ) => {
									if ( v !== 'custom' ) {
										updateSetting( 'endpoint', v );
									}
								} }
								help={ __(
									'Choose the dashboard you sign in to.',
									'quant'
								) }
							/>

							<div className="quant-onboarding__oauth">
								<Button
									variant="primary"
									icon="admin-users"
									onClick={ handleOAuthLogin }
									disabled={ saving }
									isBusy={ saving }
								>
									{ __( 'Login to Quant', 'quant' ) }
								</Button>
								<p style={ { color: '#666' } }>
									{ __(
										'Sign in and pick the project, or enter the project credentials below.',
										'quant'
									) }
								</p>
							</div>

							<p style={ { color: '#666', marginTop: 0 } }>
								{ __(
									'Or enter the project credentials. You can find them in the',
									'quant'
								) }{ ' ' }
								<a
									href={
										platform ===
										'https://api.quantgov.cloud'
											? 'https://dash.quantgov.cloud'
											: 'https://dashboard.quantcdn.io'
									}
									target="_blank"
									rel="noopener noreferrer"
								>
									{ __( 'Quant dashboard', 'quant' ) }
								</a>{ ' ' }
								{ __(
									'on the Integrations page of the project.',
									'quant'
								) }
							</p>

							<TextControl
								label={ __( 'Organization', 'quant' ) }
								value={ settings.organization }
								onChange={ ( v ) =>
									updateSetting( 'organization', v )
								}
								help={ __(
									'Your QuantCDN organization identifier',
									'quant'
								) }
							/>
							<TextControl
								label={ __( 'Project', 'quant' ) }
								value={ settings.project }
								onChange={ ( v ) =>
									updateSetting( 'project', v )
								}
								help={ __(
									'Your QuantCDN project name',
									'quant'
								) }
							/>
							<TextControl
								label={ __( 'API Token', 'quant' ) }
								type="password"
								value={ settings.token }
								onChange={ ( v ) =>
									updateSetting( 'token', v )
								}
								help={ __( 'Your project API token', 'quant' ) }
							/>
							<TextControl
								label={ __( 'API Endpoint', 'quant' ) }
								value={ settings.endpoint }
								onChange={ ( v ) =>
									updateSetting( 'endpoint', v )
								}
								help={ __(
									'https://api.quantcdn.io for QuantCDN, https://api.quantgov.cloud for QuantGov. Without /v1.',
									'quant'
								) }
							/>

							<div
								style={ {
									marginTop: '20px',
									display: 'flex',
									gap: '10px',
								} }
							>
								<Button
									variant="primary"
									onClick={ handleSaveCredentials }
									disabled={
										saving ||
										! settings.organization ||
										! settings.project ||
										! settings.token
									}
									isBusy={ saving }
								>
									{ saving
										? __( 'Saving…', 'quant' )
										: __( 'Continue', 'quant' ) }
								</Button>
							</div>
						</CardBody>
					</Card>
				);

			case 3:
				return (
					<Card className="quant-onboarding__card">
						<CardHeader>
							<h2 style={ { margin: 0 } }>
								{ __( 'Step 2: Choose Mode', 'quant' ) }
							</h2>
						</CardHeader>
						<CardBody>
							{ error && (
								<Notice
									status="error"
									onRemove={ () => setError( null ) }
									isDismissible
								>
									{ error }
								</Notice>
							) }

							<p style={ { color: '#666', marginTop: 0 } }>
								{ __(
									'Choose how you want to use QuantCDN with your WordPress site.',
									'quant'
								) }
							</p>

							<div style={ { marginBottom: '20px' } }>
								<SelectControl
									label={ __( 'Plugin Mode', 'quant' ) }
									value={ settings.mode }
									options={ [
										{
											label: __(
												'Static Site (Recommended)',
												'quant'
											),
											value: 'static',
										},
										{
											label: __( 'CDN Only', 'quant' ),
											value: 'cdn_only',
										},
									] }
									onChange={ ( v ) =>
										updateSetting( 'mode', v )
									}
								/>
							</div>

							<div
								style={ {
									backgroundColor: '#f0f0f0',
									padding: '15px',
									borderRadius: '4px',
									marginBottom: '20px',
								} }
							>
								{ settings.mode === 'static' ? (
									<>
										<strong>
											{ __(
												'Static Site Mode',
												'quant'
											) }
										</strong>
										<p
											style={ {
												margin: '10px 0 0',
												color: '#666',
											} }
										>
											{ __(
												"Your entire site is rendered and served from QuantCDN's edge. Pages are synced automatically when you publish or update content. Ideal for maximum performance and security.",
												'quant'
											) }
										</p>
									</>
								) : (
									<>
										<strong>
											{ __( 'CDN Only Mode', 'quant' ) }
										</strong>
										<p
											style={ {
												margin: '10px 0 0',
												color: '#666',
											} }
										>
											{ __(
												'WordPress serves dynamic content while QuantCDN handles caching. Cache is automatically purged when content changes. Good for sites that need dynamic features.',
												'quant'
											) }
										</p>
									</>
								) }
							</div>

							<div style={ { display: 'flex', gap: '10px' } }>
								<Button
									variant="secondary"
									onClick={ () => setStep( 2 ) }
								>
									{ __( 'Back', 'quant' ) }
								</Button>
								<Button
									variant="primary"
									onClick={ handleSaveMode }
									disabled={ saving }
									isBusy={ saving }
								>
									{ saving
										? __( 'Saving…', 'quant' )
										: __( 'Continue', 'quant' ) }
								</Button>
							</div>
						</CardBody>
					</Card>
				);

			case 4:
				return (
					<Card className="quant-onboarding__card">
						<CardHeader>
							<h2 style={ { margin: 0 } }>
								{ __( 'Step 3: Test Connection', 'quant' ) }
							</h2>
						</CardHeader>
						<CardBody style={ { textAlign: 'center' } }>
							{ error && (
								<Notice
									status="error"
									onRemove={ () => setError( null ) }
									isDismissible
								>
									{ error }
								</Notice>
							) }

							<TestResult result={ testResult } />

							<div
								style={ {
									display: 'flex',
									justifyContent: 'center',
									gap: '10px',
									marginTop: '20px',
								} }
							>
								<Button
									variant="secondary"
									onClick={ () => setStep( 2 ) }
									disabled={ testing }
								>
									{ __( 'Back to Credentials', 'quant' ) }
								</Button>
								<Button
									variant="primary"
									onClick={ handleTestConnection }
									disabled={ testing }
									isBusy={ testing }
								>
									{ testing
										? __( 'Testing…', 'quant' )
										: __( 'Test Connection', 'quant' ) }
								</Button>
							</div>
						</CardBody>
					</Card>
				);

			case 5:
				return (
					<Card className="quant-onboarding__card">
						<CardBody
							style={ { textAlign: 'center', padding: '40px' } }
						>
							<span
								className="dashicons dashicons-yes-alt"
								style={ {
									fontSize: '64px',
									color: '#00a32a',
									display: 'block',
									marginBottom: '20px',
								} }
							></span>
							<h1 style={ { marginBottom: '10px' } }>
								{ __( "You're All Set!", 'quant' ) }
							</h1>
							<p
								style={ {
									color: '#666',
									fontSize: '16px',
									maxWidth: '500px',
									margin: '0 auto 30px',
								} }
							>
								{ settings.mode === 'static'
									? __(
											'Your site is now connected to QuantCDN. Head to the Content page to sync your first pages.',
											'quant'
									  )
									: __(
											'Your site is now connected to QuantCDN. Cache will be automatically purged when you update content.',
											'quant'
									  ) }
							</p>
							<div
								style={ {
									display: 'flex',
									justifyContent: 'center',
									gap: '10px',
								} }
							>
								<Button
									variant="primary"
									onClick={ () => navigate( '/dashboard' ) }
								>
									{ __( 'Go to Dashboard', 'quant' ) }
								</Button>
								{ settings.mode === 'static' && (
									<Button
										variant="secondary"
										onClick={ () => navigate( '/content' ) }
									>
										{ __( 'Sync Content', 'quant' ) }
									</Button>
								) }
							</div>
						</CardBody>
					</Card>
				);

			default:
				return null;
		}
	};

	return (
		<div
			className="quant-onboarding"
			style={ { maxWidth: '700px', margin: '0 auto' } }
		>
			{ /* Progress indicator */ }
			{ step >= 2 && step <= 4 && (
				<div
					style={ {
						display: 'flex',
						justifyContent: 'center',
						gap: '10px',
						marginBottom: '30px',
					} }
				>
					{ [ 2, 3, 4 ].map( ( s ) => (
						<div
							key={ s }
							style={ {
								width: '12px',
								height: '12px',
								borderRadius: '50%',
								backgroundColor: step >= s ? '#5c40ff' : '#ddd',
							} }
						/>
					) ) }
				</div>
			) }

			{ renderStep() }
		</div>
	);
}
