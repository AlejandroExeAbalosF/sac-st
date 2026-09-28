import { Check, Circle, ServerCog } from 'lucide-react';
import type { Ref } from 'react';
import { useId, useMemo } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Label } from '@/components/ui/label';
import type {
    PasswordPolicy,
    PasswordRequirement,
} from '@/features/cuenta/password-policy';
import { evaluatePassword, meetsAll } from '@/features/cuenta/password-policy';
import { cn } from '@/lib/utils';

type Props = {
    policy: PasswordPolicy;
    /** El atributo `passwordrules`, para que el gestor de claves sugiera una que sirva. */
    passwordRules: string;
    password: string;
    confirmation: string;
    onPasswordChange: (value: string) => void;
    onConfirmationChange: (value: string) => void;
    errors: { password?: string; password_confirmation?: string };
    passwordRef?: Ref<HTMLInputElement>;
    autoFocus?: boolean;
};

/**
 * La contraseña nueva y su confirmación, con los requisitos a la vista.
 *
 * Los requisitos se tildan mientras se escribe, y salen de la política que
 * manda el servidor (ver `password-policy.ts`): este componente no sabe
 * cuántos caracteres hacen falta, se lo dicen. Lo que solo el servidor
 * puede comprobar —que no sea la clave actual, que no figure en una
 * filtración— se anuncia aparte, como algo que se verifica al guardar,
 * para que no parezca un requisito que el usuario incumplió sin enterarse.
 *
 * Es controlado a propósito: el checklist lee el valor en cada tecla, y con
 * los campos sueltos de `<Form>` un reinicio parcial dejaba el checklist
 * mostrando una clave que ya no estaba en el campo.
 */
export default function NewPasswordFields({
    policy,
    passwordRules,
    password,
    confirmation,
    onPasswordChange,
    onConfirmationChange,
    errors,
    passwordRef,
    autoFocus = false,
}: Props) {
    const id = useId();
    const requirementsId = `${id}-requisitos`;
    const matchId = `${id}-coinciden`;

    const requirements = useMemo(
        () => evaluatePassword(policy, password),
        [policy, password],
    );
    const complete = meetsAll(requirements);
    const met = requirements.filter((r) => r.met).length;
    const matches = confirmation !== '' && confirmation === password;

    return (
        <div className="grid gap-5">
            <div className="grid gap-2">
                <Label htmlFor={`${id}-password`}>Nueva contraseña</Label>
                <PasswordInput
                    id={`${id}-password`}
                    ref={passwordRef}
                    name="password"
                    value={password}
                    onChange={(e) => onPasswordChange(e.target.value)}
                    autoComplete="new-password"
                    autoFocus={autoFocus}
                    passwordrules={passwordRules}
                    aria-describedby={requirementsId}
                    aria-invalid={Boolean(errors.password)}
                />
                <InputError message={errors.password} />

                <div
                    id={requirementsId}
                    className="mt-1 rounded-lg border bg-muted/40 p-3"
                >
                    {/*
                     * Un segmento por requisito: se lee de un vistazo cuánto
                     * falta sin tener que recorrer la lista. Es decorativo;
                     * lo que cuenta para el lector de pantalla es la lista.
                     */}
                    <div aria-hidden="true" className="mb-3 flex gap-1">
                        {requirements.map((r) => (
                            <span
                                key={r.key}
                                className={cn(
                                    'h-1 flex-1 rounded-full transition-colors duration-300',
                                    r.met
                                        ? complete
                                            ? 'bg-success'
                                            : 'bg-primary'
                                        : 'bg-border',
                                )}
                            />
                        ))}
                    </div>

                    <p className="sr-only">
                        Requisitos de la contraseña: {met} de{' '}
                        {requirements.length} cumplidos.
                    </p>

                    <ul className="grid gap-1.5 text-sm">
                        {requirements.map((r) => (
                            <RequirementItem key={r.key} requirement={r} />
                        ))}
                    </ul>

                    <p className="mt-3 flex items-start gap-2 border-t pt-3 text-xs text-muted-foreground">
                        <ServerCog
                            className="mt-px size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            Al guardar se verifica que sea distinta de la que
                            usás ahora
                            {policy.uncompromised
                                ? ' y que no figure en filtraciones conocidas'
                                : ''}
                            .
                        </span>
                    </p>
                </div>

                {/*
                 * Solo se anuncia el momento en que se completa: anunciar
                 * cada tecla convertiría el lector de pantalla en un
                 * contador que no deja escribir.
                 */}
                <p className="sr-only" aria-live="polite">
                    {complete && password !== ''
                        ? 'La contraseña cumple todos los requisitos.'
                        : ''}
                </p>
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`${id}-confirmation`}>
                    Repetí la contraseña
                </Label>
                <PasswordInput
                    id={`${id}-confirmation`}
                    name="password_confirmation"
                    value={confirmation}
                    onChange={(e) => onConfirmationChange(e.target.value)}
                    autoComplete="new-password"
                    passwordrules={passwordRules}
                    aria-describedby={matchId}
                    aria-invalid={Boolean(errors.password_confirmation)}
                />
                <InputError message={errors.password_confirmation} />

                {/*
                 * Mientras se escribe, «todavía no coinciden» es el estado
                 * normal, no un error: va en gris. El rojo queda para lo que
                 * responda el servidor.
                 */}
                <p
                    id={matchId}
                    aria-live="polite"
                    className={cn(
                        'flex min-h-5 items-center gap-1.5 text-sm transition-colors',
                        matches
                            ? 'text-success-strong'
                            : 'text-muted-foreground',
                    )}
                >
                    {confirmation !== '' &&
                        (matches ? (
                            <>
                                <Check
                                    className="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                Las dos coinciden.
                            </>
                        ) : (
                            'Todavía no coinciden.'
                        ))}
                </p>
            </div>
        </div>
    );
}

function RequirementItem({
    requirement,
}: {
    requirement: PasswordRequirement;
}) {
    const Icon = requirement.met ? Check : Circle;

    return (
        <li
            className={cn(
                'flex items-center gap-2 transition-colors duration-200',
                requirement.met ? 'text-foreground' : 'text-muted-foreground',
            )}
        >
            <span
                className={cn(
                    'flex size-4 shrink-0 items-center justify-center rounded-full transition-colors duration-200',
                    requirement.met
                        ? 'bg-success text-success-foreground'
                        : 'text-muted-foreground/70',
                )}
                aria-hidden="true"
            >
                <Icon
                    className={requirement.met ? 'size-3' : 'size-3.5'}
                    strokeWidth={requirement.met ? 3 : 2}
                />
            </span>
            <span>
                {requirement.label}
                <span className="sr-only">
                    {requirement.met ? ': cumplido' : ': pendiente'}
                </span>
            </span>
            {requirement.detail && !requirement.met && (
                <span
                    className="ml-auto text-xs text-muted-foreground tabular-nums"
                    aria-hidden="true"
                >
                    {requirement.detail}
                </span>
            )}
        </li>
    );
}
