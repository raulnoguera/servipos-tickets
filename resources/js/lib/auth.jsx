import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api, setUnauthorizedHandler } from './api';
import { outbox } from './outbox';
import { disablePush, ensurePush } from './push';

/** Borra del teléfono las respuestas guardadas de otro usuario. */
function clearOfflineCache() {
    navigator.serviceWorker?.controller?.postMessage('clear-api-cache');
    return window.caches?.delete('servipos-api-v1').catch(() => {});
}

const AuthContext = createContext(null);
const STORED_USER = 'servipos:user';
const LOGOUT_PENDING = 'servipos:logout-pendiente';

/**
 * Cierre de sesión hecho sin señal: el servidor aún no se enteró y sus cookies siguen
 * vigentes. Se recuerda aquí para cerrarla de verdad antes de cualquier otra cosa.
 */
const pendingLogout = {
    get() {
        try {
            return localStorage.getItem(LOGOUT_PENDING) === '1';
        } catch {
            return false;
        }
    },
    set(value) {
        try {
            value ? localStorage.setItem(LOGOUT_PENDING, '1') : localStorage.removeItem(LOGOUT_PENDING);
        } catch {
            // Sin almacenamiento no se puede recordar; queda como antes.
        }
    },
    /** Intenta cerrar la sesión en el servidor. Devuelve true si ya no queda nada pendiente. */
    async flush() {
        if (!this.get()) {
            return true;
        }
        try {
            await api('auth/logout', { method: 'POST', timeout: 8000 });
        } catch (error) {
            if (error.offline) {
                return false; // sigue sin señal: se reintenta en el próximo arranque
            }
            // 401: la sesión ya no existía. Cualquier otro error tampoco deja una sesión utilizable.
        }
        this.set(false);
        return true;
    },
};

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
        // La sesión terminó en el servidor (venció, desactivaron al usuario o le cambiaron la
        // contraseña): se borra lo guardado para leer sin señal. Los envíos pendientes se
        // conservan: son trabajo del técnico y se envían cuando vuelva a entrar.
        setUnauthorizedHandler(() => {
            storedUser.set(null);
            clearOfflineCache();
            queryClient.clear();
            setUser(null);
        });

        (async () => {
            // Si alguien cerró sesión sin señal, primero se cierra de verdad en el servidor.
            if (!(await pendingLogout.flush())) {
                storedUser.set(null);
                setUser(null);
                return;
            }
            try {
                const r = await api('auth/me');
                storedUser.set(r.data);
                setUser(r.data);
                ensurePush();
            } catch (error) {
                // Sin señal: se sigue con el último usuario conocido. Con 401 la sesión terminó.
                const offlineUser = error.offline ? storedUser.get() : null;
                if (!offlineUser) {
                    storedUser.set(null);
                }
                setUser(offlineUser);
            }
        })();
    }, [queryClient]);

    const login = useCallback(async (email, password, remember) => {
        // Si quedó un cierre de sesión sin enviar, se completa antes de entrar con otra cuenta.
        await pendingLogout.flush();
        const r = await api('auth/login', { method: 'POST', body: { email, password, remember } });
        pendingLogout.set(false);
        await clearOfflineCache();
        queryClient.clear();
        storedUser.set(r.data);
        setUser(r.data);
        ensurePush(); // mismo equipo con permiso ya otorgado: avisos activos sin volver a tocar "Activar"
        return r.data;
    }, [queryClient]);

    /** La sesión ya se inició en el servidor (enlace de acceso): se deja la app lista para ese usuario. */
    const adopt = useCallback(async (next) => {
        pendingLogout.set(false);
        await clearOfflineCache();
        queryClient.clear();
        storedUser.set(next);
        setUser(next);
        ensurePush();
    }, [queryClient]);

    const logout = useCallback(async () => {
        const pending = user ? (await outbox.all().catch(() => [])).filter((i) => i.userId === user.id) : [];
        if (pending.length && !window.confirm(`Hay ${pending.length} envío(s) guardados sin señal que aún no llegan al servidor. Si cierra sesión se perderán. ¿Cerrar sesión de todas formas?`)) {
            return;
        }
        await disablePush();
        try {
            await api('auth/logout', { method: 'POST', timeout: 8000 });
        } catch (error) {
            // Sin señal el servidor no se entera: se deja anotado para cerrarla en cuanto haya conexión.
            if (error.offline) {
                pendingLogout.set(true);
            }
        }
        if (user) {
            await outbox.clear(user.id).catch(() => {});
        }
        await clearOfflineCache();
        queryClient.clear();
        storedUser.set(null);
        setUser(null);
    }, [queryClient, user]);

    const updateUser = useCallback((next) => {
        storedUser.set(next);
        setUser(next);
    }, []);

    const value = {
        user,
        updateUser,
        login,
        adopt,
        logout,
        isAdmin: user?.role === 'admin',
        isTechnician: user?.role === 'technician',
        canManage: user?.role === 'admin' || user?.role === 'operator',
    };

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export const useAuth = () => useContext(AuthContext);
