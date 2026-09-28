import { describe, expect, it } from 'vitest';
import type { PasswordPolicy } from './password-policy';
import { evaluatePassword, meetsAll, passwordLength } from './password-policy';

/** La política de arranque, la de `AppServiceProvider`. */
const politica: PasswordPolicy = {
    minLength: 12,
    maxLength: null,
    mixedCase: true,
    letters: true,
    numbers: true,
    symbols: true,
    uncompromised: false,
};

function cumplidos(value: string, policy = politica): Record<string, boolean> {
    return Object.fromEntries(
        evaluatePassword(policy, value).map((r) => [r.key, r.met]),
    );
}

describe('evaluatePassword', () => {
    it('vacía no cumple nada', () => {
        expect(cumplidos('')).toEqual({
            length: false,
            mixedCase: false,
            numbers: false,
            symbols: false,
        });
    });

    it('una clave que cumple la política la marca completa', () => {
        const requisitos = evaluatePassword(politica, 'Contrasena.Nueva.2026');

        expect(meetsAll(requisitos)).toBe(true);
    });

    it('la temporal que genera el sistema cumple por construcción', () => {
        expect(meetsAll(evaluatePassword(politica, 'k7#Qm.Z2xB4pT%9a'))).toBe(
            true,
        );
    });

    it('cuenta el largo como el servidor y muestra el avance', () => {
        const [largo] = evaluatePassword(politica, 'Abc1.');

        expect(largo).toMatchObject({ met: false, detail: '5 de 12' });
    });

    it('el contador no pasa del mínimo', () => {
        const [largo] = evaluatePassword(politica, 'Una.Clave.Bien.Larga.2026');

        expect(largo.detail).toBe('12 de 12');
    });

    it('un emoji vale un carácter, como en mb_strlen', () => {
        expect(passwordLength('🔑')).toBe(1);
        expect(passwordLength('Ñandú')).toBe(5);
    });

    it('las letras con tilde y la ñ cuentan para mayúsculas y minúsculas', () => {
        expect(cumplidos('ÑANDÚ').mixedCase).toBe(false);
        expect(cumplidos('Ñandú').mixedCase).toBe(true);
        expect(cumplidos('ñandÚ').mixedCase).toBe(true);
    });

    it('el espacio y los símbolos Unicode cuentan como símbolo, igual que en Laravel', () => {
        expect(cumplidos('una clave').symbols).toBe(true);
        expect(cumplidos('clave€').symbols).toBe(true);
        expect(cumplidos('clave¿').symbols).toBe(true);
        expect(cumplidos('Clave2026').symbols).toBe(false);
    });

    it('cualquier dígito Unicode cuenta como número', () => {
        expect(cumplidos('clave٣').numbers).toBe(true);
        expect(cumplidos('clave').numbers).toBe(false);
    });

    it('sin mayúsculas exigidas pide una letra', () => {
        const requisitos = evaluatePassword(
            { ...politica, mixedCase: false },
            '123456789012',
        );

        expect(requisitos.map((r) => r.key)).toContain('letters');
        expect(requisitos.find((r) => r.key === 'letters')?.met).toBe(false);
    });

    it('con mayúsculas exigidas no repite «una letra»', () => {
        expect(evaluatePassword(politica, '').map((r) => r.key)).not.toContain(
            'letters',
        );
    });

    it('muestra el máximo solo si la política lo tiene', () => {
        expect(
            cumplidos('Abc.12345678', { ...politica, maxLength: 10 }),
        ).toMatchObject({
            maxLength: false,
        });
        expect(cumplidos('Abc.1234')).not.toHaveProperty('maxLength');
    });

    it('lo que la política no exige no aparece', () => {
        const requisitos = evaluatePassword(
            {
                ...politica,
                mixedCase: false,
                letters: false,
                numbers: false,
                symbols: false,
            },
            'x',
        );

        expect(requisitos.map((r) => r.key)).toEqual(['length']);
    });
});
