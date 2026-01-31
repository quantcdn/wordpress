import { useState, useEffect } from '@wordpress/element';
import {
    Card,
    CardHeader,
    CardBody,
    TextControl,
    Button,
    Notice,
    SelectControl,
    Spinner
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

export function Settings() {
    const [settings, setSettings] = useState({
        organization: '',
        project: '',
        token: '',
        endpoint: 'https://api.quantcdn.io',
        mode: 'static'
    });
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [testing, setTesting] = useState(false);
    const [notice, setNotice] = useState(null);

    useEffect(() => {
        apiFetch({ path: '/quant/v1/settings' })
            .then(setSettings)
            .catch(() => {})
            .finally(() => setLoading(false));
    }, []);

    const handleSave = async () => {
        setSaving(true);
        setNotice(null);

        try {
            await apiFetch({
                path: '/quant/v1/settings',
                method: 'POST',
                data: settings
            });
            setNotice({ status: 'success', message: __('Settings saved successfully', 'quant') });
            // Clear token from state after save (it's stored server-side)
            setSettings(prev => ({ ...prev, token: '' }));
        } catch (error) {
            setNotice({ status: 'error', message: error.message || __('Failed to save settings', 'quant') });
        } finally {
            setSaving(false);
        }
    };

    const handleTest = async () => {
        setTesting(true);
        setNotice(null);

        try {
            const response = await apiFetch({ path: '/quant/v1/status' });
            if (response.connected) {
                setNotice({ status: 'success', message: __('Connection successful!', 'quant') });
            } else {
                setNotice({ status: 'error', message: __('Connection failed. Check your credentials.', 'quant') });
            }
        } catch (error) {
            setNotice({ status: 'error', message: error.message || __('Connection test failed', 'quant') });
        } finally {
            setTesting(false);
        }
    };

    const updateSetting = (key, value) => {
        setSettings(prev => ({ ...prev, [key]: value }));
    };

    if (loading) {
        return (
            <div className="quant-settings">
                <h1>{__('Settings', 'quant')}</h1>
                <Spinner />
            </div>
        );
    }

    return (
        <div className="quant-settings">
            <h1>{__('Settings', 'quant')}</h1>

            {notice && (
                <Notice status={notice.status} onRemove={() => setNotice(null)} isDismissible>
                    {notice.message}
                </Notice>
            )}

            <Card>
                <CardHeader>
                    <h2 style={{ margin: 0 }}>{__('API Credentials', 'quant')}</h2>
                </CardHeader>
                <CardBody>
                    <p style={{ color: '#666', marginTop: 0 }}>
                        {__('Enter your QuantCDN API credentials. You can find these in your', 'quant')}{' '}
                        <a href="https://dashboard.quantcdn.io" target="_blank" rel="noopener noreferrer">
                            {__('QuantCDN Dashboard', 'quant')}
                        </a>.
                    </p>
                    <TextControl
                        label={__('Organization', 'quant')}
                        value={settings.organization}
                        onChange={(v) => updateSetting('organization', v)}
                        help={__('Your QuantCDN organization identifier', 'quant')}
                    />
                    <TextControl
                        label={__('Project', 'quant')}
                        value={settings.project}
                        onChange={(v) => updateSetting('project', v)}
                        help={__('Your QuantCDN project name', 'quant')}
                    />
                    <TextControl
                        label={__('API Token', 'quant')}
                        type="password"
                        value={settings.token}
                        onChange={(v) => updateSetting('token', v)}
                        help={__('Leave blank to keep existing token', 'quant')}
                        placeholder={__('Enter new token or leave blank', 'quant')}
                    />
                    <TextControl
                        label={__('API Endpoint', 'quant')}
                        value={settings.endpoint}
                        onChange={(v) => updateSetting('endpoint', v)}
                        help={__('Default: https://api.quantcdn.io', 'quant')}
                    />
                    <div style={{ marginTop: '16px' }}>
                        <Button
                            variant="secondary"
                            onClick={handleTest}
                            disabled={testing || !settings.organization || !settings.project}
                            isBusy={testing}
                        >
                            {__('Test Connection', 'quant')}
                        </Button>
                    </div>
                </CardBody>
            </Card>

            <Card>
                <CardHeader>
                    <h2 style={{ margin: 0 }}>{__('Plugin Mode', 'quant')}</h2>
                </CardHeader>
                <CardBody>
                    <SelectControl
                        label={__('Mode', 'quant')}
                        value={settings.mode}
                        options={[
                            { label: __('Static Site', 'quant'), value: 'static' },
                            { label: __('CDN Only', 'quant'), value: 'cdn_only' }
                        ]}
                        onChange={(v) => updateSetting('mode', v)}
                        help={settings.mode === 'static'
                            ? __('Full content sync to QuantCDN edge - your entire site is served statically', 'quant')
                            : __('Cache purging and CDN management only - WordPress serves dynamic content', 'quant')
                        }
                    />
                </CardBody>
            </Card>

            <div style={{ marginTop: '16px' }}>
                <Button
                    variant="primary"
                    onClick={handleSave}
                    disabled={saving}
                    isBusy={saving}
                >
                    {saving ? __('Saving...', 'quant') : __('Save Settings', 'quant')}
                </Button>
            </div>
        </div>
    );
}
