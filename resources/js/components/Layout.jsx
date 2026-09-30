import { Component, Suspense, useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import {
    BarChart3, Bell, Building2, ClipboardList, FileUp, LayoutDashboard, ListChecks, LogOut, Menu, PlusCircle, Users, Wrench, X,
} from 'lucide-react';
import { useAuth } from '../lib/auth';
import { initials } from '../lib/format';
import { LoadingBlock, cx } from './ui';
import { Logo } from './Logo';
import { ConnectivityBar, OutboxSync } from './Offline';
import NotificationSettings from './NotificationSettings';

const NAV = [
    { to: '/', label: 'Monitor', icon: LayoutDashboard, roles: ['admin', 'operator'], end: true },
    { to: '/tickets/nuevo', label: 'Nuevo ticket', icon: PlusCircle, roles: ['admin', 'operator'] },
    { to: '/tickets', label: 'Tickets', icon: ClipboardList, roles: ['admin', 'operator'], end: true },
    { to: '/mis-tickets', label: 'Mis tickets', icon: Wrench, roles: ['technician'] },
    { to: '/reportes', label: 'Reportes', icon: BarChart3, roles: ['admin', 'operator'] },
    { section: 'Maestros', roles: ['admin', 'operator'] },
    { to: '/cajeros', label: 'Cajeros', icon: Building2, roles: ['admin', 'operator'] },
    { to: '/catalogos', label: 'Catálogos', icon: ListChecks, roles: ['admin'] },
    { to: '/usuarios', label: 'Usuarios y técnicos', icon: Users, roles: ['admin'] },
    { to: '/importar', label: 'Importar Excel', icon: FileUp, roles: ['admin'] },
];

function NavItems({ role, onNavigate }) {
    return (
        <nav className="flex flex-col gap-0.5 px-3">
            {NAV.filter((i) => i.roles.includes(role)).map((item) =>
                item.section ? (
                    <div key={item.section} className="mt-5 mb-1.5 px-3 text-[10px] font-bold uppercase tracking-[0.16em] text-white/40">
                        {item.section}
                    </div>
                ) : (
                    <NavLink
                        key={item.to}
                        to={item.to}
                        end={item.end}
                        onClick={onNavigate}
                        className={({ isActive }) =>
                            cx(
                                'group relative flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold transition-colors',
                                isActive ? 'bg-white/10 text-white' : 'text-white/65 hover:bg-white/5 hover:text-white',
                            )
                        }
                    >
                        {({ isActive }) => (
                            <>
                                {isActive && <span className="absolute inset-y-2 left-0 w-1 rounded-r bg-brand-400" />}
                                <item.icon className={cx('size-[18px]', isActive ? 'text-brand-300' : 'text-white/50 group-hover:text-white/80')} />
                                {item.label}
                            </>
                        )}
                    </NavLink>
                ),
            )}
        </nav>
    );
}

function UserCard({ user, onLogout }) {
    const [settings, setSettings] = useState(false);
    return (
        <div className="m-3 flex items-center gap-3 rounded-xl bg-white/5 p-3">
            <div className="grid size-9 shrink-0 place-items-center rounded-full bg-brand-500 text-xs font-bold text-white">{initials(user.name)}</div>
            <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-semibold text-white">{user.name}</div>
                <div className="truncate text-xs text-white/50">{user.role_label}</div>
            </div>
            <button type="button" onClick={() => setSettings(true)} className="rounded-lg p-2 text-white/60 hover:bg-white/10 hover:text-white" title="Notificaciones" aria-label="Notificaciones">
                <Bell className="size-4" />
            </button>
            <button type="button" onClick={onLogout} className="rounded-lg p-2 text-white/60 hover:bg-white/10 hover:text-white" title="Cerrar sesión" aria-label="Cerrar sesión">
                <LogOut className="size-4" />
            </button>
            <NotificationSettings open={settings} onClose={() => setSettings(false)} />
        </div>
    );
}

/** Evita la pantalla en blanco si una pantalla no se puede cargar (p. ej. sin señal). */
class PageErrorBoundary extends Component {
    state = { error: null };

    static getDerivedStateFromError(error) {
        return { error };
    }

    render() {
        if (!this.state.error) {
            return this.props.children;
        }
        return (
            <div className="mx-auto max-w-md py-16 text-center">
                <p className="font-display text-lg font-bold text-ink">No se pudo abrir esta pantalla</p>
                <p className="mt-2 text-sm text-muted">
                    {navigator.onLine ? 'Ocurrió un error al cargarla.' : 'Parece que no hay señal y esta pantalla aún no estaba guardada en el teléfono.'}
                </p>
                <div className="mt-5 flex justify-center gap-2">
                    <button type="button" onClick={() => window.history.back()} className="rounded-lg border border-line bg-white px-4 py-2 text-sm font-semibold text-ink">Volver</button>
                    <button type="button" onClick={() => window.location.reload()} className="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white">Reintentar</button>
                </div>
            </div>
        );
    }
}

export default function Layout() {
    const { user, logout } = useAuth();
    const [open, setOpen] = useState(false);
    const location = useLocation();

    return (
        <div className="min-h-dvh lg:pl-64">
            {/* Barra lateral (escritorio) */}
            <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col bg-navy-900 lg:flex">
                <div className="px-6 py-6">
                    <Logo light />
                </div>
                <div className="flex-1 overflow-y-auto pb-4">
                    <NavItems role={user.role} />
                </div>
                <UserCard user={user} onLogout={logout} />
            </aside>

            {/* Barra superior (teléfono / tablet) */}
            <header className="sticky top-0 z-30 flex items-center justify-between bg-navy-900 px-4 pt-[env(safe-area-inset-top)] lg:hidden">
                <div className="flex h-14 items-center">
                    <Logo light />
                </div>
                <button type="button" onClick={() => setOpen(true)} className="rounded-lg p-2 text-white/80 hover:bg-white/10" aria-label="Abrir menú">
                    <Menu className="size-6" />
                </button>
            </header>

            {open && (
                <div className="fixed inset-0 z-40 lg:hidden" onClick={() => setOpen(false)}>
                    <div className="absolute inset-0 bg-navy-900/60" />
                    <div className="absolute inset-y-0 right-0 flex w-72 max-w-[85vw] flex-col bg-navy-900 pt-[env(safe-area-inset-top)]" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center justify-between px-5 py-4">
                            <span className="text-sm font-semibold text-white/70">Menú</span>
                            <button type="button" onClick={() => setOpen(false)} className="rounded-lg p-2 text-white/80 hover:bg-white/10" aria-label="Cerrar menú">
                                <X className="size-5" />
                            </button>
                        </div>
                        <div className="flex-1 overflow-y-auto">
                            <NavItems role={user.role} onNavigate={() => setOpen(false)} />
                        </div>
                        <UserCard user={user} onLogout={logout} />
                    </div>
                </div>
            )}

            <main key={location.pathname} className="mx-auto w-full max-w-[1400px] px-4 py-6 pb-[max(1.5rem,env(safe-area-inset-bottom))] sm:px-6 lg:px-8 lg:py-8">
                <OutboxSync />
                <ConnectivityBar />
                <PageErrorBoundary key={location.pathname}>
                    <Suspense fallback={<LoadingBlock />}>
                        <Outlet />
                    </Suspense>
                </PageErrorBoundary>
            </main>
        </div>
    );
}
