import { usePage } from '@inertiajs/react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';

/**
 * La cuenta, en el extremo derecho del encabezado.
 *
 * Solo el avatar: el nombre, el rol y el correo ya están en el menú que se
 * abre, y repetirlos arriba le come ancho a las migas de pan, que son lo que
 * ubica al operador dentro del circuito.
 *
 * El menú en sí es el mismo `UserMenuContent` que usaba la barra lateral; lo
 * único que cambió es de dónde cuelga.
 */
export function UserMenu() {
    const { auth } = usePage().props;
    const getInitials = useInitials();

    if (!auth.user) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-9 rounded-full p-0"
                    data-test="user-menu-button"
                >
                    <Avatar className="size-8 overflow-hidden rounded-full">
                        <AvatarImage
                            src={auth.user.avatar}
                            alt={auth.user.name}
                        />
                        <AvatarFallback className="rounded-full bg-primary text-xs font-medium text-primary-foreground">
                            {getInitials(auth.user.name)}
                        </AvatarFallback>
                    </Avatar>
                    <span className="sr-only">Abrir el menú de la cuenta</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-56 rounded-lg">
                <UserMenuContent user={auth.user} role={auth.role} />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
