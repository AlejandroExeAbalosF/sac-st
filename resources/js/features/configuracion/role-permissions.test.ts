import { describe, expect, it } from 'vitest';
import { calcularCambios, cantidadDeCambios } from './role-permissions';
import type { GrupoDePermisos } from './role-permissions';

const permiso = (name: string) => ({
    name,
    label: name,
    description: '',
    sensitive: false,
    delegable: true,
});

const grupos: GrupoDePermisos[] = [
    {
        key: 'expedientes',
        label: 'Expedientes',
        permissions: [permiso('expedientes.ver'), permiso('expedientes.crear')],
    },
    {
        key: 'caja',
        label: 'Caja',
        permissions: [permiso('caja.ver'), permiso('caja.arquear')],
    },
];

describe('calcularCambios', () => {
    it('separa lo que se otorga de lo que se retira', () => {
        const cambios = calcularCambios(
            grupos,
            new Set(['expedientes.ver', 'caja.ver']),
            new Set(['expedientes.ver', 'caja.arquear']),
        );

        expect(cambios.otorgados.map((p) => p.name)).toEqual(['caja.arquear']);
        expect(cambios.retirados.map((p) => p.name)).toEqual(['caja.ver']);
        expect(cantidadDeCambios(cambios)).toBe(2);
    });

    it('lista en el orden del catálogo y no en el de los clics', () => {
        const cambios = calcularCambios(
            grupos,
            new Set(),
            new Set(['caja.arquear', 'expedientes.crear', 'caja.ver']),
        );

        expect(cambios.otorgados.map((p) => p.name)).toEqual([
            'expedientes.crear',
            'caja.ver',
            'caja.arquear',
        ]);
    });

    it('sin diferencias no hay cambios', () => {
        const cambios = calcularCambios(
            grupos,
            new Set(['caja.ver']),
            new Set(['caja.ver']),
        );

        expect(cantidadDeCambios(cambios)).toBe(0);
    });

    it('ignora permisos guardados que la pantalla no ofrece', () => {
        // Una capacidad de desarrollo, por ejemplo: no está en ningún grupo.
        const cambios = calcularCambios(
            grupos,
            new Set(['dev.forzar-cbu']),
            new Set(),
        );

        expect(cantidadDeCambios(cambios)).toBe(0);
    });
});
