import { HashRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Layout } from './components/Layout/Layout';
import { Dashboard } from './pages/Dashboard';
import { Cache } from './pages/Cache';
import { Settings } from './pages/Settings';

export function App() {
    return (
        <HashRouter>
            <Layout>
                <Routes>
                    <Route path="/" element={<Navigate to="/dashboard" replace />} />
                    <Route path="/dashboard" element={<Dashboard />} />
                    <Route path="/cache" element={<Cache />} />
                    <Route path="/settings" element={<Settings />} />
                </Routes>
            </Layout>
        </HashRouter>
    );
}
