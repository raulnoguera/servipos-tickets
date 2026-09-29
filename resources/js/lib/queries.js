import { useQuery } from '@tanstack/react-query';
import { api } from './api';

/** Catálogos para formularios; se cachean 10 minutos. */
export function useCatalogs() {
    return useQuery({ queryKey: ['catalogs'], queryFn: () => api('catalogs'), staleTime: 10 * 60 * 1000 });
}
