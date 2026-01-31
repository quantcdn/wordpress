import { useState, useEffect } from '@wordpress/element';
import {
    Card,
    CardHeader,
    CardBody,
    Button,
    Notice,
    Spinner
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

export function Diagnostics() {
    const [diagnostics, setDiagnostics] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [downloading, setDownloading] = useState(false);

    useEffect(() => {
        loadDiagnostics();
    }, []);

    const loadDiagnostics = async () => {
        setLoading(true);
        setError(null);
        try {
            const response = await apiFetch({ path: '/quant/v1/diagnostics' });
            setDiagnostics(response);
        } catch (err) {
            setError(err.message || __('Failed to load diagnostics', 'quant'));
        } finally {
            setLoading(false);
        }
    };

    const downloadReport = () => {
        if (!diagnostics) return;

        setDownloading(true);

        const report = generateReport(diagnostics);
        const blob = new Blob([report], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `quant-diagnostics-${new Date().toISOString().split('T')[0]}.txt`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);

        setDownloading(false);
    };

    const generateReport = (data) => {
        const lines = [
            '='.repeat(60),
            'QuantCDN Diagnostic Report',
            `Generated: ${new Date().toISOString()}`,
            '='.repeat(60),
            '',
            '## Environment',
            `PHP Version: ${data.environment?.php_version || 'Unknown'}`,
            `WordPress Version: ${data.environment?.wordpress_version || 'Unknown'}`,
            `Plugin Version: ${data.environment?.plugin_version || 'Unknown'}`,
            `PHP Memory Limit: ${data.environment?.php_memory_limit || 'Unknown'}`,
            `PHP Max Execution Time: ${data.environment?.php_max_execution_time || 'Unknown'}`,
            `Multisite: ${data.environment?.is_multisite ? 'Yes' : 'No'}`,
            '',
            '## Plugin Configuration',
            `Mode: ${data.plugin?.mode || 'Unknown'}`,
            `Has Credentials: ${data.plugin?.has_credentials ? 'Yes' : 'No'}`,
            `Organization: ${data.plugin?.organization || 'Not set'}`,
            `Project: ${data.plugin?.project || 'Not set'}`,
            `Endpoint: ${data.plugin?.endpoint || 'Not set'}`,
            '',
            '## API Connection',
            `Connected: ${data.api?.connected ? 'Yes' : 'No'}`,
            data.api?.error ? `Error: ${data.api.error}` : '',
            '',
            '## Database',
            `V2 Table Exists: ${data.database?.v2_table_exists ? 'Yes' : 'No'}`,
            `V1 Table Exists: ${data.database?.v1_table_exists ? 'Yes' : 'No'}`,
            '',
            '## Queue Status',
            `Pending: ${data.queue?.pending ?? 'Unknown'}`,
            `Processing: ${data.queue?.processing ?? 'Unknown'}`,
            `Failed: ${data.queue?.failed ?? 'Unknown'}`,
            `Total: ${data.queue?.total ?? 'Unknown'}`,
            '',
            '## Migration Status',
            `Needs Migration: ${data.migration?.needs_migration ? 'Yes' : 'No'}`,
            `Migration Complete: ${data.migration?.migration_complete ? 'Yes' : 'No'}`,
            data.migration?.migration_date ? `Migration Date: ${data.migration.migration_date}` : '',
            '',
        ];

        if (data.recent_errors && data.recent_errors.length > 0) {
            lines.push('## Recent Errors');
            data.recent_errors.forEach((err, i) => {
                lines.push(`${i + 1}. [${err.type}:${err.object_id}] ${err.error_message}`);
                lines.push(`   At: ${err.processed_at}`);
            });
            lines.push('');
        }

        lines.push('='.repeat(60));
        lines.push('End of Report');

        return lines.filter(l => l !== null).join('\n');
    };

    const StatusBadge = ({ success, successText, failText }) => (
        <span
            style={{
                display: 'inline-block',
                padding: '2px 8px',
                borderRadius: '3px',
                fontSize: '12px',
                fontWeight: '500',
                backgroundColor: success ? '#d4edda' : '#f8d7da',
                color: success ? '#155724' : '#721c24',
            }}
        >
            {success ? successText : failText}
        </span>
    );

    if (loading) {
        return (
            <div className="quant-diagnostics">
                <h1>{__('Diagnostics', 'quant')}</h1>
                <Card>
                    <CardBody>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                            <Spinner />
                            <span>{__('Loading diagnostic information...', 'quant')}</span>
                        </div>
                    </CardBody>
                </Card>
            </div>
        );
    }

    if (error) {
        return (
            <div className="quant-diagnostics">
                <h1>{__('Diagnostics', 'quant')}</h1>
                <Notice status="error" isDismissible={false}>
                    {error}
                </Notice>
                <div style={{ marginTop: '16px' }}>
                    <Button variant="secondary" onClick={loadDiagnostics}>
                        {__('Retry', 'quant')}
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="quant-diagnostics">
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' }}>
                <h1 style={{ margin: 0 }}>{__('Diagnostics', 'quant')}</h1>
                <div style={{ display: 'flex', gap: '10px' }}>
                    <Button variant="secondary" onClick={loadDiagnostics}>
                        {__('Refresh', 'quant')}
                    </Button>
                    <Button
                        variant="primary"
                        onClick={downloadReport}
                        disabled={downloading}
                        isBusy={downloading}
                    >
                        {downloading ? __('Downloading...', 'quant') : __('Download Report', 'quant')}
                    </Button>
                </div>
            </div>

            {/* Environment */}
            <Card style={{ marginBottom: '16px' }}>
                <CardHeader>
                    <h2 style={{ margin: 0 }}>{__('Environment', 'quant')}</h2>
                </CardHeader>
                <CardBody>
                    <table className="widefat" style={{ margin: 0 }}>
                        <tbody>
                            <tr>
                                <th style={{ textAlign: 'left', padding: '8px', width: '200px' }}>{__('PHP Version', 'quant')}</th>
                                <td style={{ padding: '8px' }}>{diagnostics?.environment?.php_version || '-'}</td>
                            </tr>
                            <tr>
                                <th style={{ textAlign: 'left', padding: '8px' }}>{__('WordPress Version', 'quant')}</th>
                                <td style={{ padding: '8px' }}>{diagnostics?.environment?.wordpress_version || '-'}</td>
                            </tr>
                            <tr>
                                <th style={{ textAlign: 'left', padding: '8px' }}>{__('Plugin Version', 'quant')}</th>
                                <td style={{ padding: '8px' }}>{diagnostics?.environment?.plugin_version || '-'}</td>
                            </tr>
                            <tr>
                                <th style={{ textAlign: 'left', padding: '8px' }}>{__('PHP Memory Limit', 'quant')}</th>
                                <td style={{ padding: '8px' }}>{diagnostics?.environment?.php_memory_limit || '-'}</td>
                            </tr>
                            <tr>
                                <th style={{ textAlign: 'left', padding: '8px' }}>{__('PHP Max Execution Time', 'quant')}</th>
                                <td style={{ padding: '8px' }}>{diagnostics?.environment?.php_max_execution_time || '-'} seconds</td>
                            </tr>
                            <tr>
                                <th style={{ textAlign: 'left', padding: '8px' }}>{__('Multisite', 'quant')}</th>
                                <td style={{ padding: '8px' }}>{diagnostics?.environment?.is_multisite ? __('Yes', 'quant') : __('No', 'quant')}</td>
                            </tr>
                        </tbody>
                    </table>
                </CardBody>
            </Card>

            {/* Plugin Configuration */}
            <Card style={{ marginBottom: '16px' }}>
                <CardHeader>
                    <h2 style={{ margin: 0 }}>{__('Plugin Configuration', 'quant')}</h2>
                </CardHeader>
                <CardBody>
                    <table className="widefat" style={{ margin: 0 }}>
                        <tbody>
                            <tr>
                                <th style={{ textAlign: 'left', padding: '8px', width: '200px' }}>{__('Mode', 'quant')}</th>
                                <td style={{ padding: '8px' }}>
                                    {diagnostics?.plugin?.mode === 'static' ? __('Static Site', 'quant') : __('CDN Only', 'quant')}
                                </td>
                            </tr>
                            <tr>
                                <th style={{ textAlign: 'left', padding: '8px' }}>{__('Credentials', 'quant')}</th>
                                <td style={{ padding: '8px' }}>
                                    <StatusBadge
                                        success={diagnostics?.plugin?.has_credentials}
                                        successText={__('Configured', 'quant')}
                                        failText={__('Not Configured', 'quant')}
                                    />
                                </td>
                            </tr>
                            <tr>
                                <th style={{ textAlign: 'left', padding: '8px' }}>{__('Organization', 'quant')}</th>
                                <td style={{ padding: '8px' }}>{diagnostics?.plugin?.organization || '-'}</td>
                            </tr>
                            <tr>
                                <th style={{ textAlign: 'left', padding: '8px' }}>{__('Project', 'quant')}</th>
                                <td style={{ padding: '8px' }}>{diagnostics?.plugin?.project || '-'}</td>
                            </tr>
                            <tr>
                                <th style={{ textAlign: 'left', padding: '8px' }}>{__('Endpoint', 'quant')}</th>
                                <td style={{ padding: '8px' }}>{diagnostics?.plugin?.endpoint || '-'}</td>
                            </tr>
                        </tbody>
                    </table>
                </CardBody>
            </Card>

            {/* API Connection */}
            <Card style={{ marginBottom: '16px' }}>
                <CardHeader>
                    <h2 style={{ margin: 0 }}>{__('API Connection', 'quant')}</h2>
                </CardHeader>
                <CardBody>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                        <StatusBadge
                            success={diagnostics?.api?.connected}
                            successText={__('Connected', 'quant')}
                            failText={__('Not Connected', 'quant')}
                        />
                        {diagnostics?.api?.error && (
                            <span style={{ color: '#721c24' }}>{diagnostics.api.error}</span>
                        )}
                    </div>
                </CardBody>
            </Card>

            {/* Database */}
            <Card style={{ marginBottom: '16px' }}>
                <CardHeader>
                    <h2 style={{ margin: 0 }}>{__('Database', 'quant')}</h2>
                </CardHeader>
                <CardBody>
                    <table className="widefat" style={{ margin: 0 }}>
                        <tbody>
                            <tr>
                                <th style={{ textAlign: 'left', padding: '8px', width: '200px' }}>{__('V2 Sync Queue Table', 'quant')}</th>
                                <td style={{ padding: '8px' }}>
                                    <StatusBadge
                                        success={diagnostics?.database?.v2_table_exists}
                                        successText={__('Exists', 'quant')}
                                        failText={__('Missing', 'quant')}
                                    />
                                </td>
                            </tr>
                            <tr>
                                <th style={{ textAlign: 'left', padding: '8px' }}>{__('V1 Queue Table', 'quant')}</th>
                                <td style={{ padding: '8px' }}>
                                    {diagnostics?.database?.v1_table_exists
                                        ? <span style={{ color: '#856404' }}>{__('Exists (can be cleaned up)', 'quant')}</span>
                                        : <span style={{ color: '#666' }}>{__('Not present', 'quant')}</span>
                                    }
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardBody>
            </Card>

            {/* Queue Status */}
            <Card style={{ marginBottom: '16px' }}>
                <CardHeader>
                    <h2 style={{ margin: 0 }}>{__('Queue Status', 'quant')}</h2>
                </CardHeader>
                <CardBody>
                    {diagnostics?.queue?.error ? (
                        <Notice status="error" isDismissible={false}>
                            {diagnostics.queue.error}
                        </Notice>
                    ) : (
                        <div style={{ display: 'flex', gap: '30px' }}>
                            <div style={{ textAlign: 'center' }}>
                                <div style={{ fontSize: '24px', fontWeight: 'bold', color: '#0073aa' }}>
                                    {diagnostics?.queue?.pending ?? 0}
                                </div>
                                <div style={{ fontSize: '12px', color: '#666' }}>{__('Pending', 'quant')}</div>
                            </div>
                            <div style={{ textAlign: 'center' }}>
                                <div style={{ fontSize: '24px', fontWeight: 'bold', color: '#f0b849' }}>
                                    {diagnostics?.queue?.processing ?? 0}
                                </div>
                                <div style={{ fontSize: '12px', color: '#666' }}>{__('Processing', 'quant')}</div>
                            </div>
                            <div style={{ textAlign: 'center' }}>
                                <div style={{ fontSize: '24px', fontWeight: 'bold', color: '#d63638' }}>
                                    {diagnostics?.queue?.failed ?? 0}
                                </div>
                                <div style={{ fontSize: '12px', color: '#666' }}>{__('Failed', 'quant')}</div>
                            </div>
                            <div style={{ textAlign: 'center' }}>
                                <div style={{ fontSize: '24px', fontWeight: 'bold', color: '#666' }}>
                                    {diagnostics?.queue?.total ?? 0}
                                </div>
                                <div style={{ fontSize: '12px', color: '#666' }}>{__('Total', 'quant')}</div>
                            </div>
                        </div>
                    )}
                </CardBody>
            </Card>

            {/* Migration Status */}
            <Card style={{ marginBottom: '16px' }}>
                <CardHeader>
                    <h2 style={{ margin: 0 }}>{__('Migration Status', 'quant')}</h2>
                </CardHeader>
                <CardBody>
                    <table className="widefat" style={{ margin: 0 }}>
                        <tbody>
                            <tr>
                                <th style={{ textAlign: 'left', padding: '8px', width: '200px' }}>{__('Needs Migration', 'quant')}</th>
                                <td style={{ padding: '8px' }}>
                                    {diagnostics?.migration?.needs_migration
                                        ? <a href="#/migration">{__('Yes - Click to migrate', 'quant')}</a>
                                        : __('No', 'quant')
                                    }
                                </td>
                            </tr>
                            <tr>
                                <th style={{ textAlign: 'left', padding: '8px' }}>{__('Migration Complete', 'quant')}</th>
                                <td style={{ padding: '8px' }}>
                                    {diagnostics?.migration?.migration_complete ? __('Yes', 'quant') : __('No', 'quant')}
                                </td>
                            </tr>
                            {diagnostics?.migration?.migration_date && (
                                <tr>
                                    <th style={{ textAlign: 'left', padding: '8px' }}>{__('Migration Date', 'quant')}</th>
                                    <td style={{ padding: '8px' }}>{diagnostics.migration.migration_date}</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </CardBody>
            </Card>

            {/* Recent Errors */}
            {diagnostics?.recent_errors && diagnostics.recent_errors.length > 0 && (
                <Card style={{ marginBottom: '16px' }}>
                    <CardHeader>
                        <h2 style={{ margin: 0 }}>{__('Recent Errors', 'quant')}</h2>
                    </CardHeader>
                    <CardBody>
                        <table className="widefat" style={{ margin: 0 }}>
                            <thead>
                                <tr>
                                    <th style={{ textAlign: 'left', padding: '8px' }}>{__('Type', 'quant')}</th>
                                    <th style={{ textAlign: 'left', padding: '8px' }}>{__('ID', 'quant')}</th>
                                    <th style={{ textAlign: 'left', padding: '8px' }}>{__('Error', 'quant')}</th>
                                    <th style={{ textAlign: 'left', padding: '8px' }}>{__('Time', 'quant')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {diagnostics.recent_errors.map((err, index) => (
                                    <tr key={index}>
                                        <td style={{ padding: '8px' }}>{err.type}</td>
                                        <td style={{ padding: '8px' }}>{err.object_id}</td>
                                        <td style={{ padding: '8px', color: '#721c24' }}>{err.error_message}</td>
                                        <td style={{ padding: '8px', whiteSpace: 'nowrap' }}>{err.processed_at}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </CardBody>
                </Card>
            )}
        </div>
    );
}
