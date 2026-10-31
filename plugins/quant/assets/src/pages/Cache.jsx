import { useState } from '@wordpress/element';
import {
	Card,
	CardHeader,
	CardBody,
	TextareaControl,
	Button,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

export function Cache() {
	const [ paths, setPaths ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const handlePurge = async () => {
		if ( ! paths.trim() ) {
			return;
		}

		setLoading( true );
		setNotice( null );

		try {
			const pathList = paths.split( '\n' ).filter( ( p ) => p.trim() );
			await apiFetch( {
				path: '/quant/v1/purge',
				method: 'POST',
				data: { paths: pathList },
			} );
			setNotice( {
				status: 'success',
				message: __( 'Cache purged successfully', 'quant' ),
			} );
			setPaths( '' );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error.message || __( 'Failed to purge cache', 'quant' ),
			} );
		} finally {
			setLoading( false );
		}
	};

	const handlePurgeAll = async () => {
		if (
			// eslint-disable-next-line no-alert
			! window.confirm(
				__( 'Purge entire cache? This cannot be undone.', 'quant' )
			)
		) {
			return;
		}

		setLoading( true );
		setNotice( null );

		try {
			await apiFetch( {
				path: '/quant/v1/purge',
				method: 'POST',
				data: { paths: [ '/*' ] },
			} );
			setNotice( {
				status: 'success',
				message: __( 'Entire cache purged', 'quant' ),
			} );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error.message || __( 'Failed to purge cache', 'quant' ),
			} );
		} finally {
			setLoading( false );
		}
	};

	return (
		<div className="quant-cache">
			<h1>{ __( 'Cache Management', 'quant' ) }</h1>

			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
					isDismissible
				>
					{ notice.message }
				</Notice>
			) }

			<Card>
				<CardHeader>
					<h2 style={ { margin: 0 } }>
						{ __( 'Purge Cache', 'quant' ) }
					</h2>
				</CardHeader>
				<CardBody>
					<p style={ { color: '#666', marginTop: 0 } }>
						{ __(
							'Enter paths to purge from the CDN cache. One path per line.',
							'quant'
						) }
					</p>
					<TextareaControl
						label={ __( 'Paths to purge', 'quant' ) }
						value={ paths }
						onChange={ setPaths }
						placeholder={ '/blog/my-post\n/about\n/contact' }
						rows={ 8 }
						help={ __(
							'Use /* to purge all paths under a directory',
							'quant'
						) }
					/>
					<div
						style={ {
							display: 'flex',
							gap: '12px',
							marginTop: '16px',
						} }
					>
						<Button
							variant="primary"
							onClick={ handlePurge }
							disabled={ loading || ! paths.trim() }
							isBusy={ loading }
						>
							{ __( 'Purge Selected Paths', 'quant' ) }
						</Button>
						<Button
							variant="secondary"
							isDestructive
							onClick={ handlePurgeAll }
							disabled={ loading }
						>
							{ __( 'Purge All Cache', 'quant' ) }
						</Button>
					</div>
				</CardBody>
			</Card>
		</div>
	);
}
