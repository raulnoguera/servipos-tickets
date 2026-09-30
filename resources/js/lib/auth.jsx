import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api, setUnauthorizedHandler } from './api';
import { outbox } from './outbox';
import { disablePush } from './push';

/** Borra del teléfono las respuestas guardadas de otro usuario. */
function clearOfflineCache() {
    navigator.serviceWorker?.controller?.postMessage('clear-api-cache');
    return window.caches?.delete('servipos-api-v1').catch(() => {});
}

const AuthContext = createContext(null);
const STORED_USER = 'servipos:user';

/** Último usuario con sesión, para abrir la app sin señal. */
const storedUser = {
    get() {
        try {
            return JSON.parse(localStorage.getItem(STORED_USER));
        } catch {
            return null;
        }
    },
    set(user) {
        try {
            user ? localStorage.setItem(STORED_USER, JSON.stringify(user)) : localStorage.removeItem(STORED_USER);
        } catch {
            // Almacenamiento no disponible (modo privado): solo se pierde el arranque sin señal.
        }
    },
};

export function AuthProvider({ children }) {
    const [user, setUser] = useState(undefined); // undefined = cargando
    const queryClient = useQueryClient();

    useEffect(() => {
        api('auth/me')
            .then((r) => {
                storedUser.set(r.data);
                setUser(r.data);
            })
            .catch((error) => {
                // Sin señal: se sigue con el último usuario conocido. Con 401 la sesión terminó.
                const offlineUser = error.offline ? storedUser.get() : null;
                if (!offlineUser) {
                    storedUser.set(null);
                }
                setUser(offlineUser);
            });
        setUnauthorizedHandler(() => {
            storedUser.set(null);
            setUser(null);
        });
    }, []);

    const login = useCallback(async (email, password, remember) => {
        const r = await api('auth/login', { method: 'POST', body: { email, password, remember } });
        await clearOfflineCache();
        queryClient.clear();
        storedUser.set(r.data);
        setUser(r.data);
        return r.data;
    }, [queryClient]);

    const logout = useCallback(async () => {
        const pending = user ? (await outbox.all().catch(() => [])).filter((i) => i.userId === user.id) : [];
        if (pending.length && !window.confirm(`Hay ${pending.length} envío(s) guardados sin señal que aún no llegan al servidor. Si cierra sesión se perderán. ¿Cerrar sesión de todas formas?`)) {
            return;
        }
        await disablePush();
        await api('auth/logout', { method: 'POST' }).catch(() => {});
        if (user) {
            await outbox.clear(user.id).catch(() => {});
        }
        await clearOfflineCache();
        queryClient.clear();
        storedUser.set(null);
        setUser(null);
    }, [queryClient, user]);

    const value = {
        user,
        login,
        logout,
        isAdmin: user?.role === 'admin',
        isTechnician: user?.role === 'technician',
        canManage: user?.role === 'admin' || user?.role === 'operator',
    };

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export const useAuth = () => useContext(AuthContext);
