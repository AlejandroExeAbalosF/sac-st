<?php

declare(strict_types=1);

/*
| Mensajes de validación.
|
| El archivo completo del framework, traducido. Tiene que estar entero: una
| clave que falte se muestra tal cual —«validation.required»— y el operador se
| queda sin saber qué corregir.
|
| Dos reglas de redacción que no son de estilo sino de que el castellano
| funcione, porque Laravel no toca el texto que arma:
|
| 1. **`:attribute` nunca abre la frase.** Laravel no capitaliza el reemplazo,
|    así que un mensaje que empiece con él sale «la contraseña tiene que…».
|    Todos arrancan con una palabra fija.
| 2. **`:attribute` lleva su artículo** —«la contraseña», «el correo»— porque
|    es la única forma de que concuerde el género sin duplicar cada mensaje.
|    La contracara: nunca va precedido de «de» ni de «a», que con el artículo
|    darían «de el» y «a el». La preposición que se usa es «en».
|
| Y el tono es el del resto de la interfaz: voseo, y decir qué falta corregir
| en vez de reprochar lo que se cargó.
*/

return [

    'accepted' => 'Hay que aceptar :attribute.',
    'accepted_if' => 'Hay que aceptar :attribute cuando :other es :value.',
    'active_url' => 'Revisá :attribute: no es una URL válida.',
    'after' => 'Revisá :attribute: tiene que ser posterior a :date.',
    'after_or_equal' => 'Revisá :attribute: tiene que ser :date o posterior.',
    'alpha' => 'Revisá :attribute: solo admite letras.',
    'alpha_dash' => 'Revisá :attribute: solo admite letras, números, guiones y guiones bajos.',
    'alpha_num' => 'Revisá :attribute: solo admite letras y números.',
    'any_of' => 'Revisá :attribute: no es un valor válido.',
    'array' => 'Revisá :attribute: tiene que ser una lista.',
    'array_keys' => 'Faltan las claves :values en :attribute.',
    'ascii' => 'Revisá :attribute: solo admite caracteres y símbolos de un byte.',
    'base64' => 'Revisá :attribute: tiene que estar codificado en base64.',
    'before' => 'Revisá :attribute: tiene que ser anterior a :date.',
    'before_or_equal' => 'Revisá :attribute: tiene que ser :date o anterior.',

    'between' => [
        'array' => 'Poné entre :min y :max elementos en :attribute.',
        'file' => 'Revisá :attribute: tiene que pesar entre :min y :max kilobytes.',
        'numeric' => 'Poné un valor entre :min y :max en :attribute.',
        'string' => 'Escribí entre :min y :max caracteres en :attribute.',
    ],

    'boolean' => 'Revisá :attribute: tiene que ser sí o no.',
    'can' => 'Revisá :attribute: contiene un valor no permitido.',
    'confirmed' => 'La confirmación no coincide con :attribute.',
    'contains' => 'Falta un valor obligatorio en :attribute.',
    'current_password' => 'La contraseña no es correcta.',
    'date' => 'Revisá :attribute: no es una fecha válida.',
    'date_equals' => 'Revisá :attribute: tiene que ser :date.',
    'date_format' => 'Revisá :attribute: no tiene el formato :format.',
    'decimal' => 'Poné :decimal decimales en :attribute.',
    'declined' => 'Hay que rechazar :attribute.',
    'declined_if' => 'Hay que rechazar :attribute cuando :other es :value.',
    'different' => 'Tienen que ser distintos :attribute y :other.',
    'digits' => 'Escribí :digits dígitos en :attribute.',
    'digits_between' => 'Escribí entre :min y :max dígitos en :attribute.',
    'dimensions' => 'Revisá :attribute: las medidas de la imagen no son válidas.',
    'distinct' => 'Está repetido ese valor en :attribute.',
    'doesnt_contain' => 'No se admiten estos valores en :attribute: :values.',
    'doesnt_end_with' => 'No puede terminar con :values el valor cargado en :attribute.',
    'doesnt_start_with' => 'No puede empezar con :values el valor cargado en :attribute.',
    'email' => 'Revisá :attribute: no parece un correo válido.',
    'encoding' => 'Revisá :attribute: tiene que estar codificado en :encoding.',
    'ends_with' => 'Tiene que terminar con :values el valor cargado en :attribute.',
    'enum' => 'Revisá :attribute: no es un valor admitido.',
    'exists' => 'Revisá :attribute: no existe.',
    'extensions' => 'Solo se admiten archivos :values en :attribute.',
    'file' => 'Revisá :attribute: tiene que ser un archivo.',
    'filled' => 'Falta :attribute.',

    'gt' => [
        'array' => 'Poné más de :value elementos en :attribute.',
        'file' => 'Revisá :attribute: tiene que pesar más de :value kilobytes.',
        'numeric' => 'Poné un valor mayor que :value en :attribute.',
        'string' => 'Escribí más de :value caracteres en :attribute.',
    ],

    'gte' => [
        'array' => 'Poné :value elementos o más en :attribute.',
        'file' => 'Revisá :attribute: tiene que pesar :value kilobytes o más.',
        'numeric' => 'Poné un valor de :value o mayor en :attribute.',
        'string' => 'Escribí :value caracteres o más en :attribute.',
    ],

    'hex_color' => 'Revisá :attribute: no es un color hexadecimal válido.',
    'image' => 'Revisá :attribute: tiene que ser una imagen.',
    'in' => 'Revisá :attribute: no es un valor admitido.',
    'in_array' => 'Revisá :attribute: no figura en :other.',
    'in_array_keys' => 'Falta al menos una de estas claves en :attribute: :values.',
    'integer' => 'Escribí un número entero en :attribute.',
    'ip' => 'Revisá :attribute: no es una dirección IP válida.',
    'ipv4' => 'Revisá :attribute: no es una dirección IPv4 válida.',
    'ipv6' => 'Revisá :attribute: no es una dirección IPv6 válida.',
    'json' => 'Revisá :attribute: tiene que ser un JSON válido.',
    'list' => 'Revisá :attribute: tiene que ser una lista.',
    'lowercase' => 'Escribí en minúsculas :attribute.',

    'lt' => [
        'array' => 'Poné menos de :value elementos en :attribute.',
        'file' => 'Revisá :attribute: tiene que pesar menos de :value kilobytes.',
        'numeric' => 'Poné un valor menor que :value en :attribute.',
        'string' => 'Escribí menos de :value caracteres en :attribute.',
    ],

    'lte' => [
        'array' => 'Poné :value elementos o menos en :attribute.',
        'file' => 'Revisá :attribute: tiene que pesar :value kilobytes o menos.',
        'numeric' => 'Poné un valor de :value o menor en :attribute.',
        'string' => 'Escribí :value caracteres o menos en :attribute.',
    ],

    'mac_address' => 'Revisá :attribute: no es una dirección MAC válida.',

    'max' => [
        'array' => 'Poné como máximo :max elementos en :attribute.',
        'file' => 'Revisá :attribute: no puede pesar más de :max kilobytes.',
        'numeric' => 'Poné un valor de :max o menor en :attribute.',
        'string' => 'Escribí como máximo :max caracteres en :attribute.',
    ],

    'max_digits' => 'Escribí como máximo :max dígitos en :attribute.',
    'mimes' => 'Solo se admiten archivos :values en :attribute.',
    'mimetypes' => 'Solo se admiten archivos :values en :attribute.',

    'min' => [
        'array' => 'Poné al menos :min elementos en :attribute.',
        'file' => 'Revisá :attribute: tiene que pesar al menos :min kilobytes.',
        'numeric' => 'Poné un valor de :min o mayor en :attribute.',
        'string' => 'Escribí al menos :min caracteres en :attribute.',
    ],

    'min_digits' => 'Escribí al menos :min dígitos en :attribute.',
    'missing' => 'No corresponde cargar :attribute.',
    'missing_if' => 'No corresponde cargar :attribute cuando :other es :value.',
    'missing_unless' => 'No corresponde cargar :attribute salvo que :other sea :value.',
    'missing_with' => 'No corresponde cargar :attribute junto con :values.',
    'missing_with_all' => 'No corresponde cargar :attribute junto con :values.',
    'multiple_of' => 'Poné un múltiplo de :value en :attribute.',
    'not_in' => 'Revisá :attribute: no es un valor admitido.',
    'not_regex' => 'Revisá :attribute: no tiene un formato válido.',
    'numeric' => 'Escribí un número en :attribute.',

    'password' => [
        'letters' => 'La contraseña tiene que tener al menos una letra.',
        'mixed' => 'La contraseña tiene que combinar mayúsculas y minúsculas.',
        'numbers' => 'La contraseña tiene que tener al menos un número.',
        'symbols' => 'La contraseña tiene que tener al menos un símbolo.',
        /*
         * Solo se comprueba en producción, contra bases de filtraciones
         * conocidas. Ver `Password::defaults()` en AppServiceProvider.
         */
        'uncompromised' => 'Esa contraseña apareció en una filtración conocida. Elegí otra.',
    ],

    'present' => 'Falta :attribute.',
    'present_if' => 'Falta :attribute cuando :other es :value.',
    'present_unless' => 'Falta :attribute salvo que :other sea :value.',
    'present_with' => 'Falta :attribute cuando está :values.',
    'present_with_all' => 'Falta :attribute cuando están :values.',
    'prohibited' => 'No está permitido cargar :attribute.',
    'prohibited_if' => 'No está permitido cargar :attribute cuando :other es :value.',
    'prohibited_if_accepted' => 'No está permitido cargar :attribute cuando se acepta :other.',
    'prohibited_if_declined' => 'No está permitido cargar :attribute cuando se rechaza :other.',
    'prohibited_unless' => 'No está permitido cargar :attribute salvo que :other esté entre :values.',
    'prohibits' => 'No se puede cargar :other junto con :attribute.',
    'regex' => 'Revisá :attribute: no tiene un formato válido.',
    'required' => 'Falta :attribute.',
    'required_array_keys' => 'Faltan las claves :values en :attribute.',
    'required_if' => 'Falta :attribute cuando :other es :value.',
    'required_if_accepted' => 'Falta :attribute cuando se acepta :other.',
    'required_if_declined' => 'Falta :attribute cuando se rechaza :other.',
    'required_unless' => 'Falta :attribute salvo que :other esté entre :values.',
    'required_with' => 'Falta :attribute cuando está :values.',
    'required_with_all' => 'Falta :attribute cuando están :values.',
    'required_without' => 'Falta :attribute cuando no está :values.',
    'required_without_all' => 'Falta :attribute cuando no está ninguno de :values.',
    'same' => 'Tienen que coincidir :attribute y :other.',

    'size' => [
        'array' => 'Poné :size elementos en :attribute.',
        'file' => 'Revisá :attribute: tiene que pesar :size kilobytes.',
        'numeric' => 'Poné el valor :size en :attribute.',
        'string' => 'Escribí :size caracteres en :attribute.',
    ],

    'starts_with' => 'Tiene que empezar con :values el valor cargado en :attribute.',
    'string' => 'Revisá :attribute: tiene que ser texto.',
    'timezone' => 'Revisá :attribute: no es una zona horaria válida.',
    'unique' => 'Ya está en uso ese valor en :attribute.',
    'uploaded' => 'No se pudo subir :attribute.',
    'uppercase' => 'Escribí en mayúsculas :attribute.',
    'url' => 'Revisá :attribute: no es una URL válida.',
    'ulid' => 'Revisá :attribute: no es un ULID válido.',
    'uuid' => 'Revisá :attribute: no es un UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Mensajes por campo
    |--------------------------------------------------------------------------
    |
    | Va acá lo que necesita decirse distinto en un campo puntual. Ojo: un
    | FormRequest con `messages()` propio manda sobre esto, y es el lugar
    | preferido cuando la regla es de un solo formulario.
    |
    */

    'custom' => [
        'current_password' => [
            'current_password' => 'La contraseña actual no es correcta.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nombres legibles de los campos
    |--------------------------------------------------------------------------
    |
    | Reemplazan al `:attribute` de los mensajes de arriba. Sin esto, un
    | formulario diría «Falta document number».
    |
    | Van **con artículo**, que es lo que resuelve el género. Solo los que
    | aparecen en más de una pantalla: lo que es de un formulario se declara en
    | el `attributes()` de su FormRequest, al lado de sus reglas.
    |
    */

    'attributes' => [
        'username' => 'el nombre de usuario',
        'password' => 'la contraseña',
        'password_confirmation' => 'la confirmación de la contraseña',
        'current_password' => 'la contraseña actual',
        'email' => 'el correo electrónico',
        'name' => 'el nombre',
        'documentNumber' => 'el DNI',
        'document_number' => 'el DNI',
        'position' => 'el cargo',
        'role' => 'el rol',
        'permissions' => 'los permisos',
        'reason' => 'el motivo',
        'code' => 'el código',
        'recovery_code' => 'el código de recuperación',

        /*
         * Campos del dominio contable.
         *
         * Un FormRequest puede definir sus propios `attributes()` cuando el
         * nombre depende de la pantalla; esto es la red para los que no lo
         * hacen. Sin esta lista el mensaje sale con el nombre de la columna
         * —«El campo cbuFolio no debe ser mayor que 40 caracteres»— y una
         * columna es exactamente lo que un contador no tiene por qué leer.
         *
         * Las palabras son las que usa la interfaz: «empleador» y no
         * «depositante», que es el desvío ya aplicado sobre el DER.
         */
        'amount' => 'el importe',
        'bankTransactionId' => 'el movimiento bancario',
        'beneficiaryAddress' => 'el domicilio del beneficiario',
        'beneficiaryBankAccountId' => 'la cuenta del beneficiario',
        'beneficiaryDocument' => 'el documento del beneficiario',
        'beneficiaryPhone' => 'el teléfono del beneficiario',
        'cashBoxId' => 'la caja',
        'cbuFolio' => 'el folio del CBU',
        'custodyStartDate' => 'la fecha de inicio de la custodia',
        'depositorId' => 'el empleador',
        'employerAddress' => 'el domicilio del empleador',
        'employerDocument' => 'el documento del empleador',
        'employerPhone' => 'el teléfono del empleador',
        'idempotencyKey' => 'la clave de la operación',
        'incomeReceiptNumberSource' => 'el origen del número de recibo',
        'installmentId' => 'la cuota',
        'notes' => 'las observaciones',
        'paseDestination' => 'el destino del pase',
        'paseNotes' => 'las observaciones del pase',
        'permissions.*' => 'los permisos',
        'q' => 'la búsqueda',
        'treasurerId' => 'el tesorero',
        'type' => 'el tipo',
    ],

];
