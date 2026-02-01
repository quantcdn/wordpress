import { useState, useEffect, useCallback } from '@wordpress/element';
import {
    Button,
    CheckboxControl,
    Spinner,
    SelectControl
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import './ContentBrowser.scss';

/**
 * Status badge component
 */
function StatusBadge({ status }) {
    const statusLabels = {
        synced: __('Synced', 'quant'),
        pending: __('Pending', 'quant'),
        never: __('Never', 'quant'),
        failed: __('Failed', 'quant'),
    };

    return (
        <span className={`quant-status-badge quant-status-badge--${status}`}>
            {statusLabels[status] || status}
        </span>
    );
}

/**
 * Pagination component
 */
function Pagination({ page, pages, onPageChange }) {
    if (pages <= 1) return null;

    return (
        <div className="quant-pagination">
            <Button
                variant="secondary"
                size="small"
                disabled={page <= 1}
                onClick={() => onPageChange(page - 1)}
            >
                {__('Previous', 'quant')}
            </Button>
            <span className="quant-pagination__info">
                {__('Page', 'quant')} {page} {__('of', 'quant')} {pages}
            </span>
            <Button
                variant="secondary"
                size="small"
                disabled={page >= pages}
                onClick={() => onPageChange(page + 1)}
            >
                {__('Next', 'quant')}
            </Button>
        </div>
    );
}

/**
 * ContentBrowser component - displays content list with sync status
 */
export function ContentBrowser({ contentType, contentTypes, onSelectionChange }) {
    const [items, setItems] = useState([]);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);
    const [totalItems, setTotalItems] = useState(0);
    const [selectedIds, setSelectedIds] = useState(new Set());
    const [selectAll, setSelectAll] = useState(false);
    const [selectedTaxonomy, setSelectedTaxonomy] = useState('');

    // Determine the actual content type to fetch
    const getContentParams = useCallback(() => {
        // If we have multiple content types (taxonomies), use the selected one
        if (contentTypes && contentTypes.length > 0) {
            const taxonomy = selectedTaxonomy || contentTypes[0]?.subtype || 'category';
            return {
                type: 'taxonomy',
                subtype: taxonomy
            };
        }

        // Single content type
        if (contentType) {
            return {
                type: contentType.type,
                subtype: contentType.subtype || ''
            };
        }

        return { type: 'post', subtype: '' };
    }, [contentType, contentTypes, selectedTaxonomy]);

    // Fetch content
    const fetchContent = useCallback(async () => {
        setLoading(true);

        const params = getContentParams();

        try {
            const response = await apiFetch({
                path: `/quant/v1/content?type=${params.type}&subtype=${params.subtype}&page=${page}&per_page=20`
            });

            setItems(response.items || []);
            setTotalPages(response.pages || 1);
            setTotalItems(response.total || 0);
        } catch (error) {
            console.error('Failed to fetch content:', error);
            setItems([]);
        } finally {
            setLoading(false);
        }
    }, [page, getContentParams]);

    // Load content on mount and when dependencies change
    useEffect(() => {
        fetchContent();
    }, [fetchContent]);

    // Reset page when content type changes
    useEffect(() => {
        setPage(1);
        setSelectedIds(new Set());
        setSelectAll(false);
    }, [contentType, selectedTaxonomy]);

    // Set initial taxonomy selection
    useEffect(() => {
        if (contentTypes && contentTypes.length > 0 && !selectedTaxonomy) {
            setSelectedTaxonomy(contentTypes[0].subtype);
        }
    }, [contentTypes, selectedTaxonomy]);

    // Notify parent of selection changes
    useEffect(() => {
        if (onSelectionChange) {
            const selectedItems = items
                .filter(item => selectedIds.has(item.id))
                .map(item => ({
                    type: item.type,
                    id: item.id
                }));
            onSelectionChange(selectedItems);
        }
    }, [selectedIds, items, onSelectionChange]);

    // Handle individual item selection
    const handleItemSelect = (id, checked) => {
        const newSelected = new Set(selectedIds);
        if (checked) {
            newSelected.add(id);
        } else {
            newSelected.delete(id);
        }
        setSelectedIds(newSelected);
        setSelectAll(newSelected.size === items.length);
    };

    // Handle select all
    const handleSelectAll = (checked) => {
        setSelectAll(checked);
        if (checked) {
            setSelectedIds(new Set(items.map(item => item.id)));
        } else {
            setSelectedIds(new Set());
        }
    };

    // Handle page change
    const handlePageChange = (newPage) => {
        setPage(newPage);
        setSelectedIds(new Set());
        setSelectAll(false);
    };

    // Format date for display
    const formatDate = (dateString) => {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleDateString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    // Build CDN URL
    const getCdnUrl = (item) => {
        // This would need to be configured based on the actual CDN domain
        return item.url;
    };

    // Render taxonomy selector for taxonomy tabs
    const renderTaxonomySelector = () => {
        if (!contentTypes || contentTypes.length <= 1) return null;

        const options = contentTypes.map(t => ({
            label: `${t.label} (${t.count})`,
            value: t.subtype
        }));

        return (
            <div className="quant-content-browser__taxonomy-selector">
                <SelectControl
                    label={__('Taxonomy', 'quant')}
                    value={selectedTaxonomy}
                    options={options}
                    onChange={setSelectedTaxonomy}
                    __nextHasNoMarginBottom
                />
            </div>
        );
    };

    if (loading) {
        return (
            <div className="quant-content-browser quant-content-browser--loading">
                <Spinner />
                <p>{__('Loading content...', 'quant')}</p>
            </div>
        );
    }

    return (
        <div className="quant-content-browser">
            {renderTaxonomySelector()}

            <div className="quant-content-browser__header">
                <span className="quant-content-browser__count">
                    {totalItems} {__('items', 'quant')}
                    {selectedIds.size > 0 && ` (${selectedIds.size} ${__('selected', 'quant')})`}
                </span>
                <Pagination
                    page={page}
                    pages={totalPages}
                    onPageChange={handlePageChange}
                />
            </div>

            {items.length === 0 ? (
                <div className="quant-content-browser__empty">
                    <p>{__('No content found.', 'quant')}</p>
                </div>
            ) : (
                <table className="quant-content-browser__table">
                    <thead>
                        <tr>
                            <th className="quant-content-browser__col-checkbox">
                                <CheckboxControl
                                    checked={selectAll}
                                    onChange={handleSelectAll}
                                    __nextHasNoMarginBottom
                                />
                            </th>
                            <th className="quant-content-browser__col-title">
                                {__('Title', 'quant')}
                            </th>
                            <th className="quant-content-browser__col-url">
                                {__('URL', 'quant')}
                            </th>
                            <th className="quant-content-browser__col-status">
                                {__('Status', 'quant')}
                            </th>
                            <th className="quant-content-browser__col-synced">
                                {__('Last Synced', 'quant')}
                            </th>
                            <th className="quant-content-browser__col-actions">
                                {__('Actions', 'quant')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((item) => (
                            <tr
                                key={item.id}
                                className={selectedIds.has(item.id) ? 'is-selected' : ''}
                            >
                                <td className="quant-content-browser__col-checkbox">
                                    <CheckboxControl
                                        checked={selectedIds.has(item.id)}
                                        onChange={(checked) => handleItemSelect(item.id, checked)}
                                        __nextHasNoMarginBottom
                                    />
                                </td>
                                <td className="quant-content-browser__col-title">
                                    <strong>{item.title || __('(No title)', 'quant')}</strong>
                                    {item.subtype && (
                                        <span className="quant-content-browser__subtype">
                                            {item.subtype}
                                        </span>
                                    )}
                                </td>
                                <td className="quant-content-browser__col-url">
                                    <code>{item.url}</code>
                                </td>
                                <td className="quant-content-browser__col-status">
                                    <StatusBadge status={item.sync_status} />
                                </td>
                                <td className="quant-content-browser__col-synced">
                                    {formatDate(item.last_synced)}
                                </td>
                                <td className="quant-content-browser__col-actions">
                                    <Button
                                        variant="link"
                                        size="small"
                                        href={getCdnUrl(item)}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        {__('View', 'quant')}
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            <div className="quant-content-browser__footer">
                <Pagination
                    page={page}
                    pages={totalPages}
                    onPageChange={handlePageChange}
                />
            </div>
        </div>
    );
}
