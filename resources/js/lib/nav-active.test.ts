import { describe, expect, it } from 'vitest';
import type { NavLink } from '@/types';
import { activeChild } from './nav-active';

const hijo = (title: string, url: string, activeOn?: string[]): NavLink => ({
    title,
    href: url,
    activeOn,
});

const multas = [
    hijo('Expedientes', '/multas/expedientes', [
        'multas/show',
        'multas/create',
    ]),
    hijo('Caja de Multas', '/multas/caja'),
    hijo('Caja del día', '/multas/caja/dia'),
    hijo('Pagos por verificar', '/multas/pagos'),
];

const titulo = (path: string, component = 'otra') =>
    activeChild(multas, path, component)?.title;

describe('la pantalla activa de un acordeón', () => {
    it('marca solo la más específica entre las que cuelgan', () => {
        expect(titulo('/multas/caja/dia')).toBe('Caja del día');
        expect(titulo('/multas/caja')).toBe('Caja de Multas');
    });

    it('incluye lo que cuelga de una hermana, como la ficha del expediente', () => {
        expect(titulo('/multas/expedientes/12')).toBe('Expedientes');
        expect(titulo('/multas/pagos/3/deposito')).toBe('Pagos por verificar');
    });

    it('no confunde un prefijo de texto con una dirección que cuelga', () => {
        expect(titulo('/multas/cajas')).toBeUndefined();
    });

    it('reconoce por la pantalla lo que vive fuera de su dirección', () => {
        expect(titulo('/multas/12', 'multas/show')).toBe('Expedientes');
        expect(titulo('/multas/nueva', 'multas/create')).toBe('Expedientes');
    });

    it('no marca ninguna fuera del acordeón', () => {
        expect(titulo('/haberes/expedientes')).toBeUndefined();
    });
});
