import { Card, CardHeader, CardBody, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export function Dashboard() {
    const [status, setStatus] = useState({ loading: true, connected: false, mode: 'static' });

    useEffect(() => {
        apiFetch({ path: '/quant/v1/status' })
            .then((data) => setStatus({ loading: false, ...data }))
            .catch(() => setStatus({ loading: false, connected: false, mode: 'static' }));
    }, []);

    return (
        <div className="quant-dashboard">
            <h1>{__('Dashboard', 'quant')}</h1>

            <div className="quant-dashboard__cards">
                <Card>
                    <CardHeader>
                        <h2 style={{ margin: 0 }}>{__('Connection Status', 'quant')}</h2>
                    </CardHeader>
                    <CardBody>
                        {status.loading ? (
                            <p><Spinner /> {__('Checking connection...', 'quant')}</p>
                        ) : status.connected ? (
                            <p className="quant-status--success">
                                {__('Connected to QuantCDN', 'quant')}
                            </p>
                        ) : (
                            <p className="quant-status--error">
                                {__('Not connected. Check your settings.', 'quant')}
                            </p>
                        )}
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader>
                        <h2 style={{ margin: 0 }}>{__('Plugin Mode', 'quant')}</h2>
                    </CardHeader>
                    <CardBody>
                        {status.loading ? (
                            <Spinner />
                        ) : (
                            <p>
                                <strong>
                                    {status.mode === 'static'
                                        ? __('Static Site', 'quant')
                                        : __('CDN Only', 'quant')
                                    }
                                </strong>
                                <br />
                                <span style={{ color: '#666', fontSize: '13px' }}>
                                    {status.mode === 'static'
                                        ? __('Full content sync to QuantCDN edge', 'quant')
                                        : __('Cache purging and CDN management', 'quant')
                                    }
                                </span>
                            </p>
                        )}
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader>
                        <h2 style={{ margin: 0 }}>{__('Quick Actions', 'quant')}</h2>
                    </CardHeader>
                    <CardBody>
                        <p>
                            <a href="#/cache">{__('Purge Cache', 'quant')}</a>
                            <br />
                            <a href="#/settings">{__('Configure Settings', 'quant')}</a>
                        </p>
                    </CardBody>
                </Card>
            </div>
        </div>
    );
}
