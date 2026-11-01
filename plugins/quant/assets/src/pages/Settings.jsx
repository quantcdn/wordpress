import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	Card,
	CardHeader,
	CardBody,
	TextControl,
	TextareaControl,
	Button,
	Notice,
	SelectControl,
	ToggleControl,
	CheckboxControl,
	Spinner,
	Flex,
	FlexItem,
	Icon,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const SCHEDULE_TYPES = [
	[ 'home', __( 'Home page', 'quant' ) ],
	[ 'post', __( 'Posts', 'quant' ) ],
	[ 'page', __( 'Pages', 'quant' ) ],
	[ 'taxonomy', __( 'Categories and tags', 'quant' ) ],
	[ 'archives', __( 'Archives', 'quant' ) ],
	[ 'authors', __( 'Author pages', 'quant' ) ],
	[ 'sitemaps', __( 'Sitemaps', 'quant' ) ],
];

const listToText = ( list ) =>
	Array.isArray( list ) ? list.join( '\n' ) : list || '';
const textToList = ( text ) =>
	text
		.split( '\n' )
		.map( ( l ) => l.trim() )
		.filter( Boolean );

function SectionCard( { title, children, description } ) {
	return (
		<Card style={ { marginBottom: '16px' } }>
			<CardHeader>
				<h2 style={ { margin: 0 } }>{ title }</h2>
			</CardHeader>
			<CardBody>
				{ description && (
					<p style={ { color: '#666', marginTop: 0 } }>
						{ description }
					</p>
				) }
				{ children }
			</CardBody>
		</Card>
	);
}

const OVERRIDE_HINT = __(
	'Set by a QUANT_* environment variable or constant. Change it there.',
	'quant'
);

/**
 * OAuth connection card: login, project selection, change project, disconnect.
 *
 * @param {Object}   props                   Component props.
 * @param {Object}   props.oauthStatus       OAuth status from the API.
 * @param {Function} props.setOauthStatus    Status setter.
 * @param {Function} props.onProjectSelected Called after a project change.
 * @param {Function} props.notify            Notice helper.
 * @param {boolean}  props.showManual        Whether manual credentials show.
 * @param {boolean}  props.manualConfigured  Manual credentials are already saved.
 * @param {Function} props.setShowManual     Toggle for manual credentials.
 */
