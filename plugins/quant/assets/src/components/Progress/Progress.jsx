import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { Button, Card, CardBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import './Progress.scss';

/**
 * Progress component - shows sync queue progress with polling
 *
 * @param {Object}   props
 * @param {Function} props.onComplete   - Called when processing completes
 * @param {number}   props.pollInterval - Polling interval in ms (default: 2000)
 * @param {boolean}  props.autoProcess  - Whether to auto-process pending items (default: false)
 */
export function Progress( {
	onComplete,
	pollInterval = 2000,
	autoProcess = false,
} ) {
	const [ status, setStatus ] = useState( {
		pending: 0,
		processing: 0,
		completed: 0,
		failed: 0,
		total: 0,
	} );
	const [ isActive, setIsActive ] = useState( true );
	const [ error, setError ] = useState( null );
	const pollRef = useRef( null );
	const initialTotalRef = useRef( null );

	// Fetch status and optionally process pending items
	const fetchStatus = useCallback( async () => {
		try {
			// Optionally process a batch of pending items
			if ( autoProcess ) {
				await apiFetch( {
					path: '/quant/v1/queue/process',
					method: 'POST',
					data: { limit: 5 },
				} );
			}

			// Fetch the current status
			const response = await apiFetch( {
				path: '/quant/v1/queue/status',
			} );

			setStatus( response );
			setError( null );

			// Store initial total for progress calculation
			if ( initialTotalRef.current === null && response.total > 0 ) {
				initialTotalRef.current = response.total;
			}

			// Check if complete (no more pending or processing)
			if ( response.pending === 0 && response.processing === 0 ) {
				setIsActive( false );
				if ( onComplete ) {
					onComplete();
				}
			}
		} catch ( err ) {
			setError(
				err.message || __( 'Failed to fetch queue status', 'quant' )
			);
		}
	}, [ onComplete, autoProcess ] );

	// Set up polling
	useEffect( () => {
		if ( isActive ) {
			// Initial fetch
			fetchStatus();

			// Start polling
			pollRef.current = setInterval( fetchStatus, pollInterval );

			return () => {
				if ( pollRef.current ) {
					clearInterval( pollRef.current );
				}
			};
		}
	}, [ isActive, fetchStatus, pollInterval ] );

	// Handle cancel
	const handleCancel = async () => {
		try {
			await apiFetch( {
				path: '/quant/v1/queue/clear',
				method: 'POST',
			} );

			setIsActive( false );
			if ( onComplete ) {
				onComplete();
			}
		} catch ( err ) {
			setError( err.message || __( 'Failed to cancel', 'quant' ) );
		}
	};

	// Handle retry failed
	const handleRetry = async () => {
		try {
			await apiFetch( {
				path: '/quant/v1/queue/retry',
				method: 'POST',
			} );

			setIsActive( true );
			initialTotalRef.current = null;
		} catch ( err ) {
			setError( err.message || __( 'Failed to retry', 'quant' ) );
		}
	};

	// Handle dismiss
	const handleDismiss = () => {
		setIsActive( false );
		if ( onComplete ) {
			onComplete();
		}
	};

	// Calculate progress percentage
	const calculateProgress = () => {
		const total = initialTotalRef.current || status.total;
		if ( total === 0 ) {
			return 0;
		}

		const done = status.completed + status.failed;
		return Math.round( ( done / total ) * 100 );
	};

	// Determine current state
	const getProgressState = () => {
		if ( error ) {
			return 'error';
		}
		if ( status.pending > 0 || status.processing > 0 ) {
			return 'active';
		}
		if ( status.failed > 0 && status.pending === 0 ) {
			return 'failed';
		}
		if ( status.completed > 0 ) {
			return 'complete';
		}
		return 'idle';
	};

	// Don't render if not active and no status to show
	if ( ! isActive && status.total === 0 ) {
		return null;
	}

	const progressState = getProgressState();
	const progressPercent = calculateProgress();

	return (
		<Card className="quant-progress">
			<CardBody>
				<div className="quant-progress__content">
					<div className="quant-progress__header">
						<h3 className="quant-progress__title">
							{ progressState === 'active' &&
								__( 'Syncing content…', 'quant' ) }
							{ progressState === 'complete' &&
								__( 'Sync complete', 'quant' ) }
							{ progressState === 'failed' &&
								__( 'Sync completed with errors', 'quant' ) }
							{ progressState === 'error' &&
								__( 'Error', 'quant' ) }
						</h3>
						{ progressState === 'active' && (
							<span className="quant-progress__processing">
								{ status.processing }{ ' ' }
								{ __( 'processing', 'quant' ) }
							</span>
						) }
					</div>

					<div className="quant-progress__bar-container">
						<div
							className={ `quant-progress__bar quant-progress__bar--${ progressState }` }
							style={ { width: `${ progressPercent }%` } }
						/>
					</div>

					<div className="quant-progress__stats">
						<span className="quant-progress__stat quant-progress__stat--completed">
							{ status.completed } { __( 'completed', 'quant' ) }
						</span>
						{ status.pending > 0 && (
							<span className="quant-progress__stat quant-progress__stat--pending">
								{ status.pending } { __( 'pending', 'quant' ) }
							</span>
						) }
						{ status.failed > 0 && (
							<span className="quant-progress__stat quant-progress__stat--failed">
								{ status.failed } { __( 'failed', 'quant' ) }
							</span>
						) }
					</div>

					{ error && (
						<p className="quant-progress__error">{ error }</p>
					) }

					<div className="quant-progress__actions">
						{ progressState === 'active' && (
							<Button
								variant="secondary"
								isDestructive
								size="small"
								onClick={ handleCancel }
							>
								{ __( 'Cancel', 'quant' ) }
							</Button>
						) }
						{ progressState === 'failed' && status.failed > 0 && (
							<Button
								variant="secondary"
								size="small"
								onClick={ handleRetry }
							>
								{ __( 'Retry Failed', 'quant' ) }
							</Button>
						) }
						{ ( progressState === 'complete' ||
							progressState === 'failed' ) && (
							<Button
								variant="link"
								size="small"
								onClick={ handleDismiss }
							>
								{ __( 'Dismiss', 'quant' ) }
							</Button>
						) }
					</div>
				</div>
			</CardBody>
		</Card>
	);
}
