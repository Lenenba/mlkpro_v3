<?php

namespace App\Support;

class PublicProductPageLocalizedOverrides
{
    /**
     * @return array<string, mixed>
     */
    public static function for(string $slug, string $locale): array
    {
        $resolvedLocale = PublicPageStockImages::normalizeLocale($locale);

        if ($resolvedLocale !== 'es') {
            return [];
        }

        return match ($slug) {
            'sales-crm' => self::salesCrmSpanish(),
            'reservations' => self::reservationsSpanish(),
            'operations' => self::operationsSpanish(),
            'commerce' => self::commerceSpanish(),
            'marketing-loyalty' => self::marketingLoyaltySpanish(),
            'ai-automation' => self::aiAutomationSpanish(),
            'command-center' => self::commandCenterSpanish(),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function salesCrmSpanish(): array
    {
        return [
            '0.kicker' => 'Un sistema para todo el ciclo comercial',
            '0.title' => 'Convierte la demanda entrante en trabajo aprobado con menos friccion',
            '0.body' => self::html('Sales & CRM conecta la captura de solicitudes, la calificacion, los presupuestos, el contexto del cliente y el seguimiento comercial para que el equipo avance mas rapido sin perder el siguiente paso.'),
            '0.feature_tabs.0.label' => 'Captar demanda',
            '0.feature_tabs.0.role' => 'Adquisicion y primera respuesta',
            '0.feature_tabs.0.title' => 'Facilita que los prospectos correctos lleguen a tu pipeline',
            '0.feature_tabs.0.body' => self::html('Reune formularios entrantes, solicitudes web, resenas y primeros mensajes en una misma capa de captacion para que la demanda llegue mas limpia y siga visible.'),
            '0.feature_tabs.0.story' => self::html('Las primeras interacciones se mantienen mas claras desde que entra la solicitud, lo que facilita la calificacion comercial y reduce la perdida de contexto.'),
            '0.feature_tabs.0.metric' => 'Una entrada mas clara al pipeline',
            '0.feature_tabs.0.person' => 'Equipo de adquisicion',
            '0.feature_tabs.0.cta_label' => 'Explorar Marketing y fidelizacion',
            '0.feature_tabs.0.avatar_alt' => 'Retrato del equipo de crecimiento',
            '0.feature_tabs.0.children.0.label' => 'Formularios entrantes',
            '0.feature_tabs.0.children.0.title' => 'Haz que la demanda entre con el nivel de detalle adecuado',
            '0.feature_tabs.0.children.0.body' => self::html('Centraliza formularios y solicitudes web para que se pierda menos informacion desde el primer contacto.'),
            '0.feature_tabs.0.children.0.cta_label' => 'Ver captura de solicitudes',
            '0.feature_tabs.0.children.1.label' => 'Primeras respuestas',
            '0.feature_tabs.0.children.1.title' => 'Responde mas rapido a las nuevas solicitudes',
            '0.feature_tabs.0.children.1.body' => self::html('Manten tiempos de primera respuesta cortos para que tu empresa se perciba reactiva desde la primera interaccion.'),
            '0.feature_tabs.0.children.1.cta_label' => 'Ver respuesta rapida',
            '0.feature_tabs.0.children.2.label' => 'Resenas y reputacion',
            '0.feature_tabs.0.children.2.title' => 'Refuerza la confianza incluso antes de enviar el presupuesto',
            '0.feature_tabs.0.children.2.body' => self::html('Usa resenas y senales de reputacion para convertir mejor la demanda ya cualificada.'),
            '0.feature_tabs.0.children.2.cta_label' => 'Ver reputacion',
            '0.feature_tabs.0.children.3.label' => 'Enlaces compartibles',
            '0.feature_tabs.0.children.3.title' => 'Haz que tu oferta y tus puntos de entrada sean mas faciles de compartir',
            '0.feature_tabs.0.children.3.body' => self::html('Comparte formularios, paginas y enlaces utiles de forma mas clara para activar con mas facilidad recomendaciones y boca a boca.'),
            '0.feature_tabs.0.children.3.cta_label' => 'Ver enlaces',
            '0.feature_tabs.1.label' => 'Presupuesto y seguimiento',
            '0.feature_tabs.1.role' => 'Calificacion, presupuesto y seguimiento',
            '0.feature_tabs.1.title' => 'Pasa mas rapido de la solicitud al presupuesto sin perder el contexto del cliente',
            '0.feature_tabs.1.body' => self::html('Califica la solicitud, prepara el presupuesto, agrega opciones y manten el seguimiento desde un mismo espacio comercial en lugar de repartir la informacion entre notas y bandejas de entrada.'),
            '0.feature_tabs.1.story' => self::html('Los presupuestos salen mas rapido, las opciones siguen coherentes y el seguimiento deja de depender de recordatorios manuales dispersos.'),
            '0.feature_tabs.1.metric' => 'Presupuestos mas claros y mejor seguidos',
            '0.feature_tabs.1.person' => 'Equipo comercial',
            '0.feature_tabs.1.cta_label' => 'Explorar Ventas y CRM',
            '0.feature_tabs.1.children.0.label' => 'Captura y calificacion',
            '0.feature_tabs.1.children.0.title' => 'Haz que la solicitud entre con el nivel de detalle adecuado',
            '0.feature_tabs.1.children.0.body' => self::html('Agrega puntos de entrada simples que ayuden al equipo a calificar la necesidad antes y a orientar mas rapido el siguiente paso.'),
            '0.feature_tabs.1.children.0.cta_label' => 'Ver captura de leads',
            '0.feature_tabs.1.children.1.label' => 'Plantillas de presupuesto',
            '0.feature_tabs.1.children.1.title' => 'Envia presupuestos coherentes en menos tiempo',
            '0.feature_tabs.1.children.1.body' => self::html('Precarga servicios, precios y opciones frecuentes para que el equipo envie propuestas claras sin reconstruirlas cada vez.'),
            '0.feature_tabs.1.children.1.cta_label' => 'Ver plantillas de presupuesto',
            '0.feature_tabs.1.children.2.label' => 'Opciones y extras',
            '0.feature_tabs.1.children.2.title' => 'Agrega mas valor sin volver pesado el presupuesto',
            '0.feature_tabs.1.children.2.body' => self::html('Agrega opciones, extras y servicios complementarios para reforzar la propuesta comercial sin rehacer trabajo manual.'),
            '0.feature_tabs.1.children.2.cta_label' => 'Ver opciones de presupuesto',
            '0.feature_tabs.1.children.3.label' => 'Seguimientos visibles',
            '0.feature_tabs.1.children.3.title' => 'Da seguimiento en el momento correcto sin perder oportunidades',
            '0.feature_tabs.1.children.3.body' => self::html('Manten recordatorios y seguimientos ligados a la misma oportunidad para que la siguiente accion comercial siga siendo obvia.'),
            '0.feature_tabs.1.children.3.cta_label' => 'Ver seguimientos',
            '0.feature_tabs.2.label' => 'Coordinar la ejecucion',
            '0.feature_tabs.2.role' => 'Paso a operaciones',
            '0.feature_tabs.2.title' => 'Entrega el trabajo aprobado a operaciones con menos confusion',
            '0.feature_tabs.2.body' => self::html('Una vez aprobada la oportunidad, la planificacion, los detalles del trabajo, las asignaciones y la ejecucion en campo pueden continuar desde el mismo contexto operativo.'),
            '0.feature_tabs.2.story' => self::html('El paso de oficina a campo se mantiene mas limpio porque el contexto del cliente, los detalles del trabajo y los siguientes pasos viajan juntos.'),
            '0.feature_tabs.2.metric' => 'Un mejor paso de ventas a la ejecucion',
            '0.feature_tabs.2.person' => 'Equipo de operaciones',
            '0.feature_tabs.2.cta_label' => 'Explorar Operaciones',
            '0.feature_tabs.2.children.0.label' => 'Planificacion',
            '0.feature_tabs.2.children.0.title' => 'Conserva el contexto correcto cuando el trabajo entra en agenda',
            '0.feature_tabs.2.children.0.body' => self::html('Pasa el trabajo aprobado a la planificacion sin perder los detalles que importan para la ejecucion.'),
            '0.feature_tabs.2.children.0.cta_label' => 'Ver planificacion',
            '0.feature_tabs.2.children.1.label' => 'Asignacion de equipo',
            '0.feature_tabs.2.children.1.title' => 'Asigna el trabajo correcto al equipo correcto',
            '0.feature_tabs.2.children.1.body' => self::html('Manten la informacion adecuada visible para asignar mas rapido y con menos idas y vueltas.'),
            '0.feature_tabs.2.children.1.cta_label' => 'Ver despacho',
            '0.feature_tabs.2.children.2.label' => 'Ejecucion en campo',
            '0.feature_tabs.2.children.2.title' => 'Llega al sitio con una lectura mas clara del trabajo',
            '0.feature_tabs.2.children.2.body' => self::html('Manten estados, contexto del cliente y puntos de atencion conectados al mismo flujo cuando el equipo ya esta en el lugar.'),
            '0.feature_tabs.2.children.2.cta_label' => 'Ver ejecucion en campo',
            '0.feature_tabs.2.children.3.label' => 'Historial del cliente',
            '0.feature_tabs.2.children.3.title' => 'Recupera el contexto completo antes de cada visita',
            '0.feature_tabs.2.children.3.body' => self::html('Manten notas, fotos, solicitudes y trabajos anteriores en un mismo lugar para que el equipo llegue preparado al sitio del cliente.'),
            '0.feature_tabs.2.children.3.cta_label' => 'Ver fichas de cliente',
            '0.feature_tabs.3.label' => 'Proteger ingresos',
            '0.feature_tabs.3.role' => 'Facturacion, pagos e ingresos',
            '0.feature_tabs.3.title' => 'Convierte el trabajo aprobado en facturacion y pagos con mas visibilidad',
            '0.feature_tabs.3.body' => self::html('Manten la facturacion, los recordatorios, el cobro y el seguimiento de ingresos conectados con la solicitud original para que el ciclo comercial termine de forma limpia.'),
            '0.feature_tabs.3.story' => self::html('El final del ciclo se mantiene mas claro cuando facturas, recordatorios y pagos siguen ligados al mismo trabajo aprobado.'),
            '0.feature_tabs.3.metric' => 'Mejor visibilidad de ingresos',
            '0.feature_tabs.3.person' => 'Equipo financiero',
            '0.feature_tabs.3.cta_label' => 'Explorar Comercio',
            '0.feature_tabs.3.children.0.label' => 'Facturacion',
            '0.feature_tabs.3.children.0.title' => 'Haz que la factura salga mas rapido despues de la aprobacion',
            '0.feature_tabs.3.children.0.body' => self::html('Genera la factura sin volver a escribir informacion que ya existe en el flujo comercial y operativo.'),
            '0.feature_tabs.3.children.0.cta_label' => 'Ver facturacion',
            '0.feature_tabs.3.children.1.label' => 'Pagos',
            '0.feature_tabs.3.children.1.title' => 'Acorta el tiempo entre trabajo completado y cobro',
            '0.feature_tabs.3.children.1.body' => self::html('Manten el cobro ligado al trabajo aprobado para reducir retrasos y pasos olvidados.'),
            '0.feature_tabs.3.children.1.cta_label' => 'Ver pagos',
            '0.feature_tabs.3.children.2.label' => 'Recordatorios',
            '0.feature_tabs.3.children.2.title' => 'Haz seguimiento sin dispersar el proceso',
            '0.feature_tabs.3.children.2.body' => self::html('Manten los recordatorios de pago visibles en el mismo flujo para que el siguiente paso siga claro.'),
            '0.feature_tabs.3.children.2.cta_label' => 'Ver recordatorios',
            '0.feature_tabs.3.children.3.label' => 'Visibilidad de ingresos',
            '0.feature_tabs.3.children.3.title' => 'Manten una lectura mas clara de lo que se ha cobrado',
            '0.feature_tabs.3.children.3.body' => self::html('Conecta los ingresos y el cierre del trabajo para entender mejor que avanza limpiamente dentro del negocio.'),
            '0.feature_tabs.3.children.3.cta_label' => 'Ver visibilidad de ingresos',
            '1.kicker' => 'Listo para estructurar la conversion',
            '1.title' => 'Empieza a convertir mas de la demanda que ya generas',
            '1.body' => self::html('Reemplaza una captura de solicitudes, presupuestos y seguimientos fragmentados por un mismo espacio comercial que ayuda a tu equipo a avanzar mas rapido, mantenerse mas coherente y gestionar mejor el paso del primer contacto al trabajo aprobado.'),
            '1.primary_label' => 'Ver la solucion Ventas y presupuestos',
            '1.secondary_label' => 'Ver precios',
            '1.aside_link_label' => 'Ver Centro de mando',
            '1.showcase_badge_note' => 'Captura, presupuestos y seguimiento en un mismo flujo conectado',
            '2.title' => 'Pensado para pipelines mas claros y presupuestos mas rapidos',
            '2.story_cards.0.title' => 'Manten cada oportunidad visible',
            '2.story_cards.0.body' => self::html('Dale al equipo una vista compartida de solicitudes entrantes, cambios de estado, siguientes acciones y movimiento del pipeline para que menos oportunidades se enfrien.'),
            '2.story_cards.1.title' => 'Presupuesta con mas coherencia',
            '2.story_cards.1.body' => self::html('Reutiliza el contexto del cliente, los servicios, las opciones y la logica comercial para enviar propuestas mas limpias sin rehacer el mismo trabajo cada vez.'),
            '2.story_cards.2.title' => 'Da seguimiento sin perder impulso',
            '2.story_cards.2.body' => self::html('Manten recordatorios, mensajes y traspasos ligados a la misma ficha de cliente para que el siguiente paso siga siendo evidente hasta la aprobacion del trabajo.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function reservationsSpanish(): array
    {
        return [
            '0.kicker' => 'Un recorrido de reservas desde la disponibilidad hasta el seguimiento',
            '0.title' => 'Convierte la reserva en un recorrido completo del cliente',
            '0.body' => self::html('Reservations conecta la eleccion del horario, la confirmacion, la llegada, la gestion de filas y el seguimiento posterior para que la experiencia siga siendo clara desde la primera reserva hasta la siguiente visita.'),
            '0.primary_label' => 'Ver la solucion Reservas y filas',
            '0.feature_tabs.0.label' => 'Ofrecer',
            '0.feature_tabs.0.title' => 'Haz que la disponibilidad sea mas facil de entender y reservar',
            '0.feature_tabs.0.body' => self::html('Convierte la disponibilidad en tiempo real en un punto de entrada mas claro para que el cliente pueda elegir el horario correcto sin friccion.'),
            '0.feature_tabs.0.cta_label' => 'Ver la solucion',
            '0.feature_tabs.1.label' => 'Confirmar',
            '0.feature_tabs.1.title' => 'Estabiliza la visita antes de que llegue el cliente',
            '0.feature_tabs.1.body' => self::html('Manten recordatorios, resumen y preparacion visibles antes de la cita para que menos visitas queden en la incertidumbre.'),
            '0.feature_tabs.1.cta_label' => 'Ver Marketing y fidelizacion',
            '0.feature_tabs.2.label' => 'Recibir',
            '0.feature_tabs.2.title' => 'Absorbe llegadas y filas con mas fluidez en el lugar',
            '0.feature_tabs.2.body' => self::html('Manten recepcion, gestion de filas y paso al servicio conectados para que el flujo en el lugar se sienta mas controlado.'),
            '0.feature_tabs.2.cta_label' => 'Ver Centro de mando',
            '0.feature_tabs.3.label' => 'Dar seguimiento',
            '0.feature_tabs.3.title' => 'Extiende la relacion despues de la visita',
            '0.feature_tabs.3.body' => self::html('Manten la reserva conectada con resenas, recordatorios, ofertas y la siguiente cita para que la visita no termine solo en la confirmacion.'),
            '0.feature_tabs.3.cta_label' => 'Ver la solucion de marketing',
            '1.kicker' => 'Listo para hacer mas fluida la visita',
            '1.title' => 'Ofrece una reserva practica sin perder el control operativo',
            '1.body' => self::html('Reemplaza agenda, confirmaciones y atencion de llegada desconectadas por un mismo flujo que ayuda a los clientes a reservar con mas facilidad y al equipo a mantenerse alineado antes, durante y despues de la visita.'),
            '1.primary_label' => 'Ver la solucion Reservas y filas',
            '1.secondary_label' => 'Ver precios',
            '1.aside_link_label' => 'Ver Marketing y fidelizacion',
            '1.showcase_badge_note' => 'Disponibilidad, confirmacion y recepcion en un mismo flujo conectado',
            '2.kicker' => 'Momentos claros a lo largo de la visita',
            '2.title' => 'Pensado para que reservar, llegar y dar seguimiento sea mas fluido',
            '2.body' => self::html('Manten visibles los momentos clave antes, durante y despues de la visita como parte de una misma experiencia para que el cliente se sienta guiado y el equipo siga en control.'),
            '2.primary_label' => 'Ver la solucion Reservas y filas',
            '2.story_cards.0.title' => 'Una eleccion mas simple al reservar',
            '2.story_cards.0.body' => self::html('Ayuda al cliente a entender como elegir el momento correcto sin friccion ni dudas.'),
            '2.story_cards.1.title' => 'Una llegada mas fluida en el lugar',
            '2.story_cards.1.body' => self::html('Dale a la recepcion, a la fila y al paso al servicio un lugar mas claro dentro de la experiencia operativa.'),
            '2.story_cards.2.title' => 'Un seguimiento real despues de la visita',
            '2.story_cards.2.body' => self::html('Manten la visita conectada con el siguiente mensaje, el siguiente recordatorio o la siguiente cita en lugar de cerrar el recorrido demasiado pronto.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function operationsSpanish(): array
    {
        return [
            '0.kicker' => 'Un flujo operativo del plan a la prueba',
            '0.title' => 'Planifica, asigna, ejecuta y cierra el trabajo desde una misma vista operativa',
            '0.body' => self::html('Operations mantiene alineados la carga de trabajo, el despacho, los detalles del trabajo, la ejecucion en campo y la prueba de finalizacion para que la oficina y el terreno no trabajen con versiones distintas de la realidad.'),
            '0.primary_label' => 'Ver la solucion Servicios de campo',
            '0.feature_tabs.0.label' => 'Planificar',
            '0.feature_tabs.0.title' => 'Lee la carga de trabajo y las prioridades antes de que empiece el dia',
            '0.feature_tabs.0.body' => self::html('Dale a los planificadores una vision mas clara de la capacidad, la urgencia y la presion de agenda antes de comprometer recursos.'),
            '0.feature_tabs.0.cta_label' => 'Ver la solucion',
            '0.feature_tabs.1.label' => 'Despachar',
            '0.feature_tabs.1.title' => 'Dale al equipo correcto el contexto correcto antes de salir',
            '0.feature_tabs.1.body' => self::html('Manten asignaciones, preparacion y detalles del trabajo visibles en el mismo momento de coordinacion para mejorar la calidad del traspaso.'),
            '0.feature_tabs.1.cta_label' => 'Ver Centro de mando',
            '0.feature_tabs.2.label' => 'Ejecutar',
            '0.feature_tabs.2.title' => 'Ayuda al equipo de campo a trabajar con una lectura mas clara del trabajo',
            '0.feature_tabs.2.body' => self::html('Haz que estados, contexto del cliente, listas de control y pruebas esperadas sean mas faciles de seguir una vez que el equipo esta en el lugar.'),
            '0.feature_tabs.2.cta_label' => 'Ver servicios de campo',
            '0.feature_tabs.3.label' => 'Cerrar',
            '0.feature_tabs.3.title' => 'Cierra el ciclo con una finalizacion mas limpia y un mejor seguimiento',
            '0.feature_tabs.3.body' => self::html('Manten validacion, prueba de trabajo, lectura de ingresos y siguientes pasos conectados para que el trabajo termine de forma controlada y no apresurada.'),
            '0.feature_tabs.3.cta_label' => 'Ver Comercio',
            '1.kicker' => 'Listo para estructurar la ejecucion',
            '1.title' => 'Da a cada equipo la misma fuente de verdad operativa',
            '1.body' => self::html('Reemplaza una planificacion fragmentada, un despacho por canales paralelos y un seguimiento de campo desconectado por un mismo espacio que ayuda a planificadores, coordinadores y equipos de campo a mantenerse alineados desde la asignacion hasta la finalizacion.'),
            '1.primary_label' => 'Ver la solucion Servicios de campo',
            '1.secondary_label' => 'Ver precios',
            '1.aside_link_label' => 'Ver Centro de mando',
            '1.showcase_badge_note' => 'Planificacion, despacho y prueba de campo en un mismo ritmo conectado',
            '2.kicker' => 'Momentos operativos claros',
            '2.title' => 'Pensado para una ejecucion mas limpia en el terreno',
            '2.body' => self::html('Manten planificacion, traspaso y finalizacion visibles como momentos distintos para que los equipos se preparen mejor, ejecuten con mas contexto y cierren el trabajo con menos faltantes.'),
            '2.primary_label' => 'Ver la solucion Servicios de campo',
            '2.story_cards.0.title' => 'Una lectura mas clara de la carga antes del compromiso',
            '2.story_cards.0.body' => self::html('Dale a la oficina una mejor vista de la carga y de los puntos de tension antes de bloquear recursos para el dia.'),
            '2.story_cards.1.title' => 'Un verdadero momento de despacho antes de salir',
            '2.story_cards.1.body' => self::html('Haz visibles los detalles que importan antes de la salida para que el equipo se vaya con mejor contexto y menos sorpresas.'),
            '2.story_cards.2.title' => 'La prueba sigue conectada al mismo flujo',
            '2.story_cards.2.body' => self::html('Manten notas, listas de control, fotos y pruebas de finalizacion ligadas al mismo trabajo para que el cierre sea mas limpio y facil de revisar.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function commerceSpanish(): array
    {
        return [
            '0.kicker' => 'Un recorrido comercial del catalogo al cobro',
            '0.title' => 'Convierte tu catalogo en ingresos sin fragmentar la experiencia',
            '0.body' => self::html('Commerce conecta la visibilidad de la oferta, el pedido guiado, la facturacion y el cobro para que la venta siga siendo coherente desde el primer clic hasta el ingreso cobrado.'),
            '0.primary_label' => 'Ver la solucion Comercio y catalogo',
            '0.feature_tabs.0.label' => 'Catalogo visible',
            '0.feature_tabs.0.title' => 'Haz que la oferta sea mas facil de explorar y de entender',
            '0.feature_tabs.0.body' => self::html('Presenta productos, servicios y categorias en una estructura mas clara para que el cliente entienda que esta disponible antes de empezar el pedido.'),
            '0.feature_tabs.0.cta_label' => 'Ver la solucion de comercio',
            '0.feature_tabs.1.label' => 'Pedido guiado',
            '0.feature_tabs.1.title' => 'Manten el pedido legible desde la seleccion hasta el resumen',
            '0.feature_tabs.1.body' => self::html('Ayuda al cliente y al equipo a avanzar por el carrito, las cantidades y las elecciones de producto sin romper el flujo comercial.'),
            '0.feature_tabs.1.cta_label' => 'Ver la tienda',
            '0.feature_tabs.2.label' => 'Factura sin friccion',
            '0.feature_tabs.2.title' => 'Deja que la facturacion retome el contexto correcto en lugar de empezar de cero',
            '0.feature_tabs.2.body' => self::html('Manten la logica comercial, las lineas utiles y la validacion interna ligadas al mismo hilo para que la factura parezca la continuacion natural de la venta.'),
            '0.feature_tabs.2.cta_label' => 'Ver Centro de mando',
            '0.feature_tabs.3.label' => 'Cobro protegido',
            '0.feature_tabs.3.title' => 'Manten el pago y la visibilidad de ingresos ligados a la transaccion',
            '0.feature_tabs.3.body' => self::html('Conecta cobro, recordatorios y seguimiento de ingresos con la venta original para que facturacion y pago no se separen en flujos distintos.'),
            '0.feature_tabs.3.cta_label' => 'Ver Comercio',
            '1.kicker' => 'Listo para monetizar',
            '1.title' => 'Vende, factura y cobra desde una misma plataforma',
            '1.body' => self::html('Reemplaza tienda, administracion y recorridos de pago desconectados por un sistema que mantiene el trayecto comercial mas facil de gestionar, mas confiable y mas legible desde el catalogo hasta el pago cobrado.'),
            '1.primary_label' => 'Ver precios',
            '1.secondary_label' => 'Ver la solucion Comercio y catalogo',
            '1.aside_link_label' => 'Ver Centro de mando',
            '1.showcase_badge_note' => 'Catalogo, pedido, factura y pago en un mismo flujo conectado',
            '2.kicker' => 'Continuidad comercial',
            '2.title' => 'Pensado para negocios que quieren una cadena comercial mas limpia',
            '2.body' => self::html('Manten la venta conectada desde el primer clic hasta el pago cobrado para que catalogo, pedido, factura e ingresos se sientan como un solo sistema comercial y no como herramientas separadas.'),
            '2.primary_label' => 'Ver la solucion Comercio y catalogo',
            '2.story_cards.0.title' => 'El catalogo vuelve a ser una puerta comercial clara',
            '2.story_cards.0.body' => self::html('Estructura la oferta para que el cliente entienda mas rapido que puede comprar, reservar o agregar antes de que la transaccion comience.'),
            '2.story_cards.1.title' => 'La logistica sigue conectada con la venta',
            '2.story_cards.1.body' => self::html('Manten stock, preparacion y cumplimiento visibles dentro de la misma historia para que el equipo no gestione los ingresos aparte de la entrega.'),
            '2.story_cards.2.title' => 'Los ingresos se sienten como la continuacion natural del pedido',
            '2.story_cards.2.body' => self::html('Deja que la facturacion y el cobro cierren el ciclo para que el pago no parezca desconectado de la compra original.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function marketingLoyaltySpanish(): array
    {
        return [
            '0.kicker' => 'Un flujo de retencion desde la senal del cliente hasta el retorno de ingresos',
            '0.title' => 'Convierte la actividad del cliente en acciones de retencion que realmente lo hagan volver',
            '0.body' => self::html('Marketing & Loyalty conecta senales, segmentacion, campanas y recorridos de fidelizacion para ayudar a los equipos a actuar en el momento correcto y proteger los ingresos futuros.'),
            '0.primary_label' => 'Ver la solucion Marketing y fidelizacion',
            '0.feature_tabs.0.label' => 'Escuchar',
            '0.feature_tabs.0.title' => 'Haz visibles las senales del cliente que merecen una accion',
            '0.feature_tabs.0.body' => self::html('Apoyate en resenas, historial de visitas, inactividad y cambios de comportamiento para saber cuando el cliente debe volver a saber de ti.'),
            '0.feature_tabs.0.cta_label' => 'Ver Ventas y CRM',
            '0.feature_tabs.1.label' => 'Segmentar',
            '0.feature_tabs.1.title' => 'Construye segmentos a partir del comportamiento real y no de suposiciones',
            '0.feature_tabs.1.body' => self::html('Agrupa clientes segun valor, ritmo, historial o actividad reciente para que la segmentacion sea precisa antes incluso de lanzar una campana.'),
            '0.feature_tabs.1.cta_label' => 'Ver Centro de mando',
            '0.feature_tabs.2.label' => 'Activar',
            '0.feature_tabs.2.title' => 'Lanza campanas que lleguen en el momento adecuado y con el mensaje correcto',
            '0.feature_tabs.2.body' => self::html('Conecta la audiencia correcta, el mensaje correcto y la oferta correcta para que la campana parezca un seguimiento util y no ruido generico.'),
            '0.feature_tabs.2.cta_label' => 'Ver la solucion',
            '0.feature_tabs.3.label' => 'Fidelizar',
            '0.feature_tabs.3.title' => 'Convierte la fidelizacion en la siguiente visita, pedido o renovacion',
            '0.feature_tabs.3.body' => self::html('Manten reactivacion, beneficios y la siguiente transaccion dentro de la misma historia para que la retencion se note en el negocio repetido y no solo en las aperturas.'),
            '0.feature_tabs.3.cta_label' => 'Ver Comercio',
            '1.kicker' => 'Listo para hacer volver a los clientes con mas regularidad',
            '1.title' => 'Convierte la actividad del cliente en campanas y fidelizacion que generan ingresos recurrentes',
            '1.body' => self::html('Reemplaza herramientas de mailing desconectadas y segmentacion al azar por un sistema donde senales, audiencia, campanas y resultados de fidelizacion siguen ligados a la ficha del cliente.'),
            '1.primary_label' => 'Ver la solucion Marketing y fidelizacion',
            '1.secondary_label' => 'Ver precios',
            '1.aside_link_label' => 'Ver Centro de mando',
            '1.showcase_badge_note' => 'Senales, campanas, fidelizacion y retorno de ingresos en un mismo flujo conectado',
            '2.kicker' => 'Retencion conectada con la actividad real',
            '2.title' => 'Pensado para equipos que quieren un marketing de clientes util, oportuno y medible',
            '2.body' => self::html('Manten las campanas ligadas al recorrido real del cliente para que el seguimiento sea mas relevante, la fidelizacion se sienta mas natural y los ingresos recurrentes sean mas faciles de entender.'),
            '2.primary_label' => 'Ver la solucion Marketing y fidelizacion',
            '2.story_cards.0.title' => 'Las senales se vuelven mas faciles de aprovechar',
            '2.story_cards.0.body' => self::html('Dale al equipo una forma mas clara de ver resenas, lapsos de ausencia, cambios de comportamiento y retornos que merecen la siguiente accion.'),
            '2.story_cards.1.title' => 'Las campanas parten de un contexto real',
            '2.story_cards.1.body' => self::html('Lanza campanas a partir del historial, del valor y de la actividad del cliente para que el mensaje se sienta conectado con lo que realmente paso.'),
            '2.story_cards.2.title' => 'La fidelizacion se convierte en ingresos recurrentes visibles',
            '2.story_cards.2.body' => self::html('Manten el vinculo entre las acciones de retencion y la siguiente visita, el siguiente pedido o la siguiente mejora lo bastante claro como para medir que hace volver a la gente.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function aiAutomationSpanish(): array
    {
        return [
            '0.kicker' => 'Un recorrido de IA desde la senal hasta la ejecucion asistida',
            '0.title' => 'Pon la IA donde los equipos ya necesitan ayuda, velocidad y contexto',
            '0.body' => self::html('AI & Automation conecta deteccion de patrones, sugerencias, automatizacion del trabajo y revision humana para que los equipos avancen mas rapido sin perder visibilidad ni criterio.'),
            '0.primary_label' => 'Ver Centro de mando',
            '0.feature_tabs.0.label' => 'Detectar',
            '0.feature_tabs.0.title' => 'Haz visibles las senales y repeticiones que merecen atencion',
            '0.feature_tabs.0.body' => self::html('Ayuda a los equipos a notar antes patrones, senales debiles y fricciones recurrentes para que la siguiente accion quede mas clara antes de perder tiempo.'),
            '0.feature_tabs.0.cta_label' => 'Ver Operaciones',
            '0.feature_tabs.1.label' => 'Sugerir',
            '0.feature_tabs.1.title' => 'Sugiere borradores y acciones utiles sin perder el contexto de origen',
            '0.feature_tabs.1.body' => self::html('Manten resumenes, borradores y recomendaciones ligados al cliente, al trabajo, a la solicitud o al expediente del que parten para que la ayuda siga siendo creible.'),
            '0.feature_tabs.1.cta_label' => 'Ver Ventas y CRM',
            '0.feature_tabs.2.label' => 'Automatizar',
            '0.feature_tabs.2.title' => 'Quita pasos utiles del trabajo repetitivo sin romper el flujo',
            '0.feature_tabs.2.body' => self::html('Automatiza enrutamiento, seguimiento, preparacion y transiciones repetitivas donde el equipo gana velocidad, coherencia y menos carga manual.'),
            '0.feature_tabs.2.cta_label' => 'Ver la plataforma',
            '0.feature_tabs.3.label' => 'Mantener control',
            '0.feature_tabs.3.title' => 'Deja la revision humana donde el criterio todavia importa',
            '0.feature_tabs.3.body' => self::html('Manten aprobaciones, excepciones y decisiones sensibles visibles para que la automatizacion ayude al equipo en lugar de tomar silenciosamente el paso equivocado.'),
            '0.feature_tabs.3.cta_label' => 'Ver Centro de mando',
            '1.kicker' => 'Listo para ahorrar tiempo sin perder el control',
            '1.title' => 'Usa IA y automatizacion para hacer avanzar el trabajo con menos friccion',
            '1.body' => self::html('Reemplaza asistentes desconectados y promesas vagas de automatizacion por un sistema donde sugerencias, resumenes, pasos del flujo y revision humana siguen conectados con el trabajo mismo.'),
            '1.primary_label' => 'Ver Centro de mando',
            '1.secondary_label' => 'Ver precios',
            '1.aside_link_label' => 'Ver Operaciones',
            '1.showcase_badge_note' => 'Sugerencias, automatizacion y revision humana en un mismo flujo conectado',
            '2.kicker' => 'Una IA conectada al trabajo real',
            '2.title' => 'Pensado para equipos que quieren una ayuda util, creible y controlable',
            '2.body' => self::html('Manten la IA ligada al contexto correcto, a los momentos adecuados de revision y a los flujos correctos para que el ahorro de tiempo sea real sin convertir decisiones en aproximaciones.'),
            '2.primary_label' => 'Ver Centro de mando',
            '2.story_cards.0.title' => 'Los patrones utiles se vuelven mas faciles de detectar',
            '2.story_cards.0.body' => self::html('Ayuda a los equipos a ver las senales repetidas, los bloqueos y los patrones debiles que merecen una accion antes de que se pierdan en el ruido diario.'),
            '2.story_cards.1.title' => 'Las sugerencias siguen ancladas en el contexto',
            '2.story_cards.1.body' => self::html('Genera borradores, resumenes y acciones propuestas a partir del expediente que el equipo ya tiene abierto para que el resultado se sienta relevante y no generico.'),
            '2.story_cards.2.title' => 'La revision humana sigue visible donde importa',
            '2.story_cards.2.body' => self::html('Deja aprobaciones, excepciones y pasos sensibles bien visibles para que el equipo sepa exactamente donde ayuda la automatizacion y donde el criterio sigue mandando.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function commandCenterSpanish(): array
    {
        return [
            '0.kicker' => 'Un recorrido de direccion desde la senal hasta la decision',
            '0.title' => 'Convierte la visibilidad transversal en prioridades mas claras y accion mas rapida',
            '0.body' => self::html('Command Center conecta senales, comparaciones, definicion de prioridades y seguimiento ejecutivo para ayudar a los equipos a actuar desde una lectura compartida y no desde vistas fragmentadas.'),
            '0.primary_label' => 'Ver la solucion Supervision multiempresa',
            '0.feature_tabs.0.label' => 'Detectar',
            '0.feature_tabs.0.title' => 'Haz visibles mas rapido las senales que importan',
            '0.feature_tabs.0.body' => self::html('Ayuda a liderazgo a ver indicadores, cambios y alertas que merecen atencion antes de que se pierdan en el ruido operativo.'),
            '0.feature_tabs.0.cta_label' => 'Ver Operaciones',
            '0.feature_tabs.1.label' => 'Comparar',
            '0.feature_tabs.1.title' => 'Compara equipos, entidades y rendimiento sin perder la vision compartida',
            '0.feature_tabs.1.body' => self::html('Lee diferencias, puntos de tension y rendimientos desiguales en un solo lugar para que la comparacion lleve a la comprension y no a la fragmentacion.'),
            '0.feature_tabs.1.cta_label' => 'Ver la solucion',
            '0.feature_tabs.2.label' => 'Priorizar',
            '0.feature_tabs.2.title' => 'Convierte la lectura en prioridades que la gente realmente pueda seguir',
            '0.feature_tabs.2.body' => self::html('Traduce lo que ve liderazgo en direccion mas clara para los equipos adecuados para que el foco sea compartido y no solo implicito.'),
            '0.feature_tabs.2.cta_label' => 'Ver Ventas y CRM',
            '0.feature_tabs.3.label' => 'Arbitrar',
            '0.feature_tabs.3.title' => 'Cierra el ciclo con una decision que haga avanzar la ejecucion',
            '0.feature_tabs.3.body' => self::html('Manten compensaciones, decisiones y siguientes movimientos visibles para que la direccion ejecutiva no se quede en el insight y llegue hasta donde hay que actuar.'),
            '0.feature_tabs.3.cta_label' => 'Ver Comercio',
            '1.kicker' => 'Listo para dirigir con mas claridad',
            '1.title' => 'Usa una misma capa de mando para alinear senales, prioridades y siguientes acciones en toda la actividad',
            '1.body' => self::html('Reemplaza dashboards desconectados y actualizaciones dispersas por un espacio de mando compartido donde ingresos, operaciones y actividad del cliente puedan leerse, priorizarse y convertirse en accion.'),
            '1.primary_label' => 'Ver la solucion Supervision multiempresa',
            '1.secondary_label' => 'Ver precios',
            '1.aside_link_label' => 'Ver Operaciones',
            '1.showcase_badge_note' => 'Senales, prioridades y decisiones en una misma capa de mando',
            '2.kicker' => 'Visibilidad directiva que desemboca en accion',
            '2.title' => 'Pensado para equipos que necesitan ver antes, comparar mejor y dirigir la accion con mas claridad',
            '2.body' => self::html('Manten las senales transversales lo bastante legibles para que liderazgo pueda actuar sobre ellas, comparar entidades o equipos con mas confianza y devolver prioridades mas claras a la ejecucion.'),
            '2.primary_label' => 'Ver la solucion Supervision multiempresa',
            '2.story_cards.0.title' => 'Las senales correctas suben mas rapido',
            '2.story_cards.0.body' => self::html('Haz visibles los indicadores y alertas que merecen atencion para que direccion se concentre antes en lo que realmente cambia el rendimiento.'),
            '2.story_cards.1.title' => 'Las comparaciones siguen siendo utiles en lugar de ruidosas',
            '2.story_cards.1.body' => self::html('Manten las diferencias entre equipos, entidades y periodos dentro de una vista legible para que la comparacion ayude a decidir y no multiplique la confusion.'),
            '2.story_cards.2.title' => 'Las decisiones se traducen con mas facilidad en accion',
            '2.story_cards.2.body' => self::html('Deja que el siguiente movimiento baje hacia los equipos correctos con suficiente claridad para que las prioridades realmente puedan ejecutarse.'),
        ];
    }

    private static function html(string $value): string
    {
        return '<p>'.trim($value).'</p>';
    }
}
