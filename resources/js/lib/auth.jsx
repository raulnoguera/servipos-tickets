import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api, setUnauthorizedHandler } from './api';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(undefined); // undefined = cargando
    const queryClient = useQueryClient();

    useEffect(() => {
        api('auth/me').then((r) => setUser(r.data)).catch(() => setUser(null));
        setUnauthorizedHandler(() => setUser(null));
    }, []);

    const login = useCallback(async (email, password, remember) => {
        const r = await api('auth/login', { method: 'POST', body: { email, password, remember } });
        queryClient.clear();
        setUser(r.data);
        return r.data;
    }, [queryClient]);

    const logout = useCallback(async () => {
        await api('auth/logout', { method: 'POST' }).catch(() => {});
        queryClient.clear();
        setUser(null);
    }, [queryClient]);

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
