import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavGroup, NavItem } from '@/types';

/**
 * Navegación principal, agrupada por circuito.
 *
 * Un ítem `disabled` se dibuja apagado y sin enlace: corresponde a un
 * módulo que el área espera pero que todavía no existe. Ocultarlo daría
 * a entender que se descartó; dejarlo enlazado llevaría a un 404.
 *
 * Un ítem con `children` se dibuja como acordeón —Caja y sus pantallas
 * hermanas—: el título despliega la lista, y arranca abierto si se está
 * parado en alguna de ellas.
 */
export function NavMain({ groups = [] }: { groups: NavGroup[] }) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <>
            {groups.map((group) => (
                <SidebarGroup key={group.label} className="px-2 py-0">
                    {/* En el acento de marca, igual que la bajada del
                        logo (ver AppLogo). El color va acá y no en
                        `SidebarGroupLabel`: ese componente es de shadcn y
                        se regenera; este es el único lugar que lo usa. */}
                    <SidebarGroupLabel className="text-sidebar-brand">
                        {group.label}
                    </SidebarGroupLabel>
                    <SidebarMenu>
                        {group.items.map((item) =>
                            item.children && item.children.length > 0 ? (
                                <ItemConHijos
                                    key={item.title}
                                    item={item}
                                    activo={isCurrentOrParentUrl}
                                />
                            ) : (
                                <ItemSimple
                                    key={item.title}
                                    item={item}
                                    activo={isCurrentOrParentUrl}
                                />
                            ),
                        )}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}

type Activo = (href: NonNullable<NavItem['href']>) => boolean;

function ItemSimple({ item, activo }: { item: NavItem; activo: Activo }) {
    return (
        <SidebarMenuItem>
            {item.disabled || !item.href ? (
                <SidebarMenuButton
                    disabled
                    aria-disabled="true"
                    className="cursor-default opacity-45"
                    tooltip={{
                        children:
                            item.disabledReason ??
                            `${item.title} — próximamente`,
                    }}
                >
                    {item.icon && <item.icon />}
                    <span>{item.title}</span>
                </SidebarMenuButton>
            ) : (
                <SidebarMenuButton
                    asChild
                    isActive={activo(item.href)}
                    tooltip={{ children: item.title }}
                >
                    <Link href={item.href} prefetch>
                        {item.icon && <item.icon />}
                        <span>{item.title}</span>
                    </Link>
                </SidebarMenuButton>
            )}

            {item.badge !== undefined && item.badge > 0 && (
                <SidebarMenuBadge className="text-sidebar-foreground">
                    {item.badge}
                </SidebarMenuBadge>
            )}
        </SidebarMenuItem>
    );
}

/**
 * Un ítem que agrupa a sus hermanas.
 *
 * El título es el disparador del acordeón y no un enlace: la primera
 * sub-pantalla —«Caja del día»— es la que lleva a donde iría el título, así
 * que hacerlo también navegar duplicaría el destino y volvería ambiguo qué
 * hace un clic. Arranca abierto si alguna hija está activa, para que no haya
 * que desplegarlo cada vez que se entra a una de ellas.
 *
 * La flecha resume el estado —cerrado apunta a la derecha, abierto hacia
 * abajo— y el `isActive` marca al padre cuando la pantalla actual es una de
 * sus hijas, para que el módulo se reconozca aun con el acordeón cerrado.
 */
function ItemConHijos({ item, activo }: { item: NavItem; activo: Activo }) {
    const hijos = item.children ?? [];
    const algunaActiva = hijos.some((hijo) => activo(hijo.href));

    return (
        <Collapsible
            asChild
            defaultOpen={algunaActiva}
            className="group/collapsible"
        >
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton
                        tooltip={{ children: item.title }}
                        isActive={algunaActiva}
                    >
                        {item.icon && <item.icon />}
                        <span>{item.title}</span>
                        <ChevronRight className="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90" />
                    </SidebarMenuButton>
                </CollapsibleTrigger>

                <CollapsibleContent>
                    <SidebarMenuSub>
                        {hijos.map((hijo) => (
                            <SidebarMenuSubItem key={hijo.title}>
                                <SidebarMenuSubButton
                                    asChild
                                    isActive={activo(hijo.href)}
                                >
                                    <Link href={hijo.href} prefetch>
                                        {hijo.icon && <hijo.icon />}
                                        <span>{hijo.title}</span>
                                    </Link>
                                </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                        ))}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}
