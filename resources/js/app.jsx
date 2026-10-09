import '../css/app.css';
import { StrictMode, lazy } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthProvider, useAuth } from './lib/auth';
import { LoadingBlock, Toaster } from './components/ui';
import Layout from './components/Layout';
import { UpdateWatcher } from './components/AppUpdate';
import Login from './pages/Login';
import Access from './pages/Access';
import { printSignature } from './lib/signature';

const Dashboard = lazy(() => import('./pages/Dashboard'));
const Tickets = lazy(() => import('./pages/Tickets'));
const TicketNew = lazy(() => import('./pages/TicketNew'));
const TicketDetail = lazy(() => import('./pages/TicketDetail'));
const TechnicianHome = lazy(() => import('./pages/TechnicianHome'));
const TechnicianHistory = lazy(() => import('./pages/TechnicianHistory'));
const Atms = lazy(() => import('./pages/Atms'));
const AtmHistory = lazy(() => import('./pages/AtmHistory'));
const Board = lazy(() => import('./pages/Board'));
const Catalogs = lazy(() => import('./pages/Catalogs'));
const Users = lazy(() => import('./pages/Users'));
const Reports = lazy(() => import('./pages/Reports'));
const ReportDetail = lazy(() => import('./pages/ReportDetail'));
const Import = lazy(() => import('./pages/Import'));
const Audit = lazy(() => import('./pages/Audit'));

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
    const location = useLocation();

    // Enlace de acceso enviado por el administrador: funciona con o sin sesión iniciada.
    if (location.pathname === '/acceso') {
        return <Access />;
    }

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
                <Route path="historial" element={<Guard roles={['technician']}><TechnicianHistory /></Guard>} />
                <Route path="tickets" element={<Guard roles={staff}><Tickets /></Guard>} />
                <Route path="tickets/nuevo" element={<Guard roles={staff}><TicketNew /></Guard>} />
                <Route path="tickets/:id" element={<TicketDetail />} />
                <Route path="cajeros" element={<Guard roles={staff}><Atms /></Guard>} />
                <Route path="cajeros/:id" element={<Guard roles={staff}><AtmHistory /></Guard>} />
                <Route path="tablero" element={<Guard roles={staff}><Board /></Guard>} />
                <Route path="reportes" element={<Guard roles={staff}><Reports /></Guard>} />
                <Route path="reportes/detalle" element={<Guard roles={staff}><ReportDetail /></Guard>} />
                <Route path="catalogos" element={<Guard roles={['admin']}><Catalogs /></Guard>} />
                <Route path="usuarios" element={<Guard roles={['admin']}><Users /></Guard>} />
                <Route path="importar" element={<Guard roles={['admin']}><Import /></Guard>} />
                <Route path="auditoria" element={<Guard roles={['admin']}><Audit /></Guard>} />
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
        if (event.data?.type === 'navigate' && new URL(event.data.url, window.location.origin).origin === window.location.origin) {
            window.location.assign(event.data.url);
        }
        if (event.data?.type === 'push') {
            // Llegó un aviso: la campana se actualiza y, si la app está a la vista, suena y se muestra.
            window.dispatchEvent(new CustomEvent('servipos:push', { detail: event.data.data }));
        }
    });
}

printSignature();

createRoot(document.getElementById('app')).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <BrowserRouter>
                <AuthProvider>
                    <UpdateWatcher />
                    <AppRoutes />
                    <Toaster />
                </AuthProvider>
            </BrowserRouter>
        </QueryClientProvider>
    </StrictMode>,
);
