import { download, preview } from '@/routes/adjuntos';

export type Attachment = App.Modules.Shared.Data.AttachmentData;
export type AttachmentKind = App.Modules.Shared.Enums.AttachmentKind;

/**
 * Lo que el visor puede mostrar: un archivo guardado o uno que el operador
 * acaba de elegir y todavía no subió.
 */
export type ViewerItem =
    | {
          source: 'remote';
          key: string;
          name: string;
          kind: AttachmentKind;
          sizeBytes: number;
          attachment: Attachment;
      }
    | {
          source: 'local';
          key: string;
          name: string;
          kind: AttachmentKind;
          sizeBytes: number;
          file: File;
      };

/**
 * Si el archivo se puede mirar en pantalla.
 *
 * Hacen falta las dos cosas: un formato que el navegador muestra —foto o
 * PDF— y permiso para pedirlo. Sin permiso no se pide nada: ni la
 * miniatura, que volvería con un 403.
 */
export function canPreview(attachment: Attachment): boolean {
    return attachment.canOpen && attachment.kind !== 'other';
}

export function remoteItem(attachment: Attachment): ViewerItem {
    return {
        source: 'remote',
        key: `adjunto-${attachment.id}`,
        name: attachment.title,
        kind: attachment.kind,
        sizeBytes: attachment.sizeBytes,
        attachment,
    };
}

/** El tipo de un archivo local, por su MIME; el servidor lo vuelve a leer del contenido. */
export function localKind(file: File): AttachmentKind {
    if (file.type === 'application/pdf') {
        return 'pdf';
    }

    return ['image/jpeg', 'image/png', 'image/webp'].includes(file.type)
        ? 'image'
        : 'other';
}

/*
 * La clave de un archivo local sale del objeto `File`, no de sus datos.
 *
 * Nombre, tamaño y fecha de modificación no lo identifican: dos PDF
 * distintos pueden coincidir en los tres, y la miniatura del primero
 * aparecía al elegir el segundo. El `WeakMap` no retiene archivos que ya
 * nadie usa.
 */
const localKeys = new WeakMap<File, string>();
let localSequence = 0;

function localKey(file: File): string {
    let key = localKeys.get(file);

    if (key === undefined) {
        localSequence += 1;
        key = `local-${localSequence}`;
        localKeys.set(file, key);
    }

    return key;
}

export function localItem(file: File): ViewerItem {
    return {
        source: 'local',
        key: localKey(file),
        name: file.name,
        kind: localKind(file),
        sizeBytes: file.size,
        file,
    };
}

export function previewUrl(attachment: Attachment): string {
    return preview(attachment.id).url;
}

export function downloadUrl(attachment: Attachment): string {
    return download(attachment.id).url;
}
