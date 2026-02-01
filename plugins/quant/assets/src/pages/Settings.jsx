import { useState, useEffect } from '@wordpress/element';
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
    Spinner,
    Flex,
    FlexItem,
    Icon
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

export function Settings() {
    const [settings, setSettings] = useState({
        organization: '',
        project: '',
        token: '',
        endpoint: 'https://api.quantcdn.io',
        mode: 'static',
        // Sync settings
        auto_sync: true,
        auto_purge: true,
        // Static config
        webserver_url: '',
        webserver_host: '',
        route_404: '/404',
        custom_routes: [],
        domain_rewrites: []
    });
    const [customRoutesText, setCustomRoutesText] = useState('');
    const [domainRewritesText, setDomainRewritesText] = useState('');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [testing, setTesting] = useState(false);
    const [testingInternal, setTestingInternal] = useState(false);
    const [internalTestResult, setInternalTestResult] = useState(null);
    const [notice, setNotice] = useState(null);

    // OAuth state
    const [oauthStatus, setOauthStatus] = useState(null);
    const [oauthLoading, setOauthLoading] = useState(true);
    const [projects, setProjects] = useState([]);
    const [projectsLoading, setProjectsLoading] = useState(false);
    const [selectedOrg, setSelectedOrg] = useState('');
    const [selectedProject, setSelectedProject] = useState('');
    const [selectingProject, setSelectingProject] = useState(false);
    const [changingProject, setChangingProject] = useState(false);
    const [showManualCredentials, setShowManualCredentials] = useState(false);

    // Check for OAuth success from redirect
    useEffect(() => {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('oauth') === 'success') {
            setNotice({ status: 'success', message: __('Successfully connected to Quant! Now select a project below.', 'quant') });
            // Clean up URL
            const newUrl = window.location.pathname + '?page=quant';
            window.history.replaceState({}, '', newUrl);
        }
    }, []);

    useEffect(() => {
        Promise.all([
            apiFetch({ path: '/quant/v1/settings' }),
            apiFetch({ path: '/quant/v1/oauth/status' })
        ])
            .then(([settingsData, oauthData]) => {
                setSettings(settingsData);
                setOauthStatus(oauthData);

                // Convert arrays to text for textareas
                if (settingsData.custom_routes) {
                    setCustomRoutesText(Array.isArray(settingsData.custom_routes)
                        ? settingsData.custom_routes.join('\n')
                        : settingsData.custom_routes);
                }
                if (settingsData.domain_rewrites) {
                    setDomainRewritesText(Array.isArray(settingsData.domain_rewrites)
                        ? settingsData.domain_rewrites.join('\n')
                        : settingsData.domain_rewrites);
                }

                // If connected via OAuth but no project selected, fetch projects
                if (oauthData.connected && !oauthData.has_project) {
                    fetchProjects();
                }
            })
            .catch(() => {})
            .finally(() => {
                setLoading(false);
                setOauthLoading(false);
            });
    }, []);

    const fetchProjects = async () => {
        setProjectsLoading(true);
        try {
            const projectsData = await apiFetch({ path: '/quant/v1/oauth/projects' });
            setProjects(projectsData);
        } catch (error) {
            console.error('Failed to fetch projects:', error);
        } finally {
            setProjectsLoading(false);
        }
    };

    const handleOAuthConnect = async () => {
        try {
            const response = await apiFetch({ path: '/quant/v1/oauth/connect' });
            if (response.redirect_url) {
                window.location.href = response.redirect_url;
            }
        } catch (error) {
            setNotice({ status: 'error', message: error.message || __('Failed to initiate OAuth', 'quant') });
        }
    };

    const handleOAuthDisconnect = async () => {
        try {
            await apiFetch({ path: '/quant/v1/oauth/disconnect', method: 'POST' });
            setOauthStatus({ connected: false });
            setSettings(prev => ({ ...prev, organization: '', project: '', token: '' }));
            setProjects([]);
            setNotice({ status: 'success', message: __('Disconnected from Quant', 'quant') });
        } catch (error) {
            setNotice({ status: 'error', message: error.message || __('Failed to disconnect', 'quant') });
        }
    };

    const handleSelectProject = async () => {
        if (!selectedOrg || !selectedProject) {
            setNotice({ status: 'error', message: __('Please select an organization and project', 'quant') });
            return;
        }

        setSelectingProject(true);
        try {
            await apiFetch({
                path: '/quant/v1/oauth/select-project',
                method: 'POST',
                data: { organization: selectedOrg, project: selectedProject }
            });

            // Refresh status and settings
            const [settingsData, oauthData] = await Promise.all([
                apiFetch({ path: '/quant/v1/settings' }),
                apiFetch({ path: '/quant/v1/oauth/status' })
            ]);

            setSettings(settingsData);
            setOauthStatus(oauthData);
            setChangingProject(false);
            setNotice({ status: 'success', message: __('Project selected successfully!', 'quant') });
        } catch (error) {
            setNotice({ status: 'error', message: error.message || __('Failed to select project', 'quant') });
        } finally {
            setSelectingProject(false);
        }
    };

    const handleSave = async () => {
        setSaving(true);
        setNotice(null);

        try {
            // Convert textarea content to arrays
            const customRoutes = customRoutesText.split('\n').filter(r => r.trim());
            const domainRewrites = domainRewritesText.split('\n').filter(r => r.trim());

            await apiFetch({
                path: '/quant/v1/settings',
                method: 'POST',
                data: {
                    ...settings,
                    custom_routes: customRoutes,
                    domain_rewrites: domainRewrites
                }
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

    const handleTestInternal = async () => {
        setTestingInternal(true);
        setInternalTestResult(null);

        try {
            const response = await apiFetch({
                path: '/quant/v1/test-internal',
                method: 'POST'
            });
            setInternalTestResult(response);
        } catch (error) {
            setInternalTestResult({
                success: false,
                message: error.message || __('Test failed', 'quant')
            });
        } finally {
            setTestingInternal(false);
        }
    };

    const updateSetting = (key, value) => {
        setSettings(prev => ({ ...prev, [key]: value }));
    };

    if (loading || oauthLoading) {
        return (
            <div className="quant-settings">
                <h1>{__('Settings', 'quant')}</h1>
                <Spinner />
            </div>
        );
    }

    // Build project options for select
    const projectOptions = [{ label: __('Select a project...', 'quant'), value: '' }];
    const orgOptions = [{ label: __('Select an organization...', 'quant'), value: '' }];

    projects.forEach(orgData => {
        const orgValue = orgData.organization.machine_name || orgData.organization.name;
        orgOptions.push({
            label: orgData.organization.name,
            value: orgValue
        });
    });

    // Get projects for selected org
    const selectedOrgData = projects.find(
        o => (o.organization.machine_name || o.organization.name) === selectedOrg
    );
    if (selectedOrgData && selectedOrgData.projects) {
        selectedOrgData.projects.forEach(proj => {
            projectOptions.push({
                label: proj.name || proj.machine_name,
                value: proj.machine_name || proj.name
            });
        });
    }

    return (
        <div className="quant-settings">
            <h1>{__('Settings', 'quant')}</h1>

            {notice && (
                <Notice status={notice.status} onRemove={() => setNotice(null)} isDismissible>
                    {notice.message}
                </Notice>
            )}

            {/* OAuth Connection Card */}
            <Card style={{ marginBottom: '16px' }}>
                <CardHeader>
                    <h2 style={{ margin: 0 }}>{__('Connect to Quant', 'quant')}</h2>
                </CardHeader>
                <CardBody>
                    {!oauthStatus?.connected ? (
                        <>
                            <p style={{ color: '#666', marginTop: 0 }}>
                                {__('Connect your WordPress site to QuantCDN with one click. This is the recommended way to configure the plugin.', 'quant')}
                            </p>
                            <Flex>
                                <FlexItem>
                                    <Button
                                        variant="primary"
                                        onClick={handleOAuthConnect}
                                        icon="admin-users"
                                    >
                                        {__('Login to Quant', 'quant')}
                                    </Button>
                                </FlexItem>
                                <FlexItem>
                                    <Button
                                        variant="link"
                                        onClick={() => setShowManualCredentials(!showManualCredentials)}
                                    >
                                        {showManualCredentials
                                            ? __('Hide manual credentials', 'quant')
                                            : __('Use manual credentials instead', 'quant')
                                        }
                                    </Button>
                                </FlexItem>
                            </Flex>
                        </>
                    ) : oauthStatus.has_project && !changingProject ? (
                        <>
                            <Flex align="center" gap={4}>
                                <FlexItem>
                                    <span style={{
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        padding: '4px 12px',
                                        backgroundColor: '#d4edda',
                                        color: '#155724',
                                        borderRadius: '4px',
                                        fontSize: '14px'
                                    }}>
                                        <Icon icon="yes-alt" style={{ marginRight: '6px' }} />
                                        {__('Connected', 'quant')}
                                    </span>
                                </FlexItem>
                                {oauthStatus.user && (
                                    <FlexItem>
                                        <span style={{ color: '#666' }}>
                                            {oauthStatus.user.email}
                                        </span>
                                    </FlexItem>
                                )}
                            </Flex>
                            <div style={{ marginTop: '12px' }}>
                                <p style={{ margin: '8px 0', color: '#333' }}>
                                    <strong>{__('Organization:', 'quant')}</strong> {oauthStatus.organization}
                                </p>
                                <p style={{ margin: '8px 0', color: '#333' }}>
                                    <strong>{__('Project:', 'quant')}</strong> {oauthStatus.project}
                                </p>
                            </div>
                            <div style={{ marginTop: '16px', display: 'flex', gap: '8px' }}>
                                <Button
                                    variant="secondary"
                                    onClick={() => {
                                        setChangingProject(true);
                                        fetchProjects();
                                    }}
                                >
                                    {__('Change Project', 'quant')}
                                </Button>
                                <Button
                                    variant="secondary"
                                    onClick={handleOAuthDisconnect}
                                    isDestructive
                                >
                                    {__('Disconnect', 'quant')}
                                </Button>
                            </div>
                        </>
                    ) : (
                        <>
                            <p style={{ color: '#666', marginTop: 0 }}>
                                {changingProject
                                    ? __('Select a different project for this WordPress site.', 'quant')
                                    : __('Connected! Now select which project to use with this WordPress site.', 'quant')
                                }
                            </p>
                            {oauthStatus.user && (
                                <p style={{ color: '#666', margin: '8px 0' }}>
                                    {__('Logged in as:', 'quant')} <strong>{oauthStatus.user.email}</strong>
                                </p>
                            )}

                            {projectsLoading ? (
                                <Spinner />
                            ) : (
                                <>
                                    <SelectControl
                                        label={__('Organization', 'quant')}
                                        value={selectedOrg}
                                        options={orgOptions}
                                        onChange={(value) => {
                                            setSelectedOrg(value);
                                            setSelectedProject('');
                                        }}
                                    />

                                    {selectedOrg && (
                                        <SelectControl
                                            label={__('Project', 'quant')}
                                            value={selectedProject}
                                            options={projectOptions}
                                            onChange={setSelectedProject}
                                        />
                                    )}

                                    <Flex style={{ marginTop: '16px' }}>
                                        <FlexItem>
                                            <Button
                                                variant="primary"
                                                onClick={handleSelectProject}
                                                disabled={!selectedOrg || !selectedProject || selectingProject}
                                                isBusy={selectingProject}
                                            >
                                                {changingProject ? __('Switch to This Project', 'quant') : __('Use This Project', 'quant')}
                                            </Button>
                                        </FlexItem>
                                        <FlexItem>
                                            {changingProject ? (
                                                <Button
                                                    variant="secondary"
                                                    onClick={() => setChangingProject(false)}
                                                >
                                                    {__('Cancel', 'quant')}
                                                </Button>
                                            ) : (
                                                <Button
                                                    variant="secondary"
                                                    onClick={handleOAuthDisconnect}
                                                    isDestructive
                                                >
                                                    {__('Disconnect', 'quant')}
                                                </Button>
                                            )}
                                        </FlexItem>
                                    </Flex>
                                </>
                            )}
                        </>
                    )}
                </CardBody>
            </Card>

            {/* Manual Credentials Card - only show if not connected via OAuth or explicitly requested */}
            {(!oauthStatus?.connected || showManualCredentials) && (
                <Card>
                    <CardHeader>
                        <h2 style={{ margin: 0 }}>{__('Manual API Credentials', 'quant')}</h2>
                    </CardHeader>
                    <CardBody>
                        <p style={{ color: '#666', marginTop: 0 }}>
                            {__('Alternatively, enter your QuantCDN API credentials manually. You can find these in your', 'quant')}{' '}
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
                        <Flex style={{ marginTop: '16px' }} gap={4}>
                            <FlexItem>
                                <Button
                                    variant="primary"
                                    onClick={handleSave}
                                    disabled={saving}
                                    isBusy={saving}
                                >
                                    {saving ? __('Saving...', 'quant') : __('Save Credentials', 'quant')}
                                </Button>
                            </FlexItem>
                            <FlexItem>
                                <Button
                                    variant="secondary"
                                    onClick={handleTest}
                                    disabled={testing || !settings.organization || !settings.project}
                                    isBusy={testing}
                                >
                                    {__('Test Connection', 'quant')}
                                </Button>
                            </FlexItem>
                        </Flex>
                    </CardBody>
                </Card>
            )}

            <Card style={{ marginTop: '16px' }}>
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

            {/* Sync Settings */}
            <Card style={{ marginTop: '16px' }}>
                <CardHeader>
                    <h2 style={{ margin: 0 }}>{__('Sync Settings', 'quant')}</h2>
                </CardHeader>
                <CardBody>
                    <ToggleControl
                        label={__('Auto-sync on publish', 'quant')}
                        checked={settings.auto_sync}
                        onChange={(v) => updateSetting('auto_sync', v)}
                        help={settings.mode === 'static'
                            ? __('Automatically sync content to QuantCDN when published or updated', 'quant')
                            : __('This setting only applies in Static mode', 'quant')
                        }
                        disabled={settings.mode !== 'static'}
                    />
                    <ToggleControl
                        label={__('Auto-purge cache', 'quant')}
                        checked={settings.auto_purge}
                        onChange={(v) => updateSetting('auto_purge', v)}
                        help={__('Automatically purge CDN cache when content is updated', 'quant')}
                    />
                </CardBody>
            </Card>

            {/* Static Configuration - only show in static mode */}
            {settings.mode === 'static' && (
                <Card style={{ marginTop: '16px' }}>
                    <CardHeader>
                        <h2 style={{ margin: 0 }}>{__('Static Configuration', 'quant')}</h2>
                    </CardHeader>
                    <CardBody>
                        {/* Internal connectivity test */}
                        <div style={{ marginBottom: '16px', padding: '12px', backgroundColor: '#f0f0f0', borderRadius: '4px' }}>
                            <Flex align="center" gap={3}>
                                <FlexItem>
                                    <strong>{__('Internal Connectivity', 'quant')}</strong>
                                </FlexItem>
                                <FlexItem>
                                    {internalTestResult === null ? (
                                        <span style={{ color: '#757575' }}>{__('Not tested', 'quant')}</span>
                                    ) : internalTestResult.success ? (
                                        <span style={{ color: '#00a32a' }}>✓ {__('Working', 'quant')}</span>
                                    ) : (
                                        <span style={{ color: '#d63638' }}>✗ {internalTestResult.message}</span>
                                    )}
                                </FlexItem>
                                <FlexItem>
                                    <Button
                                        variant="secondary"
                                        size="small"
                                        onClick={handleTestInternal}
                                        disabled={testingInternal}
                                        isBusy={testingInternal}
                                    >
                                        {__('Test Connection', 'quant')}
                                    </Button>
                                </FlexItem>
                            </Flex>
                            {internalTestResult && (
                                <div style={{ marginTop: '8px', fontSize: '12px', color: '#757575' }}>
                                    {__('Testing:', 'quant')} {internalTestResult.adjusted_url || internalTestResult.original_url}
                                </div>
                            )}
                        </div>

                        <TextControl
                            label={__('Webserver URL', 'quant')}
                            value={settings.webserver_url}
                            onChange={(v) => updateSetting('webserver_url', v)}
                            placeholder="http://localhost"
                            help={__('Optional: Override the URL used for internal page rendering. Leave empty to use the site URL.', 'quant')}
                        />
                        <TextControl
                            label={__('Webserver Host', 'quant')}
                            value={settings.webserver_host}
                            onChange={(v) => updateSetting('webserver_host', v)}
                            placeholder="www.example.com"
                            help={__('Optional: Host header to send with internal requests. Use when the webserver expects a specific hostname.', 'quant')}
                        />
                        <TextControl
                            label={__('404 Route', 'quant')}
                            value={settings.route_404}
                            onChange={(v) => updateSetting('route_404', v)}
                            placeholder="/404"
                            help={__('The path to use for 404 error pages on QuantCDN', 'quant')}
                        />
                        <TextareaControl
                            label={__('Custom Routes', 'quant')}
                            value={customRoutesText}
                            onChange={setCustomRoutesText}
                            placeholder={'/robots.txt\n/custom-page'}
                            rows={4}
                            help={__('Additional routes to sync (one per line). Use for custom pages or files.', 'quant')}
                        />
                        <TextareaControl
                            label={__('Domain Rewrites', 'quant')}
                            value={domainRewritesText}
                            onChange={setDomainRewritesText}
                            placeholder="www.example.com"
                            rows={3}
                            help={__('Domains to rewrite as relative URLs (one per line). Useful for CDN or staging domains.', 'quant')}
                        />
                    </CardBody>
                </Card>
            )}

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