function ConnectionCard( {
	oauthStatus,
	setOauthStatus,
	onProjectSelected,
	notify,
	showManual,
	manualConfigured,
	setShowManual,
} ) {
	const [ projects, setProjects ] = useState( [] );
	const [ projectsLoading, setProjectsLoading ] = useState( false );
	const [ projectsError, setProjectsError ] = useState( null );
	const [ selectedOrg, setSelectedOrg ] = useState( '' );
	const [ selectedProject, setSelectedProject ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ changing, setChanging ] = useState( false );

	const fetchProjects = useCallback( async () => {
		setProjectsLoading( true );
		setProjectsError( null );
		try {
			const result = await apiFetch( {
				path: '/quant/v1/oauth/projects',
			} );
			setProjects( Array.isArray( result ) ? result : [] );
		} catch ( error ) {
			setProjects( [] );
			setProjectsError(
				error.message || __( 'Failed to load projects', 'quant' )
			);
		} finally {
			setProjectsLoading( false );
		}
	}, [] );

	useEffect( () => {
		if ( oauthStatus?.connected && ! oauthStatus.has_project ) {
			fetchProjects();
		}
	}, [ oauthStatus, fetchProjects ] );

	const connect = async () => {
		try {
			const response = await apiFetch( {
				path: '/quant/v1/oauth/connect',
			} );
			if ( response.redirect_url ) {
				window.location.href = response.redirect_url;
			}
		} catch ( error ) {
			notify(
				'error',
				error.message || __( 'Failed to start the login', 'quant' )
			);
		}
	};

	const disconnect = async () => {
		try {
			await apiFetch( {
				path: '/quant/v1/oauth/disconnect',
				method: 'POST',
			} );
			setOauthStatus( { connected: false } );
			setProjects( [] );
			onProjectSelected();
			notify( 'success', __( 'Disconnected from Quant', 'quant' ) );
		} catch ( error ) {
			notify(
				'error',
				error.message || __( 'Failed to disconnect', 'quant' )
			);
		}
	};

	const selectProject = async () => {
		setBusy( true );
		try {
			await apiFetch( {
				path: '/quant/v1/oauth/select-project',
				method: 'POST',
				data: { organization: selectedOrg, project: selectedProject },
			} );
			setOauthStatus(
				await apiFetch( { path: '/quant/v1/oauth/status' } )
			);
			setChanging( false );
			onProjectSelected();
			notify( 'success', __( 'Project selected.', 'quant' ) );
		} catch ( error ) {
			notify(
				'error',
				error.message || __( 'Failed to select the project', 'quant' )
			);
		} finally {
			setBusy( false );
		}
	};

	const orgOptions = [
		{ label: __( 'Select an organization…', 'quant' ), value: '' },
	].concat(
		projects.map( ( o ) => ( {
			label: o.organization.name,
			value: o.organization.machine_name || o.organization.name,
		} ) )
	);
	const org = projects.find(
		( o ) =>
			( o.organization.machine_name || o.organization.name ) ===
			selectedOrg
	);
	const projectOptions = [
		{ label: __( 'Select a project…', 'quant' ), value: '' },
	].concat(
		( org?.projects || [] ).map( ( p ) => ( {
			label: p.name || p.machine_name,
			value: p.machine_name || p.name,
		} ) )
	);

	if ( ! oauthStatus?.connected ) {
		return (
			<SectionCard
				title={ __( 'Connect to Quant', 'quant' ) }
				description={ __(
					'Sign in to Quant and pick the project this site publishes to. You can also enter the project credentials by hand.',
					'quant'
				) }
			>
				<Flex justify="flex-start" gap={ 4 }>
					<FlexItem>
						<Button
							variant="primary"
							onClick={ connect }
							icon="admin-users"
						>
							{ __( 'Login to Quant', 'quant' ) }
						</Button>
					</FlexItem>
					{ ! manualConfigured && (
						<FlexItem>
							<Button
								variant="link"
								onClick={ () => setShowManual( ! showManual ) }
							>
								{ showManual
									? __( 'Hide manual credentials', 'quant' )
									: __(
											'Use manual credentials instead',
											'quant'
									  ) }
							</Button>
						</FlexItem>
					) }
				</Flex>
			</SectionCard>
		);
	}

	if ( oauthStatus.has_project && ! changing ) {
		return (
			<SectionCard title={ __( 'Connect to Quant', 'quant' ) }>
				<p className="quant-connected-badge">
					<Icon icon="yes-alt" /> { __( 'Connected', 'quant' ) }
					{ oauthStatus.user?.email && (
						<span style={ { color: '#666', marginLeft: '8px' } }>
							{ oauthStatus.user.email }
						</span>
					) }
				</p>
				<p style={ { margin: '8px 0' } }>
					<strong>{ __( 'Organization:', 'quant' ) }</strong>{ ' ' }
					{ oauthStatus.organization }
				</p>
				<p style={ { margin: '8px 0' } }>
					<strong>{ __( 'Project:', 'quant' ) }</strong>{ ' ' }
					{ oauthStatus.project }
				</p>
				<Flex
					justify="flex-start"
					gap={ 2 }
					style={ { marginTop: '16px' } }
				>
					<FlexItem>
						<Button
							variant="secondary"
							onClick={ () => {
								setChanging( true );
								fetchProjects();
							} }
						>
							{ __( 'Change Project', 'quant' ) }
						</Button>
					</FlexItem>
					<FlexItem>
						<Button
							variant="secondary"
							isDestructive
							onClick={ disconnect }
						>
							{ __( 'Disconnect', 'quant' ) }
						</Button>
					</FlexItem>
					<FlexItem>
						<Button
							variant="link"
							onClick={ () => setShowManual( ! showManual ) }
						>
							{ showManual
								? __( 'Hide manual credentials', 'quant' )
								: __( 'Show manual credentials', 'quant' ) }
						</Button>
					</FlexItem>
				</Flex>
			</SectionCard>
		);
	}

	if ( ! projectsLoading && ( projectsError || projects.length === 0 ) ) {
		return (
			<SectionCard title={ __( 'Connect to Quant', 'quant' ) }>
				<Notice status="warning" isDismissible={ false }>
					{ projectsError ||
						__(
							'No organizations or projects are available to this Quant account. Check the account in the Quant dashboard, or log in again.',
							'quant'
						) }
				</Notice>
				<Flex
					justify="flex-start"
					gap={ 2 }
					style={ { marginTop: '16px' } }
				>
					<FlexItem>
						<Button variant="primary" onClick={ connect }>
							{ __( 'Reconnect', 'quant' ) }
						</Button>
					</FlexItem>
					<FlexItem>
						<Button variant="secondary" onClick={ fetchProjects }>
							{ __( 'Try again', 'quant' ) }
						</Button>
					</FlexItem>
					<FlexItem>
						{ changing ? (
							<Button
								variant="tertiary"
								onClick={ () => setChanging( false ) }
							>
								{ __( 'Cancel', 'quant' ) }
							</Button>
						) : (
							<Button
								variant="tertiary"
								isDestructive
								onClick={ disconnect }
							>
								{ __( 'Disconnect', 'quant' ) }
							</Button>
						) }
					</FlexItem>
				</Flex>
			</SectionCard>
		);
	}

	return (
		<SectionCard
			title={ __( 'Connect to Quant', 'quant' ) }
			description={
				changing
					? __( 'Select a different project for this site.', 'quant' )
					: __(
							'Connected. Select the project to use with this site.',
							'quant'
					  )
			}
		>
			{ projectsLoading ? (
				<Spinner />
			) : (
				<>
					<SelectControl
						label={ __( 'Organization', 'quant' ) }
						value={ selectedOrg }
						options={ orgOptions }
						onChange={ ( v ) => {
							setSelectedOrg( v );
							setSelectedProject( '' );
						} }
					/>
					{ selectedOrg && (
						<SelectControl
							label={ __( 'Project', 'quant' ) }
							value={ selectedProject }
							options={ projectOptions }
							onChange={ setSelectedProject }
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
								onClick={ selectProject }
								disabled={
									! selectedOrg || ! selectedProject || busy
								}
								isBusy={ busy }
							>
								{ changing
									? __( 'Switch to This Project', 'quant' )
									: __( 'Use This Project', 'quant' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							{ changing ? (
								<Button
									variant="secondary"
									onClick={ () => setChanging( false ) }
								>
									{ __( 'Cancel', 'quant' ) }
								</Button>
							) : (
								<Button
									variant="secondary"
									isDestructive
									onClick={ disconnect }
								>
									{ __( 'Disconnect', 'quant' ) }
								</Button>
							) }
						</FlexItem>
					</Flex>
				</>
			) }
		</SectionCard>
	);
}

export function Settings() {
	const [ settings, setSettings ] = useState( null );
	const [ customRoutesText, setCustomRoutesText ] = useState( '' );
	const [ domainRewritesText, setDomainRewritesText ] = useState( '' );
	const [ scheduleRoutesText, setScheduleRoutesText ] = useState( '' );
	const [ oauthStatus, setOauthStatus ] = useState( null );
	const [ showManual, setShowManual ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );
	const [ testingInternal, setTestingInternal ] = useState( false );
	const [ internalResult, setInternalResult ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const notify = useCallback(
		( status, message ) => setNotice( { status, message } ),
		[]
	);

	const load = useCallback( async () => {
		const [ data, oauth ] = await Promise.all( [
			apiFetch( { path: '/quant/v1/settings' } ),
			apiFetch( { path: '/quant/v1/oauth/status' } ),
		] );
		setSettings( data );
		setOauthStatus( oauth );
		setCustomRoutesText( listToText( data.custom_routes ) );
		setDomainRewritesText( listToText( data.domain_rewrites ) );
		setScheduleRoutesText( listToText( data.schedule?.custom_routes ) );
	}, [] );

	useEffect( () => {
		if (
			new URLSearchParams( window.location.search ).get( 'oauth' ) ===
			'success'
		) {
			notify(
				'success',
				__( 'Connected to Quant. Now select a project below.', 'quant' )
			);
			window.history.replaceState(
				{},
				'',
				window.location.pathname + '?page=quant#/settings'
			);
		}
		load().catch( ( error ) =>
			notify(
				'error',
				error.message || __( 'Failed to load settings', 'quant' )
			)
		);
	}, [ load, notify ] );

	const update = ( key, value ) =>
		setSettings( ( prev ) => ( { ...prev, [ key ]: value } ) );
	const updateSchedule = ( key, value ) =>
		setSettings( ( prev ) => ( {
			...prev,
			schedule: { ...prev.schedule, [ key ]: value },
		} ) );

	const save = async () => {
		setSaving( true );
		setNotice( null );
		try {
			const data = {
				...settings,
				custom_routes: textToList( customRoutesText ),
				domain_rewrites: textToList( domainRewritesText ),
				schedule: {
					...settings.schedule,
					custom_routes: textToList( scheduleRoutesText ),
				},
			};
			delete data.overridden;
			delete data.schedules;
			delete data.has_token;
			if ( ! data.token ) {
				delete data.token;
			}
			const response = await apiFetch( {
				path: '/quant/v1/settings',
				method: 'POST',
				data,
			} );
			setSettings( { ...response, token: '' } );
			notify( 'success', __( 'Settings saved.', 'quant' ) );
		} catch ( error ) {
			notify(
				'error',
				error.message || __( 'Failed to save settings', 'quant' )
			);
		} finally {
			setSaving( false );
		}
	};

	const testConnection = async () => {
		setTesting( true );
		try {
			const status = await apiFetch( { path: '/quant/v1/status' } );
			notify(
				status.connected ? 'success' : 'error',
				status.connected
					? __( 'Connected to the Quant API.', 'quant' )
					: sprintf(
							/* translators: %s: error message */
							__( 'Connection failed: %s', 'quant' ),
							status.error ||
								__( 'check your credentials', 'quant' )
					  )
			);
		} catch ( error ) {
			notify(
				'error',
				error.message || __( 'Connection test failed', 'quant' )
			);
		} finally {
			setTesting( false );
		}
	};

	const testInternal = async () => {
		setTestingInternal( true );
		setInternalResult( null );
		try {
			setInternalResult(
				await apiFetch( {
					path: '/quant/v1/test-internal',
					method: 'POST',
				} )
			);
		} catch ( error ) {
			setInternalResult( {
				success: false,
				message: error.message || __( 'Test failed', 'quant' ),
			} );
		} finally {
			setTestingInternal( false );
		}
	};

	if ( ! settings ) {
		return (
			<div className="quant-settings">
				<h1>{ __( 'Settings', 'quant' ) }</h1>
				<Spinner />
			</div>
		);
	}

	const overridden = settings.overridden || {};
	const manualConfigured = !! (
		settings.organization &&
		settings.project &&
		settings.has_token
	);
	const isOverridden = ( group, field ) =>
		!! ( overridden[ group ] && overridden[ group ].includes( field ) );
	const helpFor = ( group, field, help ) =>
		isOverridden( group, field ) ? OVERRIDE_HINT : help;
	const scheduleOptions = Object.entries( settings.schedules || {} ).map(
		( [ value, label ] ) => ( { value, label } )
	);

	return (
		<div className="quant-settings">
			<h1>{ __( 'Settings', 'quant' ) }</h1>

			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
					isDismissible
				>
					{ notice.message }
				</Notice>
			) }

			<ConnectionCard
				oauthStatus={ oauthStatus }
				setOauthStatus={ setOauthStatus }
				onProjectSelected={ () => load().catch( () => {} ) }
				notify={ notify }
				showManual={ showManual }
				manualConfigured={ manualConfigured }
				setShowManual={ setShowManual }
			/>

			{ ( showManual ||
				( ! oauthStatus?.connected && manualConfigured ) ) && (
				<SectionCard
					title={ __( 'Project Credentials', 'quant' ) }
					description={ __(
						'Copy these from the Integrations page of the Quant dashboard.',
						'quant'
					) }
				>
					<TextControl
						label={ __( 'API Endpoint', 'quant' ) }
						value={ settings.endpoint }
						onChange={ ( v ) => update( 'endpoint', v ) }
						disabled={ isOverridden( 'credentials', 'endpoint' ) }
						help={ helpFor(
							'credentials',
							'endpoint',
							__(
								'https://api.quantcdn.io for QuantCDN, https://api.quantgov.cloud for QuantGov. Without /v1.',
								'quant'
							)
						) }
					/>
					<TextControl
						label={ __( 'Organization (API Customer)', 'quant' ) }
						value={ settings.organization }
						onChange={ ( v ) => update( 'organization', v ) }
						disabled={ isOverridden(
							'credentials',
							'organization'
						) }
						help={ helpFor( 'credentials', 'organization' ) }
					/>
					<TextControl
						label={ __( 'Project', 'quant' ) }
						value={ settings.project }
						onChange={ ( v ) => update( 'project', v ) }
						disabled={ isOverridden( 'credentials', 'project' ) }
						help={ helpFor( 'credentials', 'project' ) }
					/>
					<TextControl
						label={ __( 'API Token', 'quant' ) }
						type="password"
						value={ settings.token }
						onChange={ ( v ) => update( 'token', v ) }
						disabled={ isOverridden( 'credentials', 'token' ) }
						placeholder={
							settings.has_token
								? __(
										'Token saved. Enter a new one to replace it.',
										'quant'
								  )
								: __( 'Project token', 'quant' )
						}
						help={ helpFor( 'credentials', 'token' ) }
					/>
					<Flex
						justify="flex-start"
						gap={ 2 }
						style={ { marginTop: '16px' } }
					>
						<FlexItem>
							<Button
								variant="primary"
								onClick={ save }
								disabled={ saving }
								isBusy={ saving }
							>
								{ __( 'Save Credentials', 'quant' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button
								variant="secondary"
								onClick={ testConnection }
								disabled={ testing }
								isBusy={ testing }
							>
								{ __( 'Test Connection', 'quant' ) }
							</Button>
						</FlexItem>
					</Flex>
				</SectionCard>
			) }

			<SectionCard
				title={ __( 'API Connection', 'quant' ) }
				description={ __(
					'Applies to every request the plugin makes, in both modes.',
					'quant'
				) }
			>
				<TextControl
					label={ __( 'HTTP request timeout (seconds)', 'quant' ) }
					type="number"
					value={ settings.http_request_timeout }
					onChange={ ( v ) =>
						update(
							'http_request_timeout',
							parseInt( v, 10 ) || 30
						)
					}
					disabled={ isOverridden(
						'credentials',
						'http_request_timeout'
					) }
				/>
				<ToggleControl
					label={ __( 'Disable SSL verification', 'quant' ) }
					checked={ !! settings.disable_tls_verify }
					onChange={ ( v ) => update( 'disable_tls_verify', v ) }
					disabled={ isOverridden(
						'credentials',
						'disable_tls_verify'
					) }
					help={ __(
						'Only for local servers with self-signed certificates.',
						'quant'
					) }
				/>
			</SectionCard>

			<SectionCard title={ __( 'Mode', 'quant' ) }>
				<SelectControl
					label={ __( 'Plugin mode', 'quant' ) }
					value={ settings.mode }
					disabled={ isOverridden( 'mode', 'mode' ) }
					options={ [
						{
							label: __(
								'Static Site: publish the whole site to the Quant edge',
								'quant'
							),
							value: 'static',
						},
						{
							label: __(
								'CDN Only: WordPress serves pages, Quant caches and purges them',
								'quant'
							),
							value: 'cdn_only',
						},
					] }
					onChange={ ( v ) => update( 'mode', v ) }
				/>
				<ToggleControl
					label={ __( 'Automatic static push', 'quant' ) }
					checked={ !! settings.auto_sync }
					onChange={ ( v ) => update( 'auto_sync', v ) }
					disabled={
						settings.mode !== 'static' ||
						isOverridden( 'sync', 'auto_sync' )
					}
					help={ __(
						'Push pages, terms and media to Quant when they change (Static mode).',
						'quant'
					) }
				/>
				<ToggleControl
					label={ __( 'Also push related pages', 'quant' ) }
					checked={ settings.sync_related !== false }
					onChange={ ( v ) => update( 'sync_related', v ) }
					disabled={
						settings.mode !== 'static' || ! settings.auto_sync
					}
					help={ __(
						'When a post changes, also push the home page, its categories, tags, author and archive pages.',
						'quant'
					) }
				/>
				<ToggleControl
					label={ __( 'Cache purge on save', 'quant' ) }
					checked={ !! settings.auto_purge }
					onChange={ ( v ) => update( 'auto_purge', v ) }
					disabled={ isOverridden( 'sync', 'auto_purge' ) }
					help={ __(
						'Purge the edge cache when content changes. Used in CDN Only mode, or in Static mode when automatic push is off.',
						'quant'
					) }
				/>
			</SectionCard>

			{ settings.mode === 'static' && (
				<SectionCard
					title={ __( 'Static Site Rendering', 'quant' ) }
					description={ __(
						'How the plugin fetches pages from this server before it pushes them.',
						'quant'
					) }
				>
					<div className="quant-inline-test">
						<strong>
							{ __( 'Internal connectivity', 'quant' ) }
						</strong>
						{ internalResult === null ? (
							<span style={ { color: '#757575' } }>
								{ __( 'Not tested', 'quant' ) }
							</span>
						) : (
							<span
								style={ {
									color: internalResult.success
										? '#00a32a'
										: '#d63638',
								} }
							>
								{ internalResult.success
									? '✓ ' + __( 'Working', 'quant' )
									: '✗ ' + internalResult.message }
							</span>
						) }
						<Button
							variant="secondary"
							size="small"
							onClick={ testInternal }
							disabled={ testingInternal }
							isBusy={ testingInternal }
						>
							{ __( 'Test', 'quant' ) }
						</Button>
						{ internalResult?.adjusted_url && (
							<div className="quant-inline-test__detail">
								{ internalResult.adjusted_url }
								{ internalResult.host_header
									? ` (Host: ${ internalResult.host_header })`
									: '' }
							</div>
						) }
					</div>
					<TextControl
						label={ __( 'Webserver URL', 'quant' ) }
						value={ settings.webserver_url }
						onChange={ ( v ) => update( 'webserver_url', v ) }
						placeholder="http://localhost"
						disabled={ isOverridden( 'static', 'webserver_url' ) }
						help={ helpFor(
							'static',
							'webserver_url',
							__(
								'Where the plugin can reach this site from the server, for example http://localhost. Leave empty to use the site URL.',
								'quant'
							)
						) }
					/>
					<TextControl
						label={ __( 'Hostname (Host header)', 'quant' ) }
						value={ settings.webserver_host }
						onChange={ ( v ) => update( 'webserver_host', v ) }
						placeholder="www.example.com"
						disabled={ isOverridden( 'static', 'webserver_host' ) }
						help={ helpFor(
							'static',
							'webserver_host',
							__(
								'The public hostname WordPress expects, so rendered links use the public domain.',
								'quant'
							)
						) }
					/>
					<ToggleControl
						label={ __(
							'Strip host domain from content',
							'quant'
						) }
						checked={ !! settings.strip_host }
						onChange={ ( v ) => update( 'strip_host', v ) }
						help={ __(
							'Rewrite absolute links to this site as relative links, and upgrade http:// links to https://.',
							'quant'
						) }
					/>
					<TextareaControl
						label={ __(
							'Additional domains to make relative',
							'quant'
						) }
						value={ domainRewritesText }
						onChange={ setDomainRewritesText }
						rows={ 3 }
						placeholder="cdn.example.com"
						help={ __(
							'One per line. Useful for staging or editorial domains.',
							'quant'
						) }
					/>
					<ToggleControl
						label={ __( 'Follow pagination links', 'quant' ) }
						checked={ !! settings.follow_links }
						onChange={ ( v ) => update( 'follow_links', v ) }
						help={ __(
							'Queue the next pages of listings found in rendered markup.',
							'quant'
						) }
					/>
					<ToggleControl
						label={ __( 'Override existing proxies', 'quant' ) }
						checked={ !! settings.proxy_override }
						onChange={ ( v ) => update( 'proxy_override', v ) }
						help={ __(
							'Tell Quant that pushed pages replace proxy rules at the same path.',
							'quant'
						) }
					/>
				</SectionCard>
			) }

			{ settings.mode === 'static' && (
				<SectionCard
					title={ __( 'Seed Content', 'quant' ) }
					description={ __(
						'What a full push includes besides posts, pages, terms and media.',
						'quant'
					) }
				>
					<ToggleControl
						label={ __( 'Theme assets', 'quant' ) }
						checked={ !! settings.theme_assets }
						onChange={ ( v ) => update( 'theme_assets', v ) }
						help={ __(
							'CSS, JavaScript, images and fonts of the active theme and its parent.',
							'quant'
						) }
					/>
					<ToggleControl
						label={ __( 'Sitemaps', 'quant' ) }
						checked={ !! settings.sitemap }
						onChange={ ( v ) => update( 'sitemap', v ) }
						help={ __(
							'WordPress core sitemaps, Yoast SEO and Rank Math sitemaps with their stylesheets.',
							'quant'
						) }
					/>
					<TextControl
						label={ __( '404 page path', 'quant' ) }
						value={ settings.route_404 }
						onChange={ ( v ) => update( 'route_404', v ) }
						placeholder="/404"
						help={ __(
							'A path that returns your 404 page. It is published as the Quant 404 page.',
							'quant'
						) }
					/>
					<TextareaControl
						label={ __( 'Custom routes', 'quant' ) }
						value={ customRoutesText }
						onChange={ setCustomRoutesText }
						rows={ 4 }
						placeholder={ '/robots.txt\n/custom-page' }
						help={ __(
							'One per line. Content routes are rendered; paths that match a file on disk are uploaded.',
							'quant'
						) }
					/>
				</SectionCard>
			) }

			{ settings.mode === 'static' && (
				<SectionCard
					title={ __( 'Scheduled Push', 'quant' ) }
					description={ __(
						'Re-push chosen content on a schedule, for example for listings that change without a save.',
						'quant'
					) }
				>
					<ToggleControl
						label={ __( 'Enable scheduled push', 'quant' ) }
						checked={ !! settings.schedule?.enabled }
						onChange={ ( v ) => updateSchedule( 'enabled', v ) }
					/>
					{ settings.schedule?.enabled && (
						<>
							<SelectControl
								label={ __( 'Schedule', 'quant' ) }
								value={ settings.schedule.interval }
								options={ scheduleOptions }
								onChange={ ( v ) =>
									updateSchedule( 'interval', v )
								}
							/>
							{ SCHEDULE_TYPES.map( ( [ type, label ] ) => (
								<CheckboxControl
									key={ type }
									label={ label }
									checked={ (
										settings.schedule.types || []
									).includes( type ) }
									onChange={ ( checked ) =>
										updateSchedule(
											'types',
											checked
												? [
														...( settings.schedule
															.types || [] ),
														type,
												  ]
												: (
														settings.schedule
															.types || []
												  ).filter(
														( t ) => t !== type
												  )
										)
									}
								/>
							) ) }
							<TextareaControl
								label={ __( 'Custom routes', 'quant' ) }
								value={ scheduleRoutesText }
								onChange={ setScheduleRoutesText }
								rows={ 3 }
								placeholder="/robots.txt"
							/>
						</>
					) }
				</SectionCard>
			) }

			<div style={ { marginTop: '16px' } }>
				<Button
					variant="primary"
					onClick={ save }
					disabled={ saving }
					isBusy={ saving }
				>
					{ __( 'Save Settings', 'quant' ) }
				</Button>
			</div>
		</div>
	);
}
