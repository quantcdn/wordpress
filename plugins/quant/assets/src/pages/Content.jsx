import { useState, useEffect, useCallback } from '@wordpress/element';
import {
    Card,
    CardHeader,
    CardBody,
    TabPanel,
    Button,
    Notice,
    Spinner
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { ContentBrowser } from '../components/ContentBrowser/ContentBrowser';
import { Progress } from '../components/Progress/Progress';

export function Content() {
    const [status, setStatus] = useState({ loading: true, mode: 'static', connected: false });
    const [contentTypes, setContentTypes] = useState([]);
    const [selectedItems, setSelectedItems] = useState([]);
    const [notice, setNotice] = useState(null);
    const [isSeeding, setIsSeeding] = useState(false);
    const [isSyncing, setIsSyncing] = useState(false);
    const [isProcessing, setIsProcessing] = useState(false);
    const [showProgress, setShowProgress] = useState(false);
    const [activeTab, setActiveTab] = useState('post');

    // Load status and content types on mount
    useEffect(() => {
        Promise.all([
            apiFetch({ path: '/quant/v1/status' }),
            apiFetch({ path: '/quant/v1/content-types' })
        ])
            .then(([statusData, typesData]) => {
                setStatus({ loading: false, ...statusData });
                setContentTypes(typesData);
            })
            .catch(() => {
                setStatus({ loading: false, mode: 'cdn_only', connected: false });
            });
    }, []);

    // Handle seed all - includes all content types
    const handleSeedAll = async () => {
        if (!confirm(__('This will queue ALL content for syncing to QuantCDN (posts, pages, CPTs, taxonomies, media, archives, home, theme assets). Continue?', 'quant'))) {
            return;
        }

        setIsSeeding(true);
        setNotice(null);

        try {
            // Get all CPT names to include them
            const cptTypes = contentTypes
                .filter(t => t.type === 'cpt')
                .map(t => t.subtype);

            const types = [
                'post', 'page', 'media', 'taxonomy',
                'home', 'archives', 'theme_assets',
                ...cptTypes
            ];

            const response = await apiFetch({
                path: '/quant/v1/seed',
                method: 'POST',
                data: { types }
            });

            setNotice({
                status: 'success',
                message: __(`Queued ${response.queued} items for sync.`, 'quant')
            });
            setShowProgress(true);
        } catch (error) {
            setNotice({
                status: 'error',
                message: error.message || __('Failed to seed content', 'quant')
            });
        } finally {
            setIsSeeding(false);
        }
    };

    // Handle sync selected
    const handleSyncSelected = async () => {
        if (selectedItems.length === 0) {
            setNotice({
                status: 'warning',
                message: __('No items selected', 'quant')
            });
            return;
        }

        setIsSyncing(true);
        setNotice(null);

        try {
            const response = await apiFetch({
                path: '/quant/v1/sync',
                method: 'POST',
                data: {
                    items: selectedItems,
                    action: 'sync'
                }
            });

            setNotice({
                status: 'success',
                message: __(`Queued ${response.queued} items for sync.`, 'quant')
            });
            setSelectedItems([]);
            setShowProgress(true);
        } catch (error) {
            setNotice({
                status: 'error',
                message: error.message || __('Failed to sync items', 'quant')
            });
        } finally {
            setIsSyncing(false);
        }
    };

    // Handle process queue manually
    const handleProcessQueue = async () => {
        setIsProcessing(true);
        setNotice(null);

        try {
            const response = await apiFetch({
                path: '/quant/v1/queue/process',
                method: 'POST',
                data: { limit: 10 }
            });

            // Build informative message
            let message = '';
            let status = 'info';

            if (response.processed === 0 && response.failed === 0) {
                message = __('No pending items in queue. Use "Seed All Content" or select items to sync.', 'quant');
            } else if (response.failed > 0 && response.processed > 0) {
                message = __(`Processed ${response.processed} items, ${response.failed} failed.`, 'quant');
                status = 'warning';
            } else if (response.failed > 0) {
                message = __(`${response.failed} items failed to process.`, 'quant');
                status = 'error';
            } else {
                message = __(`Processed ${response.processed} items successfully.`, 'quant');
                status = 'success';
            }

            if (response.remaining > 0) {
                message += ` ${response.remaining} remaining.`;
            }

            // Add error details if any
            if (response.errors && response.errors.length > 0) {
                message += '\n\nErrors:\n' + response.errors.join('\n');
            }

            setNotice({ status, message });

            if (response.processed > 0 || response.remaining > 0) {
                setShowProgress(true);
            }
        } catch (error) {
            setNotice({
                status: 'error',
                message: error.message || __('Failed to process queue', 'quant')
            });
        } finally {
            setIsProcessing(false);
        }
    };

    // Handle selection change from ContentBrowser
    const handleSelectionChange = useCallback((items) => {
        setSelectedItems(items);
    }, []);

    // Handle progress complete
    const handleProgressComplete = () => {
        setShowProgress(false);
    };

    // Build tabs from content types
    const buildTabs = () => {
        const tabs = [];

        // Add standard tabs
        const postType = contentTypes.find(t => t.type === 'post');
        if (postType) {
            tabs.push({
                name: 'post',
                title: `${postType.label} (${postType.count})`,
                contentType: postType
            });
        }

        const pageType = contentTypes.find(t => t.type === 'page');
        if (pageType) {
            tabs.push({
                name: 'page',
                title: `${pageType.label} (${pageType.count})`,
                contentType: pageType
            });
        }

        // Add custom post types
        const cpts = contentTypes.filter(t => t.type === 'cpt');
        cpts.forEach(cpt => {
            tabs.push({
                name: `cpt_${cpt.subtype}`,
                title: `${cpt.label} (${cpt.count})`,
                contentType: cpt
            });
        });

        // Add taxonomies tab
        const taxonomies = contentTypes.filter(t => t.type === 'taxonomy');
        if (taxonomies.length > 0) {
            const totalTaxCount = taxonomies.reduce((sum, t) => sum + t.count, 0);
            tabs.push({
                name: 'taxonomies',
                title: `${__('Taxonomies', 'quant')} (${totalTaxCount})`,
                contentTypes: taxonomies
            });
        }

        // Add media tab
        const media = contentTypes.find(t => t.type === 'media');
        if (media) {
            tabs.push({
                name: 'media',
                title: `${media.label} (${media.count})`,
                contentType: media
            });
        }

        return tabs;
    };

    // Loading state
    if (status.loading) {
        return (
            <div className="quant-content">
                <h1>{__('Content', 'quant')}</h1>
                <Spinner />
            </div>
        );
    }

    // Not connected - show warning
    if (!status.connected) {
        return (
            <div className="quant-content">
                <h1>{__('Content', 'quant')}</h1>
                <Notice status="warning" isDismissible={false}>
                    {__('Not connected to QuantCDN. Please configure your API credentials in Settings before syncing content.', 'quant')}
                </Notice>
            </div>
        );
    }

    // CDN-only mode - show message
    if (status.mode !== 'static') {
        return (
            <div className="quant-content">
                <h1>{__('Content', 'quant')}</h1>
                <Notice status="info" isDismissible={false}>
                    {__('Content sync is only available in Static mode. Change your mode in Settings to enable content syncing.', 'quant')}
                </Notice>
            </div>
        );
    }

    const tabs = buildTabs();

    return (
        <div className="quant-content">
            <h1>{__('Content', 'quant')}</h1>

            {notice && (
                <Notice
                    status={notice.status}
                    onRemove={() => setNotice(null)}
                    isDismissible
                >
                    {notice.message}
                </Notice>
            )}

            {showProgress && (
                <Progress onComplete={handleProgressComplete} />
            )}

            <Card className="quant-content__header-card">
                <CardBody>
                    <div className="quant-content__actions">
                        <div className="quant-content__mode-indicator">
                            <span className="quant-content__mode-badge quant-content__mode-badge--static">
                                {__('Static Mode', 'quant')}
                            </span>
                            <span className="quant-content__mode-description">
                                {__('Content is synced to QuantCDN edge servers.', 'quant')}
                            </span>
                        </div>
                        <div className="quant-content__batch-actions">
                            <Button
                                variant="primary"
                                onClick={handleSeedAll}
                                disabled={isSeeding || showProgress}
                                isBusy={isSeeding}
                            >
                                {__('Seed All Content', 'quant')}
                            </Button>
                            <Button
                                variant="secondary"
                                onClick={handleSyncSelected}
                                disabled={isSyncing || selectedItems.length === 0 || showProgress}
                                isBusy={isSyncing}
                            >
                                {__('Sync Selected', 'quant')}
                                {selectedItems.length > 0 && ` (${selectedItems.length})`}
                            </Button>
                            <Button
                                variant="tertiary"
                                onClick={handleProcessQueue}
                                disabled={isProcessing || showProgress}
                                isBusy={isProcessing}
                            >
                                {__('Process Queue', 'quant')}
                            </Button>
                        </div>
                    </div>
                </CardBody>
            </Card>

            {tabs.length > 0 && (
                <Card className="quant-content__browser-card">
                    <TabPanel
                        tabs={tabs}
                        onSelect={(tabName) => {
                            setActiveTab(tabName);
                            setSelectedItems([]);
                        }}
                    >
                        {(tab) => (
                            <ContentBrowser
                                contentType={tab.contentType}
                                contentTypes={tab.contentTypes}
                                onSelectionChange={handleSelectionChange}
                                key={tab.name}
                            />
                        )}
                    </TabPanel>
                </Card>
            )}
        </div>
    );
}
