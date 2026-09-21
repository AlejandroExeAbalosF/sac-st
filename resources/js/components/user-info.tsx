import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import type { User } from '@/types';

export function UserInfo({
    user,
    showEmail = false,
    role = null,
}: {
    user: User;
    showEmail?: boolean;
    /** Rol principal; se muestra bajo el nombre cuando se pasa. */
    role?: string | null;
}) {
    const getInitials = useInitials();

    return (
        <>
            <Avatar className="h-8 w-8 overflow-hidden rounded-full">
                <AvatarImage src={user.avatar} alt={user.name} />
                {/*
                 * Azul de marca y no el de la barra: desde que la cuenta vive
                 * en el encabezado, este avatar solo se dibuja sobre fondo
                 * claro —el menú desplegable—, y tiene que ser el mismo color
                 * que el del disparador que lo abrió.
                 */}
                <AvatarFallback className="rounded-full bg-primary text-primary-foreground">
                    {getInitials(user.name)}
                </AvatarFallback>
            </Avatar>
            <div className="grid flex-1 text-left text-sm leading-tight">
                <span className="truncate font-medium">{user.name}</span>
                {role && (
                    <span className="truncate text-xs text-muted-foreground capitalize">
                        {role}
                    </span>
                )}
                {showEmail && user.email && (
                    <span className="truncate text-xs text-muted-foreground">
                        {user.email}
                    </span>
                )}
            </div>
        </>
    );
}
