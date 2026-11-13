import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { Button, Card, CardBody } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { shouldRetry, retryDelay } from './retry';
import './Progress.scss';

const DEFAULT_INTERVAL = 2000;

/**
 * Progress of one processing run.
 *
 * Polls the queue with a self-scheduling loop, so requests never overlap.
 * Progress and failures are counted for this run only, not for the whole
 * queue history. The card stays visible after the run until it is dismissed.
 *
 * @param {Object}   props              Component props.
 * @param {Function} props.onComplete   Called when the user dismisses the card.
 * @param {Function} props.onFinished   Called once when the run ends (refresh lists).
 * @param {number}   props.pollInterval Delay between polls in ms.
 * @param {boolean}  props.autoProcess  Process batches (true) or only watch the status.
 */
export function Progress( {
	onComplete,
	onFinished,
	pollInterval = DEFAULT_INTERVAL,
	autoProcess = false,
} ) {
	const [ status, setStatus ] = useState( null );
	const [ startPending, setStartPending ] = useState( null );
	const [ processedRun, setProcessedRun ] = useState( 0 );
	const [ failedRun, setFailedRun ] = useState( 0 );
	const [ runErrors, setRunErrors ] = useState( [] );
	const [ state, setState ] = useState( 'active' ); // active | done | stopped | error
	const [ error, setError ] = useState( null );
	const [ runId, setRunId ] = useState( 0 );
	const [ retrying, setRetrying ] = useState( false );

	const stopRef = useRef( false );
	const onCompleteRef = useRef( onComplete );
	const onFinishedRef = useRef( onFinished );
	useEffect( () => {
		onCompleteRef.current = onComplete;
		onFinishedRef.current = onFinished;
	}, [ onComplete, onFinished ] );

	const finish = useCallback( ( nextState ) => {
		setState( nextState );
		if ( onFinishedRef.current ) {
			onFinishedRef.current();
		}
	}, [] );

	useEffect( () => {
		let cancelled = false;
		let timer = null;
		let started = false;
		let failures = 0;

		const tick = async () => {
			if ( stopRef.current ) {
				return;
			}
			try {
				if ( autoProcess ) {
					const result = await apiFetch( {
						path: '/quant/v1/queue/process',
						method: 'POST',
						data: { limit: 5 },
					} );
					if ( cancelled ) {
						return;
					}
					setProcessedRun( ( n ) => n + ( result.processed || 0 ) );
					setFailedRun( ( n ) => n + ( result.failed || 0 ) );
					if ( result.errors && result.errors.length ) {
						setRunErrors( ( prev ) =>
							prev.concat( result.errors ).slice( -10 )
						);
					}
				}

				const response = await apiFetch( {
					path: '/quant/v1/queue/status',
				} );
				if ( cancelled || stopRef.current ) {
					return;
				}

				failures = 0;
				setRetrying( false );
				setStatus( response );
				if ( ! started ) {
					started = true;
					setStartPending(
						( response.pending || 0 ) + ( response.processing || 0 )
					);
				}

				if ( response.pending === 0 && response.processing === 0 ) {
					finish( 'done' );
					return;
				}

				timer = setTimeout( tick, pollInterval );
			} catch ( err ) {
				if ( cancelled || stopRef.current ) {
					return;
				}
				failures += 1;
				if ( shouldRetry( err, failures ) ) {
					setRetrying( true );
					timer = setTimeout( tick, retryDelay( failures ) );
					return;
				}
				setRetrying( false );
				setError(
					err.message || __( 'Failed to process the queue', 'quant' )
				);
				finish( 'error' );
			}
		};

		stopRef.current = false;
		setState( 'active' );
		setError( null );
		tick();

		return () => {
			cancelled = true;
			if ( timer ) {
				clearTimeout( timer );
			}
		};
	}, [ autoProcess, pollInterval, runId, finish ] );

	const handleStop = () => {
		stopRef.current = true;
		finish( 'stopped' );
	};

	const handleRetry = async () => {
		try {
			await apiFetch( { path: '/quant/v1/queue/retry', method: 'POST' } );
			setStatus( null );
			setStartPending( null );
			setProcessedRun( 0 );
			setFailedRun( 0 );
			setRunErrors( [] );
			setRunId( ( id ) => id + 1 );
		} catch ( err ) {
			setError( err.message || __( 'Failed to retry', 'quant' ) );
		}
	};

	const handleDismiss = () => {
		if ( onCompleteRef.current ) {
			onCompleteRef.current();
		}
	};

	const pending = status ? status.pending + status.processing : 0;
	const total = startPending || 0;
	const done = Math.max( 0, Math.min( total, total - pending ) );
	const percent =
		state === 'done' || total === 0
			? 100
			: Math.round( ( done / total ) * 100 );

	let barState = state;
	if ( state === 'done' && failedRun > 0 ) {
		barState = 'failed';
	} else if ( state === 'done' ) {
		barState = 'complete';
	}

	const titles = {
		active: __( 'Syncing content…', 'quant' ),
		complete: __( 'Sync complete', 'quant' ),
		failed: __( 'Sync completed with errors', 'quant' ),
		stopped: __( 'Stopped. Pending items stay in the queue.', 'quant' ),
		error: __( 'Processing stopped', 'quant' ),
	};

	return (
		<Card className="quant-progress">
			<CardBody>
				<div className="quant-progress__content">
					<div className="quant-progress__header">
						<h3 className="quant-progress__title">
							{ titles[ barState ] }
						</h3>
						{ state === 'active' && retrying && (
							<span className="quant-progress__processing">
								{ __(
									'The server did not answer. Trying again…',
									'quant'
								) }
							</span>
						) }
						{ state === 'active' && ! retrying && status && (
							<span className="quant-progress__processing">
								{ sprintf(
									/* translators: %d: number of pending items */
									__( '%d remaining', 'quant' ),
									pending
								) }
							</span>
						) }
					</div>

					<div
						className="quant-progress__bar-container"
						role="progressbar"
						aria-valuemin={ 0 }
						aria-valuemax={ 100 }
						aria-valuenow={ percent }
					>
						<div
							className={ `quant-progress__bar quant-progress__bar--${ barState }` }
							style={ { width: `${ percent }%` } }
						/>
					</div>

					<div className="quant-progress__stats">
						<span className="quant-progress__stat quant-progress__stat--completed">
							{ sprintf(
								/* translators: %d: number of items */
								__( '%d completed', 'quant' ),
								autoProcess ? processedRun : done
							) }
						</span>
						{ pending > 0 && (
							<span className="quant-progress__stat quant-progress__stat--pending">
								{ sprintf(
									/* translators: %d: number of items */
									__( '%d pending', 'quant' ),
									pending
								) }
							</span>
						) }
						{ failedRun > 0 && (
							<span className="quant-progress__stat quant-progress__stat--failed">
								{ sprintf(
									/* translators: %d: number of items */
									__( '%d failed', 'quant' ),
									failedRun
								) }
							</span>
						) }
					</div>

					{ error && (
						<p className="quant-progress__error">{ error }</p>
					) }

					{ runErrors.length > 0 && state !== 'active' && (
						<ul className="quant-progress__errors">
							{ runErrors.map( ( message, i ) => (
								<li key={ i }>{ message }</li>
							) ) }
						</ul>
					) }

					<div className="quant-progress__actions">
						{ state === 'active' && (
							<Button variant="secondary" onClick={ handleStop }>
								{ __( 'Stop', 'quant' ) }
							</Button>
						) }
						{ state !== 'active' && failedRun > 0 && (
							<Button variant="secondary" onClick={ handleRetry }>
								{ __( 'Retry Failed', 'quant' ) }
							</Button>
						) }
						{ state !== 'active' && (
							<Button variant="link" onClick={ handleDismiss }>
								{ __( 'Dismiss', 'quant' ) }
							</Button>
						) }
					</div>
				</div>
			</CardBody>
		</Card>
	);
}
