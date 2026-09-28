/**
 * Los requisitos de la contraseña, evaluados mientras se escribe.
 *
 * La política no está escrita acá: llega del servidor
 * (`PasswordPolicyData`, armada desde `Password::defaults()`), y lo único
 * que este archivo sabe es cómo mira Laravel cada requisito. Las
 * expresiones son las de `Illuminate\Validation\Rules\Password`, con las
 * mismas clases Unicode, para que el checklist no marque como cumplido algo
 * que después el servidor rechaza —ni al revés, con una «ñ» o una tilde—.
 *
 * Es una ayuda, no una validación: la que decide sigue siendo la del
 * `FormRequest`, y por eso el botón de guardar nunca se deshabilita por lo
 * que diga esta función.
 */

export type PasswordPolicy = App.Modules.Shared.Data.PasswordPolicyData;

export type PasswordRequirementKey =
    'length' | 'maxLength' | 'mixedCase' | 'letters' | 'numbers' | 'symbols';

export type PasswordRequirement = {
    key: PasswordRequirementKey;
    label: string;
    met: boolean;
    /** Un dato más para mostrar al lado, como «9 de 12». */
    detail?: string;
};

const MIXED_CASE = /(\p{Ll}+.*\p{Lu})|(\p{Lu}+.*\p{Ll})/u;
const LETTER = /\p{L}/u;
const NUMBER = /\p{N}/u;
const SYMBOL = /\p{Z}|\p{S}|\p{P}/u;

/**
 * Cuántos caracteres tiene, contados como los cuenta `mb_strlen`.
 *
 * `value.length` cuenta unidades UTF-16: un emoji valdría dos y el
 * contador adelantaría al servidor.
 */
export function passwordLength(value: string): number {
    return [...value].length;
}

export function evaluatePassword(
    policy: PasswordPolicy,
    value: string,
): PasswordRequirement[] {
    const length = passwordLength(value);
    const requirements: PasswordRequirement[] = [
        {
            key: 'length',
            label: `Al menos ${policy.minLength} caracteres`,
            met: length >= policy.minLength,
            detail: `${Math.min(length, policy.minLength)} de ${policy.minLength}`,
        },
    ];

    if (policy.maxLength !== null) {
        requirements.push({
            key: 'maxLength',
            label: `Hasta ${policy.maxLength} caracteres`,
            met: length <= policy.maxLength,
        });
    }

    if (policy.mixedCase) {
        requirements.push({
            key: 'mixedCase',
            label: 'Mayúsculas y minúsculas',
            met: MIXED_CASE.test(value),
        });
    } else if (policy.letters) {
        // Con mayúsculas y minúsculas exigidas, «una letra» ya está
        // cubierta: mostrarla sería un renglón que se tilda solo.
        requirements.push({
            key: 'letters',
            label: 'Al menos una letra',
            met: LETTER.test(value),
        });
    }

    if (policy.numbers) {
        requirements.push({
            key: 'numbers',
            label: 'Al menos un número',
            met: NUMBER.test(value),
        });
    }

    if (policy.symbols) {
        requirements.push({
            key: 'symbols',
            label: 'Al menos un símbolo, como # o $',
            met: SYMBOL.test(value),
        });
    }

    return requirements;
}

export function meetsAll(requirements: PasswordRequirement[]): boolean {
    return requirements.every((requirement) => requirement.met);
}
