import '../css/app.css';
import { StrictMode, lazy } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthProvider, useAuth } from './lib/auth';
import { LoadingBlock, Toaster } from './components/ui';
import Layout from './components/Layout';
import Login from './pages/Login';

const Dashboard = lazy(() => import('./pages/Dashboard'));
const Tickets = lazy(() => import('./pages/Tickets'));
const TicketNew = lazy(() => import('./pages/TicketNew'));
const TicketDetail = lazy(() => import('./pages/TicketDetail'));
const TechnicianHome = lazy(() => import('./pages/TechnicianHome'));
const Atms = lazy(() => import('./pages/Atms'));
const Catalogs = lazy(() => import('./pages/Catalogs'));
const Users = lazy(() => import('./pages/Users'));
const Reports = lazy(() => import('./pages/Reports'));
const Import = lazy(() => import('./pages/Import'));

const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: 1, refetchOnWindowFocus: true, staleTime: 15000 } },
});

function Guard({ roles, children }) {
    const { user } = useAuth();
    if (roles && !roles.includes(user.role)) {
        return <Navigate to={user.role === 'technician' ? '/mis-tickets' : '/'} replace />;
    }
    return children;
}

function AppRoutes() {
    const { user } = useAuth();

    if (user === undefined) {
        return <LoadingBlock label="Iniciando…" />;
    }
    if (user === null) {
        return <Login />;
    }

    const staff = ['admin', 'operator'];
    return (
        <Routes>
            <Route element={<Layout />}>
                <Route index element={user.role === 'technician' ? <Navigate to="/mis-tickets" replace /> : <Dashboard />} />
                <Route path="mis-tickets" element={<Guard roles={['technician']}><TechnicianHome /></Guard>} />
                <Route path="tickets" element={<Guard roles={staff}><Tickets /></Guard>} />
                <Route path="tickets/nuevo" element={<Guard roles={staff}><TicketNew /></Guard>} />
                <Route path="tickets/:id" element={<TicketDetail />} />
                <Route path="cajeros" element={<Guard roles={staff}><Atms /></Guard>} />
                <Route path="reportes" element={<Guard roles={staff}><Reports /></Guard>} />
                <Route path="catalogos" element={<Guard roles={['admin']}><Catalogs /></Guard>} />
                <Route path="usuarios" element={<Guard roles={['admin']}><Users /></Guard>} />
                <Route path="importar" element={<Guard roles={['admin']}><Import /></Guard>} />
                <Route path="*" element={<Navigate to="/" replace />} />
            </Route>
        </Routes>
    );
}

// Guarda la app en el teléfono para abrir sin señal y recibir notificaciones.
if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', async () => {
        const registration = await navigator.serviceWorker.register('/sw.js').catch(() => null);
        // Guarda las pantallas de esta versión para usarlas sin señal.
        (registration?.active || (await navigator.serviceWorker.ready).active)?.postMessage('precache');
    });
    // Al tocar una notificación con la app abierta, el service worker pide navegar al ticket.
    navigator.serviceWorker.addEventListener('message', (event) => {
        if (event.data?.type === 'navigate') {
            window.location.assign(event.data.url);
        }
    });
}

createRoot(document.getElementById('app')).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <BrowserRouter>
                <AuthProvider>
                    <AppRoutes />
                    <Toaster />
                </AuthProvider>
            </BrowserRouter>
        </QueryClientProvider>
    </StrictMode>,
);
