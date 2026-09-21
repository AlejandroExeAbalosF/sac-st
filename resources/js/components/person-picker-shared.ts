import { cuit, dni } from '@/lib/format';

export type PersonOption = {
    id: number;
    name: string;
    /** Falta cuando el expediente trajo solo el nombre. */
    document: string | null;
    type: PersonType;
    /** Titular de una organización, cuando está cargado. */
    ownerName?: string | null;
};

export type PersonBankAccountOption = {
    id: number;
    cbu: string;
    verificationStatus: 'unverified' | 'verified' | 'rejected';
    isActive: boolean;
};

export type PersonDraft = {
    type: PersonType;
    firstName: string;
    lastName: string;
    legalName: string;
    document: string;
    ownerDocument: string;
    ownerFirstName: string;
    ownerLastName: string;
    cbu: string;
};

export type OwnerLookup =
    | { estado: 'vacio' }
    | { estado: 'buscando' }
    | { estado: 'encontrado'; person: PersonOption }
    | { estado: 'nuevo' }
    | { estado: 'error'; message: string };

export type PersonRole = 'employer' | 'beneficiary';
export type PersonType = 'individual' | 'company';

/** Un DNI de 6 a 8 dígitos, o un CUIL de 11. */
export const esDocumentoBuscable = (digitos: string): boolean =>
    digitos.length === 11 || (digitos.length >= 6 && digitos.length <= 8);

export function personDocumentLabel(person: PersonOption): string {
    if (person.document === null) {
        return 'Sin documento';
    }

    return person.type === 'individual'
        ? `DNI ${dni(person.document)}`
        : `CUIT ${cuit(person.document)}`;
}
