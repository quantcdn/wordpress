import { NavLink } from 'react-router-dom';
import { __ } from '@wordpress/i18n';
import './Layout.scss';

export function Layout({ children }) {
    return (
        <div className="quant-admin">
            <header className="quant-admin__header">
                <div className="quant-admin__logo">
                    <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                        <rect width="32" height="32" rx="6" fill="#5c40ff"/>
                        <text x="50%" y="55%" dominantBaseline="middle" textAnchor="middle" fill="white" fontSize="16" fontWeight="bold">Q</text>
                    </svg>
                    <span>QuantCDN</span>
                </div>
                <div className="quant-admin__version">
                    v{window.quantAdmin?.version || '2.0.0'}
                </div>
            </header>

            <div className="quant-admin__body">
                <nav className="quant-admin__sidebar">
                    <ul>
                        <li>
                            <NavLink to="/dashboard">
                                <span className="dashicons dashicons-dashboard"></span>
                                {__('Dashboard', 'quant')}
                            </NavLink>
                        </li>
                        <li>
                            <NavLink to="/cache">
                                <span className="dashicons dashicons-trash"></span>
                                {__('Cache', 'quant')}
                            </NavLink>
                        </li>
                        <li>
                            <NavLink to="/settings">
                                <span className="dashicons dashicons-admin-settings"></span>
                                {__('Settings', 'quant')}
                            </NavLink>
                        </li>
                    </ul>
                </nav>

                <main className="quant-admin__content">
                    {children}
                </main>
            </div>
        </div>
    );
}
