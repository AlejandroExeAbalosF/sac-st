import { Command as CommandPrimitive } from 'cmdk';
import { Search } from 'lucide-react';
import * as React from 'react';
import { cn } from '@/lib/utils';

function Command({
    className,
    ...props
}: React.ComponentProps<typeof CommandPrimitive>) {
    return (
        <CommandPrimitive
            data-slot="command"
            className={cn(
                'flex h-full w-full flex-col overflow-hidden rounded-lg bg-popover text-popover-foreground',
                className,
            )}
            {...props}
        />
    );
}

function CommandInput({
    className,
    ...props
}: React.ComponentProps<typeof CommandPrimitive.Input>) {
    return (
        <div className="flex h-11 items-center gap-2 border-b px-3">
            <Search
                className="size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <CommandPrimitive.Input
                data-slot="command-input"
                className={cn(
                    'h-10 w-full bg-transparent text-sm outline-hidden placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50',
                    className,
                )}
                {...props}
            />
        </div>
    );
}

function CommandList({
    className,
    ...props
}: React.ComponentProps<typeof CommandPrimitive.List>) {
    return (
        <CommandPrimitive.List
            data-slot="command-list"
            className={cn(
                'max-h-72 scroll-py-1 overflow-x-hidden overflow-y-auto p-1',
                className,
            )}
            {...props}
        />
    );
}

function CommandGroup({
    className,
    ...props
}: React.ComponentProps<typeof CommandPrimitive.Group>) {
    return (
        <CommandPrimitive.Group
            data-slot="command-group"
            className={cn('overflow-hidden', className)}
            {...props}
        />
    );
}

function CommandItem({
    className,
    ...props
}: React.ComponentProps<typeof CommandPrimitive.Item>) {
    return (
        <CommandPrimitive.Item
            data-slot="command-item"
            className={cn(
                'relative flex min-h-10 cursor-default items-center gap-2 rounded-md px-2.5 py-2 text-sm outline-hidden select-none',
                'data-[selected=true]:bg-accent data-[selected=true]:text-accent-foreground data-[disabled=true]:pointer-events-none data-[disabled=true]:opacity-50',
                className,
            )}
            {...props}
        />
    );
}

export { Command, CommandGroup, CommandInput, CommandItem, CommandList };
