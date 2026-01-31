import { HashRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Layout } from './components/Layout/Layout';
import { Dashboard } from './pages/Dashboard';
import { Content } from './pages/Content';
import { Cache } from './pages/Cache';
import { Settings } from './pages/Settings';
import { Migration } from './pages/Migration';
import { Onboarding } from './pages/Onboarding';
import { Diagnostics } from './pages/Diagnostics';

export function App() {
    return (
        <HashRouter>
            <Layout>
                <Routes>
                    <Route path="/" element={<Navigate to="/dashboard" replace />} />
                    <Route path="/dashboard" element={<Dashboard />} />
                    <Route path="/content" element={<Content />} />
                    <Route path="/cache" element={<Cache />} />
                    <Route path="/settings" element={<Settings />} />
                    <Route path="/migration" element={<Migration />} />
                    <Route path="/onboarding" element={<Onboarding />} />
                    <Route path="/diagnostics" element={<Diagnostics />} />
                </Routes>
            </Layout>
        </HashRouter>
    );
}
