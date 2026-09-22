import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Banknote,
    CalendarDays,
    ClipboardCheck,
    Contact,
    FileSpreadsheet,
    FileText,
    History,
    Landmark,
    LayoutGrid,
    Lock,
    Scale,
    ScrollText,
    Settings,
    ShieldCheck,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import {
    Sidebar,
    SidebarContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { inicio } from '@/routes';
import { index as cuentasBancarias } from '@/routes/banco/cuentas';
import { index as extractos } from '@/routes/banco/extractos';
import { index as movimientos } from '@/routes/banco/movimientos';
import { calendario, dia as caja } from '@/routes/caja';
import { index as arqueos } from '@/routes/caja/arqueos';
import { index as cierres } from '@/routes/caja/cierres';
import { index as accesos } from '@/routes/configuracion/accesos';
import { index as auditoria } from '@/routes/configuracion/auditoria';
import { index as roles } from '@/routes/configuracion/roles';
import { index as usuarios } from '@/routes/configuracion/usuarios';
import { index as haberes } from '@/routes/expedientes';
import { index as personas } from '@/routes/personas';
import { index as planillas } from '@/routes/planillas';
import type { Auth, NavGroup, NavItem, NavLink } from '@/types';

/*
 * La navegación sigue el recorrido del dinero, no el esquema de la base:
 * el expediente entra, el dinero se recibe, se identifica contra el banco,
 * se paga y se cierra la caja. Un operador que sigue el menú de arriba hacia
 * abajo está siguiendo el circuito real. Los módulos futuros se incorporan
 * cuando estén disponibles para que la navegación describa acciones reales.
 */
const navGroups: NavGroup[] = [
    {
        label: 'Operación',
        items: [
            { title: 'Inicio', href: inicio(), icon: LayoutGrid },
            { title: 'Haberes', href: haberes(), icon: FileText },
            /*
             * «Recepciones» no está en el menú — desvío 49.
             *
             * Un depósito trae una sola cuota: el comprobante se cruza con
             * su crédito y «registrar y asignar» lo resuelve desde la
             * cuota. La pantalla sigue existiendo para repartir una
             * recepción entre varias cuotas —el caso extremo que el
             * relevamiento previó y que el área no tuvo—, y se llega desde
             * «repartirlo a mano» de la cuota.
             */
            /*
             * Entra por «Movimientos» y no por «Extractos»: importar es
             * el medio, y lo que el operador viene a hacer es mirar qué
             * entró al banco. Los extractos y las cuentas cuelgan de esa
             * pantalla.
             */
            { title: 'Banco', href: movimientos(), icon: ArrowLeftRight },
            { title: 'Extractos', href: extractos(), icon: FileSpreadsheet },
            /*
             * «Se paga»: las dos colas del egreso —lo que está listo para
             * entregarse en mano y lo que salió por el banco y todavía nadie
             * dio por hecho—. Va acá y no junto a Haberes porque ese es el
             * lugar que ocupa en el recorrido del dinero.
             *
             * El área lo llama «Planillas», que es lo que viene a buscar:
             * la hoja para trabajar la fila. El permiso sigue siendo
             * `egresos.registrar` porque nombra el acto, no la pantalla.
             *
             * Es el único ítem de este grupo con permiso: el rol de consulta
             * mira expedientes y caja, pero una cola de trabajo es de quien
             * la trabaja.
             *
             * Sin contador a propósito. La barra no se remonta al navegar,
             * así que un número acá no se refresca solo y habría que
             * compartirlo desde `HandleInertiaRequests` en cada respuesta del
             * sistema. Los contadores que el área usa viven en el tablero.
             */
            {
                title: 'Planillas',
                href: planillas(),
                icon: Banknote,
                permission: 'egresos.registrar',
            },
            /*
             * Y acá cierra el recorrido: contar el cajón y cerrar el día.
             * Va último porque es lo último que pasa, no porque importe
             * menos. Es un acordeón porque el módulo tiene varias pantallas
             * hermanas —el día, los arqueos, los cierres, el calendario— y
             * hasta ahora se saltaba entre ellas por botones adentro de
             * cada una. La primera sub-pantalla lleva a donde iba «Caja».
             */
            {
                title: 'Caja',
                icon: Scale,
                children: [
                    { title: 'Caja del día', href: caja(), icon: Scale },
                    {
                        title: 'Arqueos',
                        href: arqueos(),
                        icon: ClipboardCheck,
                    },
                    { title: 'Cierres', href: cierres(), icon: Lock },
                    {
                        title: 'Calendario',
                        href: calendario(),
                        icon: CalendarDays,
                    },
                ],
            },
        ],
    },
    {
        label: 'Administración',
        items: [
            { title: 'Personas', href: personas(), icon: Contact },
            {
                title: 'Cuentas bancarias',
                href: cuentasBancarias(),
                icon: Landmark,
            },
            /*
             * «Configuración» dejó de apuntar al perfil propio. Es un
             * acordeón, igual que Caja, porque agrupa varias pantallas
             * hermanas y su título no es ninguna de ellas; la cuenta
             * personal se movió al menú del encabezado, que es donde el
             * operador la busca.
             */
            {
                title: 'Configuración',
                icon: Settings,
                children: [
                    {
                        title: 'Usuarios',
                        href: usuarios(),
                        icon: Users,
                        permission: 'usuarios.ver',
                    },
                    {
                        title: 'Roles',
                        href: roles(),
                        icon: ShieldCheck,
                        permission: 'roles.gestionar',
                    },
                    {
                        title: 'Accesos y sesiones',
                        href: accesos(),
                        icon: History,
                        permission: 'auditoria.accesos.ver',
                    },
                    {
                        title: 'Auditoría',
                        href: auditoria(),
                        icon: ScrollText,
                        permission: 'auditoria.operaciones.ver',
                    },
                ],
            },
        ],
    },
];

/**
 * Deja fuera las sub-pantallas que el usuario no puede abrir, y el grupo
 * entero si no le queda ninguna.
 *
 * Un ítem `disabled` sirve para un módulo que todavía no existe: el área
 * sabe que viene y ocultarlo haría pensar que se descartó. Una pantalla
 * que existe pero no le corresponde a este usuario es otra cosa, y
 * mostrarla apagada solo ofrece una puerta que termina en un 403.
 */
function visiblesPara(
    grupos: NavGroup[],
    can: Record<string, boolean>,
): NavGroup[] {
    const permitido = (item: NavItem | NavLink): boolean =>
        item.permission === undefined || can[item.permission] === true;

    return grupos
        .map((grupo) => ({
            ...grupo,
            items: grupo.items
                .map((item) =>
                    item.children === undefined
                        ? item
                        : {
                              ...item,
                              children: item.children.filter(permitido),
                          },
                )
                .filter(
                    (item) =>
                        permitido(item) &&
                        (item.children === undefined ||
                            item.children.length > 0),
                ),
        }))
        .filter((grupo) => grupo.items.length > 0);
}

export function AppSidebar() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const grupos = visiblesPara(navGroups, auth.can ?? {});

    /*
     * Variante `sidebar` y no `inset`: el diseño del área tiene la barra a
     * ras del borde y el contenido apoyado sobre el fondo claro. `inset`
     * enmarcaba toda la pantalla con el azul de la barra, lo que le daba
     * aspecto de tablero oscuro y desperdiciaba ancho.
     */
    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={inicio()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            {/*
             * Sin pie: la cuenta se mudó al encabezado (ver UserMenu). La
             * barra queda siendo solo navegación, que es lo único que le
             * corresponde.
             */}
            <SidebarContent className="gap-4">
                <NavMain groups={grupos} />
            </SidebarContent>
        </Sidebar>
    );
}
